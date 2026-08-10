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

    const ENGINE_VERSION = '1321';
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

        // Run after individual validation so already-invalid records cannot win
        // a same-container collision group.
        self::resolve_collision_groups( $ids );
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
        self::resolve_candidate_collision( absint( $object_id ) );
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
            $current_reason = (string) get_post_meta( $candidate_id, 'candidate_listing_guard_reason', true );
            if ( 'listing_container_collision' !== $current_reason ) {
                delete_post_meta( $candidate_id, 'candidate_listing_guard_reason' );
            }
        }

        self::$lock = false;
    }

    /**
     * Resolve groups where one source produced three or more different titles
     * for the exact same detail URL and start day. This is a conservative
     * signature of an ancestor listing container leaking one link/date into
     * multiple headings.
     */
    private static function resolve_collision_groups( $ids ) {
        if ( ! is_array( $ids ) || ! $ids ) {
            return;
        }

        $groups = array();

        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            $data = self::collision_data( $candidate_id );
            if ( ! $data ) {
                continue;
            }

            $key = $data['source_id'] . '|' . $data['event_identity'] . '|' . $data['start_day'];
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array();
            }
            $groups[ $key ][ $candidate_id ] = $data;
        }

        foreach ( $groups as $group ) {
            self::resolve_collision_group( $group );
        }
    }

    private static function resolve_candidate_collision( $candidate_id ) {
        $data = self::collision_data( $candidate_id );
        if ( ! $data ) {
            return;
        }

        $matches = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'parser_type', 'value' => 'html' ),
                array( 'key' => 'source_id', 'value' => $data['source_id'] ),
                array( 'key' => 'event_url', 'value' => $data['event_url'] ),
            ),
        ) );

        $group = array();
        foreach ( $matches as $match_id ) {
            $match_id = absint( $match_id );
            $match_data = self::collision_data( $match_id );
            if ( $match_data && $match_data['start_day'] === $data['start_day'] ) {
                $group[ $match_id ] = $match_data;
            }
        }

        self::resolve_collision_group( $group );
    }

    private static function resolve_collision_group( $group ) {
        if ( ! is_array( $group ) || count( $group ) < 3 ) {
            return;
        }

        $titles = array();
        foreach ( $group as $data ) {
            $titles[ $data['title_key'] ] = true;
        }
        if ( count( $titles ) < 3 ) {
            return;
        }

        $winner_id = 0;
        $winner_score = -1;
        foreach ( $group as $candidate_id => $data ) {
            $score = self::collision_rank( $candidate_id, $data );
            if ( $score > $winner_score || ( $score === $winner_score && ( ! $winner_id || $candidate_id < $winner_id ) ) ) {
                $winner_id = absint( $candidate_id );
                $winner_score = $score;
            }
        }

        if ( ! $winner_id ) {
            return;
        }

        self::$lock = true;
        foreach ( $group as $candidate_id => $data ) {
            $candidate_id = absint( $candidate_id );
            if ( $candidate_id === $winner_id ) {
                update_post_meta( $candidate_id, 'candidate_listing_collision_winner', '1' );
                continue;
            }

            $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
            if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
                continue;
            }

            update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
            update_post_meta( $candidate_id, 'candidate_resolution', 'listing_container_collision' );
            update_post_meta( $candidate_id, 'candidate_listing_guard_reason', 'listing_container_collision' );
            update_post_meta( $candidate_id, 'candidate_duplicate_of', $winner_id );
            update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );
        }
        self::$lock = false;
    }

    private static function collision_data( $candidate_id ) {
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return array();
        }
        if ( 'html' !== (string) get_post_meta( $candidate_id, 'parser_type', true ) ) {
            return array();
        }

        $source_id = absint( get_post_meta( $candidate_id, 'source_id', true ) );
        $event_url = trim( (string) get_post_meta( $candidate_id, 'event_url', true ) );
        $source_url = trim( (string) get_post_meta( $candidate_id, 'source_url', true ) );
        $start = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
        $start_day = preg_match( '/^(20\d{2}-\d{2}-\d{2})/', $start, $m ) ? $m[1] : '';
        $identity = self::url_identity( $event_url );
        $source_identity = self::url_identity( $source_url );

        if ( ! $source_id || ! $identity || ! $start_day || $identity === $source_identity || ! self::is_detail_like_url( $event_url ) ) {
            return array();
        }

        return array(
            'source_id'      => $source_id,
            'event_url'      => $event_url,
            'event_identity' => $identity,
            'start_day'      => $start_day,
            'title_key'      => self::normalize_key( get_the_title( $candidate_id ) ),
            'slug_key'       => self::url_slug_key( $event_url ),
        );
    }

    private static function collision_rank( $candidate_id, $data ) {
        $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
        $protected = in_array( $status, array( 'imported', 'existing', 'changed' ), true );
        $ignored = 'ignored' === $status;

        $score = $protected ? 10000 : ( $ignored ? 0 : 1000 );
        $title = isset( $data['title_key'] ) ? $data['title_key'] : '';
        $slug  = isset( $data['slug_key'] ) ? $data['slug_key'] : '';

        if ( $title && $slug ) {
            if ( $title === $slug ) {
                $score += 1000;
            } elseif ( false !== strpos( $title, $slug ) || false !== strpos( $slug, $title ) ) {
                $score += 500;
            }

            $percent = 0.0;
            similar_text( $title, $slug, $percent );
            $score += (int) round( $percent );
        }

        $score += min( 100, absint( get_post_meta( $candidate_id, 'candidate_confidence_score', true ) ) );
        return $score;
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

    private static function is_detail_like_url( $url ) {
        $path = (string) wp_parse_url( trim( (string) $url ), PHP_URL_PATH );
        $path = '/' . trim( rawurldecode( $path ), '/' );
        if ( '/' === $path ) {
            return false;
        }
        if ( preg_match( '#^/(?:tr|en)?/?(?:event|events|etkinlik|etkinlikler|takvim|calendar|agenda)/?$#i', $path ) ) {
            return false;
        }
        return true;
    }

    private static function url_identity( $url ) {
        $parts = wp_parse_url( trim( (string) $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }
        $host = strtolower( preg_replace( '/^www\./', '', rtrim( (string) $parts['host'], '.' ) ) );
        $path = isset( $parts['path'] ) ? '/' . trim( rawurldecode( (string) $parts['path'] ), '/' ) : '/';
        return $host . $path;
    }

    private static function url_slug_key( $url ) {
        $path = trim( (string) wp_parse_url( trim( (string) $url ), PHP_URL_PATH ), '/' );
        if ( ! $path ) {
            return '';
        }
        $parts = explode( '/', rawurldecode( $path ) );
        $slug = end( $parts );
        return self::normalize_key( str_replace( array( '-', '_' ), ' ', (string) $slug ) );
    }

    private static function normalize_key( $value ) {
        $value = strtolower( remove_accents( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        $value = preg_replace( '/\b20\d{2}\b/', ' ', $value );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
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
