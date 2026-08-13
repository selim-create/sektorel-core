<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Runs the existing deterministic candidate matcher inside Source Center.
 *
 * This stage does not create Events. It classifies only discovery/canonical
 * candidates after source parsing has finished. Strong no-change matches are
 * resolved to the existing Event so source evidence is captured immediately;
 * changed/incomplete/new candidates remain in review.
 */
class Sektorel_Event_Candidate_Background_Matcher {

    const NONCE_ACTION = 'sektorel_candidate_background_matcher';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 20;

    public static function init() {
        add_action( 'wp_ajax_sektorel_candidate_match_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_candidate_match_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();

        if ( ! class_exists( 'Sektorel_Event_Candidate_Matcher' ) || ! class_exists( 'Sektorel_Event_Source_Role' ) ) {
            wp_send_json_error( array( 'message' => 'Candidate matcher veya source-role katmanı kullanılamıyor.' ) );
        }

        $ids   = self::eligible_candidate_ids();
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array( 'ids' => array_values( $ids ) ),
            self::QUEUE_TTL
        );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $ids ),
        ) );
    }

    public static function ajax_batch() {
        self::require_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Candidate matcher kuyruk anahtarı eksik.' ) );
        }

        $key   = self::queue_key( get_current_user_id(), $token );
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || ! isset( $queue['ids'] ) || ! is_array( $queue['ids'] ) ) {
            wp_send_json_error( array( 'message' => 'Candidate matcher kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $ids   = array_values( array_map( 'absint', $queue['ids'] ) );
        $batch = array_slice( $ids, $offset, self::BATCH_SIZE );

        $existing = 0;
        $changed = 0;
        $incomplete = 0;
        $new = 0;
        $skipped = 0;
        $error = 0;
        $resolved = 0;
        $messages = array();

        foreach ( $batch as $candidate_id ) {
            if ( ! self::candidate_is_eligible( $candidate_id ) ) {
                $skipped++;
                continue;
            }

            $result = self::classify_with_existing_engine( $candidate_id );
            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Hata: ' . get_the_title( $candidate_id ) . ' — ' . $result->get_error_message();
                continue;
            }

            $status = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
            $event_id = absint( get_post_meta( $candidate_id, 'matched_event_id', true ) );

            if ( 'existing' === $status && $event_id && 'event' === get_post_type( $event_id ) && 'trash' !== get_post_status( $event_id ) ) {
                update_post_meta( $candidate_id, 'candidate_status', 'imported' );
                update_post_meta( $candidate_id, 'candidate_resolution', 'background_existing_match' );
                update_post_meta( $candidate_id, 'imported_event_id', $event_id );
                update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
                $existing++;
                $resolved++;
                $messages[] = 'Mevcut Event’e bağlandı: ' . get_the_title( $candidate_id );
                continue;
            }

            if ( 'changed' === $status ) {
                $changed++;
            } elseif ( 'incomplete' === $status ) {
                $incomplete++;
            } elseif ( 'new' === $status ) {
                $new++;
            } elseif ( 'existing' === $status ) {
                $existing++;
            } else {
                $skipped++;
            }
        }

        $next_offset = min( count( $ids ), $offset + count( $batch ) );
        $done        = $next_offset >= count( $ids );
        if ( $done ) {
            delete_transient( $key );
        } else {
            set_transient( $key, $queue, self::QUEUE_TTL );
        }

        wp_send_json_success( array(
            'next_offset' => $next_offset,
            'done'        => $done,
            'existing'    => $existing,
            'changed'     => $changed,
            'incomplete'  => $incomplete,
            'new'         => $new,
            'resolved'    => $resolved,
            'skipped'     => $skipped,
            'error'       => $error,
            // Source Center knows the generic counters; expose resolved strong
            // matches as unchanged and leave review states visible in messages.
            'unchanged'   => $existing,
            'updated'     => 0,
            'created'     => 0,
            'messages'    => $messages,
        ) );
    }

    private static function classify_with_existing_engine( $candidate_id ) {
        $gateway = Closure::bind(
            static function ( $id ) {
                $revision  = absint( get_option( Sektorel_Event_Candidate_Matcher::EVENT_REVISION_OPTION, 1 ) );
                $signature = Sektorel_Event_Candidate_Matcher::candidate_signature( $id, $revision );
                Sektorel_Event_Candidate_Matcher::event_index();
                Sektorel_Event_Candidate_Matcher::classify_candidate( $id, $signature );
                return true;
            },
            null,
            'Sektorel_Event_Candidate_Matcher'
        );

        if ( ! $gateway ) {
            return new WP_Error( 'candidate_matcher_gateway_unavailable', 'Mevcut matcher motoruna erişilemedi.' );
        }

        try {
            return $gateway( absint( $candidate_id ) );
        } catch ( Throwable $e ) {
            return new WP_Error( 'candidate_matcher_runtime_error', $e->getMessage() );
        }
    }

    private static function eligible_candidate_ids() {
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );

        if ( ! $ids ) {
            return array();
        }

        update_meta_cache( 'post', $ids );
        $eligible = array();
        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            if ( self::candidate_is_eligible( $candidate_id ) ) {
                $eligible[] = $candidate_id;
            }
        }

        return $eligible;
    }

    private static function candidate_is_eligible( $candidate_id ) {
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) || 'trash' === get_post_status( $candidate_id ) ) {
            return false;
        }

        $status = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
        if ( in_array( $status, array( 'imported', 'ignored', 'rejected' ), true ) ) {
            return false;
        }

        $role = Sektorel_Event_Source_Role::role_for_candidate( $candidate_id );
        if ( ! in_array( $role, array( 'discovery', 'canonical_registry' ), true ) ) {
            return false;
        }

        $title = trim( (string) get_the_title( $candidate_id ) );
        $start = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
        if ( '' === $title || '' === $start ) {
            return false;
        }

        return true;
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_candidate_match_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
