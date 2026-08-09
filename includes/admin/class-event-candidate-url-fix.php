<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Candidate_URL_Fix {

    const NONCE_ACTION = 'sektorel_event_candidate_jsonld';
    const REPAIR_OPTION = 'sektorel_candidate_url_fix_1261';

    public static function init() {
        add_action( 'added_post_meta', array( __CLASS__, 'normalize_candidate_meta' ), 10, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'normalize_candidate_meta' ), 10, 4 );
        add_action( 'admin_post_sektorel_import_event_candidate', array( __CLASS__, 'handle_import_candidate' ), 1 );
        add_action( 'save_post_event', array( __CLASS__, 'normalize_imported_event' ), 50, 2 );
        add_action( 'admin_init', array( __CLASS__, 'repair_existing_imports' ) );
    }

    public static function normalize_candidate_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        if ( ! in_array( $meta_key, array( 'event_url', 'registration_link' ), true ) ) {
            return;
        }

        $source_url = (string) get_post_meta( $object_id, 'source_url', true );
        $event_url  = (string) get_post_meta( $object_id, 'event_url', true );
        $base_url   = 'registration_link' === $meta_key ? ( self::is_absolute_http_url( $event_url ) ? $event_url : $source_url ) : $source_url;
        $absolute   = self::absolute_url( (string) $meta_value, $base_url );

        if ( $absolute && $absolute !== (string) $meta_value ) {
            update_post_meta( $object_id, $meta_key, $absolute );
        }
    }

    public static function handle_import_candidate() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        $candidate_id = isset( $_GET['candidate_id'] ) ? absint( $_GET['candidate_id'] ) : 0;
        check_admin_referer( self::NONCE_ACTION . '_import_' . $candidate_id );

        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            wp_die( 'Geçersiz aday.' );
        }

        self::normalize_candidate_urls( $candidate_id );

        $existing = (int) get_post_meta( $candidate_id, 'imported_event_id', true );
        if ( $existing && 'event' === get_post_type( $existing ) ) {
            self::sync_candidate_urls_to_event( $candidate_id, $existing );
            wp_safe_redirect( get_edit_post_link( $existing, 'url' ) );
            exit;
        }

        $event_id = wp_insert_post( array(
            'post_type'    => 'event',
            'post_status'  => 'draft',
            'post_title'   => get_the_title( $candidate_id ),
            'post_content' => get_post_field( 'post_content', $candidate_id ),
        ), true );

        if ( is_wp_error( $event_id ) ) {
            wp_die( esc_html( $event_id->get_error_message() ) );
        }

        $keys = array( 'start_date', 'end_date', 'location_type', 'venue', 'address', 'organizer' );
        foreach ( $keys as $key ) {
            update_post_meta( $event_id, $key, get_post_meta( $candidate_id, $key, true ) );
        }

        update_post_meta( $event_id, 'event_type', 'konferans' );
        update_post_meta( $event_id, 'source_candidate_id', $candidate_id );
        self::sync_candidate_urls_to_event( $candidate_id, $event_id );

        update_post_meta( $candidate_id, 'candidate_status', 'imported' );
        update_post_meta( $candidate_id, 'imported_event_id', $event_id );

        wp_safe_redirect( get_edit_post_link( $event_id, 'url' ) );
        exit;
    }

    public static function normalize_imported_event( $post_id, $post ) {
        if ( ! $post || 'event' !== $post->post_type ) {
            return;
        }

        $candidate_id = (int) get_post_meta( $post_id, 'source_candidate_id', true );
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return;
        }

        self::normalize_candidate_urls( $candidate_id );
        self::sync_candidate_urls_to_event( $candidate_id, $post_id );
    }

    public static function repair_existing_imports() {
        if ( get_option( self::REPAIR_OPTION ) ) {
            return;
        }

        $candidate_ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => 'imported_event_id',
                    'compare' => 'EXISTS',
                ),
            ),
        ) );

        foreach ( $candidate_ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            $event_id     = (int) get_post_meta( $candidate_id, 'imported_event_id', true );

            self::normalize_candidate_urls( $candidate_id );

            if ( $event_id && 'event' === get_post_type( $event_id ) ) {
                self::sync_candidate_urls_to_event( $candidate_id, $event_id );
            }
        }

        update_option( self::REPAIR_OPTION, current_time( 'mysql' ), false );
    }

    private static function normalize_candidate_urls( $candidate_id ) {
        $source_url = (string) get_post_meta( $candidate_id, 'source_url', true );
        $event_url  = (string) get_post_meta( $candidate_id, 'event_url', true );

        $absolute_event_url = self::absolute_url( $event_url, $source_url );
        if ( ! $absolute_event_url ) {
            $absolute_event_url = self::absolute_url( $source_url, $source_url );
        }
        if ( $absolute_event_url ) {
            update_post_meta( $candidate_id, 'event_url', $absolute_event_url );
        }

        $registration = (string) get_post_meta( $candidate_id, 'registration_link', true );
        $base_url     = $absolute_event_url ? $absolute_event_url : $source_url;
        $absolute_registration = self::absolute_url( $registration, $base_url );
        if ( $absolute_registration ) {
            update_post_meta( $candidate_id, 'registration_link', $absolute_registration );
        }
    }

    private static function sync_candidate_urls_to_event( $candidate_id, $event_id ) {
        $source_url   = (string) get_post_meta( $candidate_id, 'source_url', true );
        $event_url    = self::absolute_url( (string) get_post_meta( $candidate_id, 'event_url', true ), $source_url );
        $base_url     = $event_url ? $event_url : $source_url;
        $registration = self::absolute_url( (string) get_post_meta( $candidate_id, 'registration_link', true ), $base_url );

        if ( $registration ) {
            update_post_meta( $event_id, 'registration_link', $registration );
            update_post_meta( $candidate_id, 'registration_link', $registration );
        }

        if ( $event_url ) {
            update_post_meta( $event_id, 'source_url', $event_url );
            update_post_meta( $candidate_id, 'event_url', $event_url );
        } elseif ( $source_url ) {
            update_post_meta( $event_id, 'source_url', esc_url_raw( $source_url, array( 'http', 'https' ) ) );
        }
    }

    private static function absolute_url( $url, $base_url ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $base_url = trim( (string) $base_url );

        if ( ! $url ) {
            return '';
        }

        if ( self::is_absolute_http_url( $url ) ) {
            return esc_url_raw( $url, array( 'http', 'https' ) );
        }

        if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
            return '';
        }

        $base = wp_parse_url( $base_url );
        if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
            return '';
        }

        $scheme = strtolower( (string) $base['scheme'] );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return '';
        }

        $origin = $scheme . '://' . $base['host'];
        if ( ! empty( $base['port'] ) ) {
            $origin .= ':' . absint( $base['port'] );
        }

        if ( 0 === strpos( $url, '//' ) ) {
            return esc_url_raw( $scheme . ':' . $url, array( 'http', 'https' ) );
        }

        if ( 0 === strpos( $url, '/' ) ) {
            return esc_url_raw( $origin . self::normalize_relative_path( $url ), array( 'http', 'https' ) );
        }

        $base_path = isset( $base['path'] ) && $base['path'] ? (string) $base['path'] : '/';

        if ( 0 === strpos( $url, '?' ) ) {
            return esc_url_raw( $origin . $base_path . $url, array( 'http', 'https' ) );
        }

        if ( 0 === strpos( $url, '#' ) ) {
            $query = isset( $base['query'] ) && $base['query'] ? '?' . $base['query'] : '';
            return esc_url_raw( $origin . $base_path . $query . $url, array( 'http', 'https' ) );
        }

        $directory = '/' === substr( $base_path, -1 ) ? $base_path : trailingslashit( dirname( $base_path ) );
        return esc_url_raw( $origin . self::normalize_relative_path( $directory . $url ), array( 'http', 'https' ) );
    }

    private static function normalize_relative_path( $path ) {
        $fragment = '';
        $query    = '';

        $hash_pos = strpos( $path, '#' );
        if ( false !== $hash_pos ) {
            $fragment = substr( $path, $hash_pos );
            $path = substr( $path, 0, $hash_pos );
        }

        $query_pos = strpos( $path, '?' );
        if ( false !== $query_pos ) {
            $query = substr( $path, $query_pos );
            $path = substr( $path, 0, $query_pos );
        }

        $segments = array();
        foreach ( explode( '/', $path ) as $segment ) {
            if ( '' === $segment || '.' === $segment ) {
                continue;
            }
            if ( '..' === $segment ) {
                array_pop( $segments );
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode( '/', $segments ) . $query . $fragment;
    }

    private static function is_absolute_http_url( $url ) {
        return (bool) preg_match( '#^https?://#i', trim( (string) $url ) );
    }
}
