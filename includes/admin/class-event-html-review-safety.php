<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_HTML_Review_Safety {

    const ENGINE_VERSION = '1350';
    const OPTION_KEY = 'sektorel_html_review_nav_cleanup_1350';
    const NONCE_ACTION = 'sektorel_export_unresolved_html_candidates';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'replace_export_handler' ), 120 );
        add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'reject_navigation_insert' ), 110, 2 );
        add_action( 'load-edit.php', array( __CLASS__, 'cleanup_existing_navigation' ), 38 );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ), 38 );
    }

    public static function replace_export_handler() {
        remove_action(
            'admin_post_sektorel_export_unresolved_html_candidates',
            array( 'Sektorel_Event_HTML_Unresolved_Review', 'export_csv' )
        );
        add_action(
            'admin_post_sektorel_export_unresolved_html_candidates',
            array( __CLASS__, 'export_csv' )
        );
    }

    public static function reject_navigation_insert( $maybe_empty, $postarr ) {
        if ( ! is_admin() || ! wp_doing_ajax() ) {
            return $maybe_empty;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( 'sektorel_html_event_scan_batch' !== $action ) {
            return $maybe_empty;
        }

        $post_type = isset( $postarr['post_type'] ) ? (string) $postarr['post_type'] : '';
        if ( 'event_candidate' !== $post_type ) {
            return $maybe_empty;
        }

        $title = isset( $postarr['post_title'] ) ? (string) $postarr['post_title'] : '';
        return self::is_navigation_title( $title ) ? true : $maybe_empty;
    }

    public static function cleanup_existing_navigation() {
        global $typenow;

        if ( 'event_candidate' !== $typenow || ! current_user_can( 'manage_options' ) || self::is_filtered_request() ) {
            return;
        }

        if ( get_option( self::OPTION_KEY ) ) {
            return;
        }

        $ids = self::unresolved_ids();
        $ignored = 0;

        foreach ( $ids as $candidate_id ) {
            if ( ! self::is_navigation_title( get_the_title( $candidate_id ) ) ) {
                continue;
            }

            $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
            if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
                continue;
            }

            update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
            update_post_meta( $candidate_id, 'candidate_resolution', 'navigation_false_positive' );
            update_post_meta( $candidate_id, 'candidate_quality_reason', 'navigation_false_positive' );
            update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
            update_post_meta( $candidate_id, 'candidate_review_safety_version', self::ENGINE_VERSION );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );
            $ignored++;
        }

        $report = array(
            'checked' => count( $ids ),
            'ignored' => $ignored,
        );
        update_option( self::OPTION_KEY, $report, false );
        set_transient( self::notice_key(), $report, 10 * MINUTE_IN_SECONDS );
    }

    public static function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        check_admin_referer( self::NONCE_ACTION );

        $ids = self::unresolved_ids();
        $filename = 'unresolved-html-candidates-' . gmdate( 'Ymd-His' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        if ( false === $out ) {
            wp_die( 'CSV çıktısı oluşturulamadı.' );
        }

        fwrite( $out, "\xEF\xBB\xBF" );
        self::csv_row( $out, array(
            'candidate_id',
            'title',
            'start_date',
            'end_date',
            'source_id',
            'source_title',
            'candidate_status',
            'confidence_level',
            'confidence_score',
            'match_score',
            'match_reason',
            'quality_reason',
            'venue',
            'address',
            'organizer',
            'location_type',
            'event_url',
            'registration_link',
            'source_url',
        ) );

        foreach ( $ids as $candidate_id ) {
            $source_id = absint( get_post_meta( $candidate_id, 'source_id', true ) );
            self::csv_row( $out, array(
                $candidate_id,
                get_the_title( $candidate_id ),
                (string) get_post_meta( $candidate_id, 'start_date', true ),
                (string) get_post_meta( $candidate_id, 'end_date', true ),
                $source_id,
                $source_id ? get_the_title( $source_id ) : '',
                (string) get_post_meta( $candidate_id, 'candidate_status', true ),
                (string) get_post_meta( $candidate_id, 'candidate_confidence_level', true ),
                (string) get_post_meta( $candidate_id, 'candidate_confidence_score', true ),
                (string) get_post_meta( $candidate_id, 'candidate_match_score', true ),
                (string) get_post_meta( $candidate_id, 'candidate_match_reason', true ),
                (string) get_post_meta( $candidate_id, 'candidate_quality_reason', true ),
                (string) get_post_meta( $candidate_id, 'venue', true ),
                (string) get_post_meta( $candidate_id, 'address', true ),
                (string) get_post_meta( $candidate_id, 'organizer', true ),
                (string) get_post_meta( $candidate_id, 'location_type', true ),
                (string) get_post_meta( $candidate_id, 'event_url', true ),
                (string) get_post_meta( $candidate_id, 'registration_link', true ),
                (string) get_post_meta( $candidate_id, 'source_url', true ),
            ) );
        }

        fclose( $out );
        exit;
    }

    private static function csv_row( $handle, $fields ) {
        fputcsv( $handle, $fields, ';', '"', '\\' );
    }

    private static function is_navigation_title( $title ) {
        $key = self::normalize_key( $title );
        return in_array(
            $key,
            array(
                'fuar takvimi',
                'bize ulasin',
                'ziyaretcilerimiz hosgeldiniz',
            ),
            true
        );
    }

    private static function unresolved_ids() {
        return array_values( array_map( 'absint', get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'parser_type', 'value' => 'html' ),
                array( 'key' => 'candidate_status', 'value' => array( 'new', 'incomplete' ), 'compare' => 'IN' ),
            ),
        ) ) ) );
    }

    private static function normalize_key( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = strtolower( remove_accents( $value ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
    }

    private static function is_filtered_request() {
        foreach ( array( 'candidate_confidence', 'candidate_match_status', 'candidate_parser', 'candidate_quality', 's', 'm' ) as $key ) {
            if ( isset( $_GET[ $key ] ) && '' !== trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) ) {
                return true;
            }
        }
        return false;
    }

    public static function render_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'event_candidate' !== $screen->post_type || 'edit' !== $screen->base ) {
            return;
        }

        $report = get_transient( self::notice_key() );
        if ( ! is_array( $report ) ) {
            return;
        }

        delete_transient( self::notice_key() );
        echo '<div class="notice notice-success is-dismissible"><p><strong>HTML Review Safety 1.35.0:</strong> ' . esc_html( sprintf(
            'Kontrol edilen: %1$d; navigation false-positive olarak yok sayılan: %2$d.',
            absint( $report['checked'] ),
            absint( $report['ignored'] )
        ) ) . '</p></div>';
    }

    private static function notice_key() {
        return 'sektorel_html_review_safety_notice_' . absint( get_current_user_id() );
    }
}
