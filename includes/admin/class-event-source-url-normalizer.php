<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normalizes legacy event-source URLs imported as bare domains and repairs a
 * tiny allowlist of known canonical source targets.
 *
 * The class never relaxes WordPress safe-request checks. wp_safe_remote_get()
 * and the source checker's SSRF/private-network rules remain authoritative.
 */
class Sektorel_Event_Source_URL_Normalizer {

    const VERSION = '1333';

    public static function init() {
        add_action( 'wp_ajax_sektorel_prepare_html_event_scan', array( __CLASS__, 'normalize_active_sources' ), 1 );
        add_action( 'wp_ajax_sektorel_event_source_prepare_checks', array( __CLASS__, 'normalize_active_sources' ), 1 );
        add_action( 'admin_post_sektorel_check_event_source', array( __CLASS__, 'normalize_single_from_request' ), 1 );
    }

    public static function normalize_active_sources() {
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
            self::normalize_source( absint( $source_id ) );
            self::repair_known_canonical_source( absint( $source_id ) );
        }
    }

    public static function normalize_single_from_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
        if ( $source_id ) {
            self::normalize_source( $source_id );
            self::repair_known_canonical_source( $source_id );
        }
    }

    private static function normalize_source( $source_id ) {
        if ( ! $source_id || 'event_source' !== get_post_type( $source_id ) ) {
            return;
        }

        $raw = trim( html_entity_decode( (string) get_post_meta( $source_id, 'source_url', true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( ! $raw || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) || 0 === strpos( $raw, '//' ) ) {
            return;
        }

        if ( preg_match( '/[\s\x00-\x1F\x7F]/u', $raw ) || false !== strpos( $raw, '@' ) ) {
            return;
        }

        $candidate = 'https://' . ltrim( $raw, '/' );
        $parts = wp_parse_url( $candidate );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return;
        }

        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        if ( ! self::looks_like_hostname( $host ) ) {
            return;
        }

        $normalized = esc_url_raw( $candidate, array( 'http', 'https' ) );
        if ( ! $normalized ) {
            return;
        }

        update_post_meta( $source_id, 'source_url', $normalized );
        update_post_meta( $source_id, 'source_url_normalized_version', self::VERSION );
        update_post_meta( $source_id, 'source_url_normalized_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'source_url_normalized_from', sanitize_text_field( $raw ) );
    }

    private static function repair_known_canonical_source( $source_id ) {
        if ( ! $source_id || 'event_source' !== get_post_type( $source_id ) ) {
            return;
        }

        $title = self::normalize_title( get_the_title( $source_id ) );
        $url   = trim( (string) get_post_meta( $source_id, 'source_url', true ) );
        $host  = self::host( $url );
        $target = '';
        $reason = '';

        // The root ankiros.com DNS result is rejected by the checker while the
        // current official fair website is served from the www hostname.
        if ( 'ankiros demir celik ve metalurji fuari' === $title && self::is_ankiros_host( $host ) ) {
            $target = 'https://www.ankiros.com/';
            $reason = 'ankiros_official_www';
        }

        // The World Foundry Congress is a separate event and should no longer
        // share the ANKIROS catalog URL.
        if ( 'uluslararasi dokum kongresi' === $title && self::is_ankiros_host( $host ) ) {
            $target = 'https://76wfc.com/';
            $reason = 'world_foundry_congress_official';
        }

        if ( ! $target || self::same_url( $url, $target ) ) {
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

        // Previous health/parser results belong to the old target.
        foreach ( array(
            'check_state', 'detected_parser', 'last_http_status', 'last_content_type',
            'last_final_url', 'last_result', 'last_error', 'last_candidate_scan_at',
            'last_candidate_count'
        ) as $key ) {
            delete_post_meta( $source_id, $key );
        }
    }

    private static function is_ankiros_host( $host ) {
        return in_array( $host, array( 'ankiros.com', 'www.ankiros.com' ), true );
    }

    private static function host( $url ) {
        return strtolower( rtrim( (string) wp_parse_url( trim( (string) $url ), PHP_URL_HOST ), '.' ) );
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

    private static function looks_like_hostname( $host ) {
        if ( ! $host || strlen( $host ) > 253 || false === strpos( $host, '.' ) ) {
            return false;
        }

        if ( 'localhost' === $host || preg_match( '/\.(?:local|localhost|internal)$/i', $host ) ) {
            return false;
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        return (bool) preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host );
    }
}
