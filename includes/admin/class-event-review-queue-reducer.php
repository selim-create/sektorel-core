<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reduces review-queue noise without introducing a second classifier.
 * Reuses the existing HTML Review Triage engine and only archives candidates
 * that remain unresolved, unmatched, unimported discovery records and are
 * deterministically re-classified as `noise`.
 */
class Sektorel_Event_Review_Queue_Reducer {

    const NONCE_ACTION   = 'sektorel_review_queue_reduce';
    const QUEUE_TTL      = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE     = 20;
    const ENGINE_VERSION = '1440';

    public static function init() {
        add_action( 'wp_ajax_sektorel_review_queue_reduce_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_review_queue_reduce_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();

        if ( ! class_exists( 'Sektorel_Event_HTML_Review_Triage' ) || ! class_exists( 'Sektorel_Event_Source_Role' ) ) {
            wp_send_json_error( array( 'message' => 'Review triage veya source-role katmanı kullanılamıyor.' ) );
        }

        $ids   = self::refresh_triage_and_noise_ids();
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
            wp_send_json_error( array( 'message' => 'Review temizleme kuyruk anahtarı eksik.' ) );
        }

        $key   = self::queue_key( get_current_user_id(), $token );
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || ! isset( $queue['ids'] ) || ! is_array( $queue['ids'] ) ) {
            wp_send_json_error( array( 'message' => 'Review temizleme kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $ids      = array_values( array_map( 'absint', $queue['ids'] ) );
        $batch    = array_slice( $ids, $offset, self::BATCH_SIZE );
        $ignored  = 0;
        $skipped  = 0;
        $messages = array();

        foreach ( $batch as $candidate_id ) {
            $guard = self::noise_guard( $candidate_id );
            if ( is_wp_error( $guard ) ) {
                $skipped++;
                $messages[] = 'Atlandı: ' . get_the_title( $candidate_id ) . ' — ' . $guard->get_error_message();
                continue;
            }

            $triage = Sektorel_Event_HTML_Review_Triage::classify( $candidate_id );
            self::store_triage( $candidate_id, $triage );
            if ( 'noise' !== sanitize_key( (string) $triage['level'] ) ) {
                $skipped++;
                $messages[] = 'Atlandı: ' . get_the_title( $candidate_id ) . ' — triage artık gürültü demiyor.';
                continue;
            }

            update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
            update_post_meta( $candidate_id, 'candidate_resolution', 'deterministic_triage_noise' );
            update_post_meta( $candidate_id, 'candidate_quality_reason', 'deterministic_triage_noise' );
            update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
            update_post_meta( $candidate_id, 'candidate_review_reducer_version', self::ENGINE_VERSION );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );

            $ignored++;
            $messages[] = 'Gürültü olarak arşivlendi: ' . get_the_title( $candidate_id );
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
            'created'     => 0,
            'updated'     => 0,
            'unchanged'   => 0,
            'skipped'     => $skipped,
            'error'       => 0,
            'ignored'     => $ignored,
            'messages'    => $messages,
        ) );
    }

    private static function refresh_triage_and_noise_ids() {
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'parser_type', 'value' => 'html' ),
                array( 'key' => 'candidate_status', 'value' => array( 'new', 'incomplete' ), 'compare' => 'IN' ),
            ),
        ) );

        if ( ! $ids ) {
            return array();
        }

        update_meta_cache( 'post', $ids );
        $noise = array();

        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            if ( is_wp_error( self::noise_guard( $candidate_id, false ) ) ) {
                continue;
            }

            $triage = Sektorel_Event_HTML_Review_Triage::classify( $candidate_id );
            self::store_triage( $candidate_id, $triage );
            if ( 'noise' === sanitize_key( (string) $triage['level'] ) ) {
                $noise[] = $candidate_id;
            }
        }

        return $noise;
    }

    private static function noise_guard( $candidate_id, $require_noise_meta = true ) {
        $candidate_id = absint( $candidate_id );
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) || 'trash' === get_post_status( $candidate_id ) ) {
            return new WP_Error( 'invalid_candidate', 'Geçersiz aday.' );
        }

        if ( 'html' !== (string) get_post_meta( $candidate_id, 'parser_type', true ) ) {
            return new WP_Error( 'not_html', 'Aday HTML parser kaydı değil.' );
        }

        if ( 'discovery' !== Sektorel_Event_Source_Role::role_for_candidate( $candidate_id ) ) {
            return new WP_Error( 'not_discovery', 'Yalnız discovery adayları otomatik temizlenir.' );
        }

        $status = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
        if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
            return new WP_Error( 'resolved_status', 'Aday artık açık review durumunda değil.' );
        }

        if ( absint( get_post_meta( $candidate_id, 'matched_event_id', true ) ) ) {
            return new WP_Error( 'matched_event', 'Mevcut Event eşleşmesi olan aday temizlenmez.' );
        }

        if ( absint( get_post_meta( $candidate_id, 'imported_event_id', true ) ) ) {
            return new WP_Error( 'already_imported', 'Daha önce Event’e dönüştürülmüş aday temizlenmez.' );
        }

        if ( $require_noise_meta && 'noise' !== sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_triage_level', true ) ) ) {
            return new WP_Error( 'not_noise', 'Mevcut triage sonucu gürültü değil.' );
        }

        return true;
    }

    private static function store_triage( $candidate_id, $triage ) {
        update_post_meta( $candidate_id, 'candidate_triage_level', sanitize_key( (string) $triage['level'] ) );
        update_post_meta( $candidate_id, 'candidate_triage_score', absint( $triage['score'] ) );
        update_post_meta( $candidate_id, 'candidate_triage_reasons', sanitize_text_field( implode( ', ', (array) $triage['reasons'] ) ) );
        update_post_meta( $candidate_id, 'candidate_triage_version', Sektorel_Event_HTML_Review_Triage::ENGINE_VERSION );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_review_queue_reduce_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
