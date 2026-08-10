<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Repairs a very small set of legacy catalog rows whose imported URL points to
 * an obsolete/shared host instead of the current canonical event website.
 *
 * This is intentionally exact-title + expected-host scoped. It does not relax
 * the source checker's SSRF/private-network rules and will not overwrite an
 * administrator-provided URL on another host.
 */
class Sektorel_Event_Source_Canonical_Repair {

    const VERSION = '1333';

    public static function init() {
        // Run after the bare-domain normalizer (priority 1) and before queue
        // preparation/checker callbacks (default priority 10).
        add_action( 'wp_ajax_sektorel_prepare_html_event_scan', array( __CLASS__, 'repair_active_sources' ), 2 );
        add_action( 'wp_ajax_sektorel_event_source_prepare_checks', array( __CLASS__, 'repair_active_sources' ), 2 );
        add_action( 'admin_post_sektorel_check_event_source', array( __CLASS__, 'repair_single_from_request' ), 2 );
    }

    public static function repair_active_sources() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array( 'key' => 'source_status', 'value' => 'active' ),
            ),
        ) );

        foreach ( $ids as $source_id ) {
            self::repair_source( absint( $source_id ) );
        }
    }

    public static function repair_single_from_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
        if ( $source_id ) {
            self::repair_source( $source_id );
        }
    }

    private static function repair_source( $source_id ) {
        if ( ! $source_id || 'event_source' !== get_post_type( $source_id ) ) {
            return;
        }

        $title = self::normalize_title( get_the_title( $source_id ) );
        $url   = trim( (string) get_post_meta( $source_id, 'source_url', true ) );
        $host  = self::host( $url );

        $target = '';
        $reason = '';

        if ( 'ankiros demir celik ve metalurji fuari' === $title && self::is_ankiros_host( $host ) ) {
            $target = 'https://www.ankiros.com/';
            $reason = 'ankiros_official_www';
        }

        if ( 'uluslararasi dokum kongresi' === $title && self::is_ankiros_host( $host ) ) {
            $target = 'https://76wfc.com/';
            $reason = 'world_foundry_congress_official';
        }

        if ( ! $target || self::same_url( $url, $target ) ) {
            if ( $target ) {
                update_post_meta( $source_id, 'source_canonical_repair_version', self::VERSION );
            }
            return;
        }

        $sanitized = esc_url_raw( $target, array( 'http', 'https' ) );
        if ( ! $sanitized ) {
            return;
        }

        update_post_meta( $source_id, 'source_canonical_previous_url', sanitize_text_field( $url ) );
        update_post_meta( $source_id, 'source_url', $sanitized );
        update_post_meta( $source_id, 'source_canonical_repair_version', self::VERSION );
        update_post_meta( $source_id, 'source_canonical_repair_reason', sanitize_key( $reason ) );
        update_post_meta( $source_id, 'source_canonical_repaired_at', current_time( 'mysql' ) );

        // Health/parser metadata belongs to the previous URL. Force the next
        // source check to classify the canonical target from scratch.
        delete_post_meta( $source_id, 'check_state' );
        delete_post_meta( $source_id, 'detected_parser' );
        delete_post_meta( $source_id, 'last_http_status' );
        delete_post_meta( $source_id, 'last_content_type' );
        delete_post_meta( $source_id, 'last_final_url' );
        delete_post_meta( $source_id, 'last_result' );
        delete_post_meta( $source_id, 'last_error' );
        delete_post_meta( $source_id, 'last_candidate_scan_at' );
        delete_post_meta( $source_id, 'last_candidate_count' );
    }

    private static function is_ankiros_host( $host ) {
        return in_array( $host, array( 'ankiros.com', 'www.ankiros.com' ), true );
    }

    private static function host( $url ) {
        $host = strtolower( (string) wp_parse_url( trim( (string) $url ), PHP_URL_HOST ) );
        return preg_replace( '/\.$/', '', $host );
    }

    private static function same_url( $a, $b ) {
        return untrailingslashit( strtolower( trim( (string) $a ) ) ) === untrailingslashit( strtolower( trim( (string) $b ) ) );
    }

    private static function normalize_title( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = strtolower( remove_accents( wp_strip_all_tags( $value ) ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }
}
