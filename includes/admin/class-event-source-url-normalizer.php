<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Normalizes legacy event-source URLs that were imported as bare domains.
 *
 * Example: ankiros.com -> https://ankiros.com
 *
 * This class deliberately does not relax WordPress safe-request checks. It
 * only supplies an explicit HTTPS scheme for syntactically valid public-style
 * host/path values; wp_safe_remote_get() and the source checker's SSRF rules
 * remain authoritative.
 */
class Sektorel_Event_Source_URL_Normalizer {

    const VERSION = '1332';

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
        }
    }

    public static function normalize_single_from_request() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
        if ( $source_id ) {
            self::normalize_source( $source_id );
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

        // Accept only host-like values with an optional path/query/fragment.
        // No whitespace, credentials, control characters or custom schemes.
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

    private static function looks_like_hostname( $host ) {
        if ( ! $host || strlen( $host ) > 253 || false === strpos( $host, '.' ) ) {
            return false;
        }

        // Reject obvious localhost/private-address literals here. The existing
        // checker/wp_safe_remote_get protections still run afterwards as well.
        if ( 'localhost' === $host || preg_match( '/\.(?:local|localhost|internal)$/i', $host ) ) {
            return false;
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        return (bool) preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host );
    }
}
