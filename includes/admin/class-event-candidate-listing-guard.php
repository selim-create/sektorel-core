<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Guards generic HTML listing candidates against parent-container/date leakage.
 *
 * A listing parser can accidentally pair a heading from one card with the first
 * date/link found in an ancestor container. This class validates the completed
 * candidate without changing the legacy parser itself.
 */
class Sektorel_Event_Candidate_Listing_Guard {

    const ENGINE_VERSION = '1320';
    const BATCH_SIZE     = 250;

    private static $lock = false;

    public static function init() {
        add_action( 'load-edit.php', array( __CLASS__, 'validate_existing_batch' ), 31 );
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_validate_candidate' ), 98, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_validate_candidate' ), 98, 4 );
    }

    public static function validate_existing_batch() {
        global $typenow;

        if ( 'event_candidate' !== $typenow || ! current_user_can( 'manage_options' ) || self::is_filtered_request() ) {
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => self::BATCH_SIZE,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'parser_type', 'value' => 'html' ),
                array(
                    'relation' => 'OR',
                    array( 'key' => 'candidate_listing_guard_version', 'compare' => 'NOT EXISTS' ),
                    array( 'key' => 'candidate_listing_guard_version', 'value' => self::ENGINE_VERSION, 'compare' => '!=' ),
                ),
            ),
        ) );

        foreach ( $ids as $candidate_id ) {
            self::validate_candidate( absint( $candidate_id ) );
        }
    }

    public static function maybe_validate_candidate( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$lock || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        if ( ! in_array( $meta_key, array( 'parser_type', 'candidate_status' ), true ) ) {
            return;
        }

        if ( 'html' !== (string) get_post_meta( $object_id, 'parser_type', true ) ) {
            return;
        }

        self::validate_candidate( absint( $object_id ) );
    }

    private static function validate_candidate( $candidate_id ) {
        if ( ! $candidate_id || self::$lock || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return;
        }

        if ( 'html' !== (string) get_post_meta( $candidate_id, 'parser_type', true ) ) {
            return;
        }

        self::$lock = true;

        $status     = (string) get_post_meta( $candidate_id, 'candidate_status', true );
        $source_id  = absint( get_post_meta( $candidate_id, 'source_id', true ) );
        $source_url = $source_id ? trim( (string) get_post_meta( $source_id, 'source_url', true ) ) : '';
        if ( ! $source_url ) {
            $source_url = trim( (string) get_post_meta( $candidate_id, 'source_url', true ) );
        }
        $event_url  = trim( (string) get_post_meta( $candidate_id, 'event_url', true ) );
        $start_date = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );

        $reason = '';

        // Generic invariant: when a detail URL explicitly contains a year,
        // it must agree with the candidate start year. A 2025 detail carrying
        // a 2026 date is strong evidence of ancestor-container date leakage.
        $url_year   = self::year_from_url_path( $event_url );
        $start_year = self::year_from_date( $start_date );
        if ( $url_year && $start_year && $url_year !== $start_year ) {
            $reason = 'event_url_year_mismatch';
        }

        // Webrazzi's verified listing has a stable event-detail contract:
        // /etkinlik/{year}/{slug}/. The public listing also contains a long
        // historical archive, so arbitrary links discovered inside ancestor
        // event containers must not become event candidates.
        if ( ! $reason && self::is_webrazzi_event_listing( $source_url ) ) {
            if ( ! self::is_webrazzi_event_detail( $event_url ) ) {
                $reason = 'webrazzi_non_event_detail';
            } else {
                $detail_year = self::year_from_url_path( $event_url );
                if ( $detail_year && $start_year && $detail_year !== $start_year ) {
                    $reason = 'webrazzi_detail_year_mismatch';
                }
            }
        }

        update_post_meta( $candidate_id, 'candidate_listing_guard_version', self::ENGINE_VERSION );
        update_post_meta( $candidate_id, 'candidate_listing_guard_checked_at', current_time( 'mysql' ) );

        if ( $reason ) {
            update_post_meta( $candidate_id, 'candidate_listing_guard_reason', sanitize_key( $reason ) );

            // Never rewrite a record that an administrator already processed or
            // a matcher identified as an existing/changed event. Only unresolved
            // parser output may be auto-suppressed.
            if ( in_array( $status, array( 'new', 'incomplete' ), true ) ) {
                update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
                update_post_meta( $candidate_id, 'candidate_resolution', 'listing_contamination' );
                update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
                delete_post_meta( $candidate_id, 'candidate_match_signature' );
            }
        } else {
            delete_post_meta( $candidate_id, 'candidate_listing_guard_reason' );
        }

        self::$lock = false;
    }

    private static function is_webrazzi_event_listing( $url ) {
        $parts = wp_parse_url( trim( (string) $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return false;
        }

        $host = strtolower( preg_replace( '/^www\./', '', rtrim( (string) $parts['host'], '.' ) ) );
        if ( 'webrazzi.com' !== $host ) {
            return false;
        }

        $path = isset( $parts['path'] ) ? '/' . trim( (string) $parts['path'], '/' ) . '/' : '/';
        return '/etkinlik/' === $path;
    }

    private static function is_webrazzi_event_detail( $url ) {
        $parts = wp_parse_url( trim( (string) $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return false;
        }

        $host = strtolower( preg_replace( '/^www\./', '', rtrim( (string) $parts['host'], '.' ) ) );
        if ( 'webrazzi.com' !== $host ) {
            return false;
        }

        $path = isset( $parts['path'] ) ? '/' . trim( rawurldecode( (string) $parts['path'] ), '/' ) . '/' : '/';
        return (bool) preg_match( '#^/etkinlik/20\d{2}/[^/]+/$#i', $path );
    }

    private static function year_from_url_path( $url ) {
        $path = (string) wp_parse_url( trim( (string) $url ), PHP_URL_PATH );
        if ( preg_match( '#(?:^|/)(20\d{2})(?:/|$)#', $path, $matches ) ) {
            return (int) $matches[1];
        }
        return 0;
    }

    private static function year_from_date( $value ) {
        if ( preg_match( '/^(20\d{2})-\d{2}-\d{2}/', (string) $value, $matches ) ) {
            return (int) $matches[1];
        }
        return 0;
    }

    private static function is_filtered_request() {
        foreach ( array( 'candidate_confidence', 'candidate_match_status', 'candidate_parser', 'candidate_quality', 's', 'm' ) as $key ) {
            if ( ! isset( $_GET[ $key ] ) ) {
                continue;
            }
            $value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
            if ( '' !== trim( (string) $value ) && '0' !== trim( (string) $value ) ) {
                return true;
            }
        }
        return false;
    }
}
