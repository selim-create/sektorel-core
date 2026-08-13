<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Converts safe canonical-registry candidates to draft events inside the
 * Source Center background pipeline.
 *
 * This stage deliberately excludes discovery and enrichment sources. It reuses
 * the existing candidate conversion engine, so source-role guards, candidate
 * dedupe, cross-source evidence, TOBB taxonomy hooks and draft-only publishing
 * semantics remain the source of truth.
 */
class Sektorel_Event_Canonical_Draft_Stage {

    const NONCE_ACTION = 'sektorel_canonical_candidate_drafts';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 12;

    public static function init() {
        add_action( 'wp_ajax_sektorel_canonical_drafts_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_canonical_drafts_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();

        if ( ! class_exists( 'Sektorel_Event_Candidate_Quality' ) ) {
            wp_send_json_error( array( 'message' => 'Canonical draft dönüşüm motoru kullanılamıyor.' ) );
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
            wp_send_json_error( array( 'message' => 'Canonical draft kuyruk anahtarı eksik.' ) );
        }

        $key   = self::queue_key( get_current_user_id(), $token );
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || ! isset( $queue['ids'] ) || ! is_array( $queue['ids'] ) ) {
            wp_send_json_error( array( 'message' => 'Canonical draft kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $ids       = array_values( array_map( 'absint', $queue['ids'] ) );
        $batch     = array_slice( $ids, $offset, self::BATCH_SIZE );
        $created   = 0;
        $unchanged = 0;
        $skipped   = 0;
        $error     = 0;
        $messages  = array();

        foreach ( $batch as $candidate_id ) {
            if ( ! self::candidate_is_eligible( $candidate_id ) ) {
                $skipped++;
                continue;
            }

            $title = get_the_title( $candidate_id );

            // Matcher already found the occurrence: attach canonical evidence
            // directly instead of creating even a temporary duplicate draft.
            $matched_event_id = absint( get_post_meta( $candidate_id, 'matched_event_id', true ) );
            if ( $matched_event_id && 'event' === get_post_type( $matched_event_id ) && 'trash' !== get_post_status( $matched_event_id ) ) {
                update_post_meta( $candidate_id, 'candidate_status', 'imported' );
                update_post_meta( $candidate_id, 'imported_event_id', $matched_event_id );
                $unchanged++;
                $messages[] = 'Mevcut etkinliğe bağlandı: ' . $title;
                continue;
            }

            $result = self::convert_with_existing_engine( $candidate_id );

            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Engellendi: ' . $title . ' — ' . $result->get_error_message();
                continue;
            }

            if ( 'existing' === $result ) {
                $unchanged++;
                continue;
            }

            $event_id = absint( $result );
            if ( $event_id && 'event' === get_post_type( $event_id ) ) {
                $final_event_id = absint( get_post_meta( $candidate_id, 'imported_event_id', true ) );
                if ( $final_event_id && 'event' === get_post_type( $final_event_id ) ) {
                    $event_id = $final_event_id;
                }
                $created++;
                $messages[] = 'Yeni taslak: ' . $title;
            } else {
                $error++;
                $messages[] = 'Hata: ' . $title . ' — geçerli event ID dönmedi.';
            }
        }

        $next_offset = min( count( $ids ), $offset + count( $batch ) );
        $done        = $next_offset >= count( $ids );

        if ( $done ) {
            delete_transient( $key );
        }

        wp_send_json_success( array(
            'next_offset' => $next_offset,
            'done'        => $done,
            'created'     => $created,
            'unchanged'   => $unchanged,
            'skipped'     => $skipped,
            'error'       => $error,
            'messages'    => $messages,
        ) );
    }

    private static function convert_with_existing_engine( $candidate_id ) {
        $gateway = Closure::bind(
            static function ( $id ) {
                return Sektorel_Event_Candidate_Quality::convert_candidate( $id );
            },
            null,
            'Sektorel_Event_Candidate_Quality'
        );

        if ( ! $gateway ) {
            return new WP_Error( 'canonical_converter_unavailable', 'Mevcut candidate dönüşüm motoruna erişilemedi.' );
        }

        return $gateway( absint( $candidate_id ) );
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
            if ( self::candidate_is_eligible( absint( $candidate_id ) ) ) {
                $eligible[] = absint( $candidate_id );
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

        $imported_event_id = absint( get_post_meta( $candidate_id, 'imported_event_id', true ) );
        if ( $imported_event_id && 'event' === get_post_type( $imported_event_id ) && 'trash' !== get_post_status( $imported_event_id ) ) {
            return false;
        }

        if ( ! class_exists( 'Sektorel_Event_Source_Role' ) || 'canonical_registry' !== Sektorel_Event_Source_Role::role_for_candidate( $candidate_id ) ) {
            return false;
        }

        $title = trim( (string) get_the_title( $candidate_id ) );
        $start = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
        if ( '' === $title || '' === $start ) {
            return false;
        }

        $end_or_start = trim( (string) get_post_meta( $candidate_id, 'end_date', true ) );
        if ( '' === $end_or_start ) {
            $end_or_start = $start;
        }
        $date = substr( $end_or_start, 0, 10 );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && $date < current_time( 'Y-m-d' ) ) {
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
        return 'sektorel_canonical_drafts_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
