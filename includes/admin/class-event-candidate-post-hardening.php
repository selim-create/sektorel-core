<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Post-processing hardening for generic HTML candidates.
 *
 * Keeps the legacy parser stable while fixing two field-proven edge cases:
 * - cross-month ranges such as "31 Ağustos-3 Eylül 2026"
 * - duplicate candidates created from separate source records on the same site
 */
class Sektorel_Event_Candidate_Post_Hardening {

    const ENGINE_VERSION = '1310';
    const BATCH_SIZE     = 200;

    private static $lock = false;

    public static function init() {
        add_action( 'load-edit.php', array( __CLASS__, 'harden_existing_batch' ), 29 );
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_harden_candidate' ), 95, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_harden_candidate' ), 95, 4 );
    }

    public static function harden_existing_batch() {
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
                    array( 'key' => 'candidate_post_hardening_version', 'compare' => 'NOT EXISTS' ),
                    array( 'key' => 'candidate_post_hardening_version', 'value' => self::ENGINE_VERSION, 'compare' => '!=' ),
                ),
            ),
        ) );

        foreach ( $ids as $candidate_id ) {
            self::harden_candidate( absint( $candidate_id ) );
        }
    }

    public static function maybe_harden_candidate( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$lock || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        if ( ! in_array( $meta_key, array( 'parser_type', 'start_date', 'end_date', 'event_url', 'source_url', 'candidate_status' ), true ) ) {
            return;
        }

        if ( 'html' !== (string) get_post_meta( $object_id, 'parser_type', true ) ) {
            return;
        }

        // parser_type is written at the end of HTML upsert, so this is the
        // preferred moment to inspect the completed candidate.
        if ( 'parser_type' === $meta_key || 'candidate_status' === $meta_key ) {
            self::harden_candidate( absint( $object_id ) );
        }
    }

    private static function harden_candidate( $candidate_id ) {
        if ( ! $candidate_id || self::$lock || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return;
        }

        if ( 'html' !== (string) get_post_meta( $candidate_id, 'parser_type', true ) ) {
            return;
        }

        self::$lock = true;

        self::repair_cross_month_range( $candidate_id );
        self::apply_global_duplicate_key( $candidate_id );
        update_post_meta( $candidate_id, 'candidate_post_hardening_version', self::ENGINE_VERSION );
        update_post_meta( $candidate_id, 'candidate_post_hardened_at', current_time( 'mysql' ) );

        self::$lock = false;
    }

    private static function repair_cross_month_range( $candidate_id ) {
        $text = self::clean_text(
            get_the_title( $candidate_id ) . ' ' . get_post_field( 'post_content', $candidate_id )
        );

        $range = self::parse_cross_month_range( $text );
        if ( ! $range ) {
            return;
        }

        $current_start = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
        $current_end   = trim( (string) get_post_meta( $candidate_id, 'end_date', true ) );
        $current_day   = substr( $current_start, 0, 10 );
        $start_day     = substr( $range['start'], 0, 10 );
        $end_day       = substr( $range['end'], 0, 10 );

        $changed = false;

        // The field-observed bug selected the range end as start. Correct only
        // that exact shape; do not overwrite unrelated structured dates.
        if ( $current_day && $current_day === $end_day && $start_day !== $end_day ) {
            update_post_meta( $candidate_id, 'candidate_previous_start_date', $current_start );
            update_post_meta( $candidate_id, 'start_date', $range['start'] );
            update_post_meta( $candidate_id, 'end_date', $range['end'] );
            $changed = true;
        } elseif ( $current_day === $start_day && ! $current_end ) {
            update_post_meta( $candidate_id, 'end_date', $range['end'] );
            $changed = true;
        }

        if ( $changed ) {
            update_post_meta( $candidate_id, 'candidate_date_repair', 'cross_month_range' );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );
        }
    }

    private static function parse_cross_month_range( $text ) {
        $months = self::month_map();
        $names  = array_keys( $months );
        usort( $names, function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
        $pattern = implode( '|', array_map( function( $name ) { return preg_quote( $name, '/' ); }, $names ) );

        if ( ! preg_match( '/\b(\d{1,2})\s+(' . $pattern . ')\s*[-–—]\s*(\d{1,2})\s+(' . $pattern . ')\s+(20\d{2})\b/iu', $text, $m ) ) {
            return array();
        }

        $m1 = self::month_number( $m[2] );
        $m2 = self::month_number( $m[4] );
        if ( ! $m1 || ! $m2 || $m1 === $m2 ) {
            return array();
        }

        $year  = (int) $m[5];
        $start = self::valid_date( $year, $m1, (int) $m[1] );
        $end   = self::valid_date( $year, $m2, (int) $m[3] );
        if ( ! $start || ! $end || strtotime( $end ) < strtotime( $start ) ) {
            return array();
        }

        return array( 'start' => $start . 'T00:00', 'end' => $end . 'T00:00' );
    }

    private static function apply_global_duplicate_key( $candidate_id ) {
        $key = self::global_key( $candidate_id );
        if ( ! $key ) {
            return;
        }

        update_post_meta( $candidate_id, 'candidate_global_key', $key );

        $source_id = absint( get_post_meta( $candidate_id, 'source_id', true ) );
        $matches = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'post__not_in'   => array( $candidate_id ),
            'meta_key'       => 'candidate_global_key',
            'meta_value'     => $key,
        ) );

        if ( ! $matches ) {
            return;
        }

        foreach ( $matches as $other_id ) {
            $other_id = absint( $other_id );
            if ( ! $other_id || $source_id === absint( get_post_meta( $other_id, 'source_id', true ) ) ) {
                continue;
            }

            $winner = self::better_candidate( $candidate_id, $other_id );
            $loser  = $winner === $candidate_id ? $other_id : $candidate_id;

            if ( self::can_auto_ignore_duplicate( $loser ) ) {
                update_post_meta( $loser, 'candidate_status', 'ignored' );
                update_post_meta( $loser, 'candidate_resolution', 'global_duplicate' );
                update_post_meta( $loser, 'candidate_duplicate_of', $winner );
                update_post_meta( $loser, 'candidate_resolved_at', current_time( 'mysql' ) );
                delete_post_meta( $loser, 'candidate_match_signature' );
            }

            if ( $loser === $candidate_id ) {
                break;
            }
        }
    }

    private static function global_key( $candidate_id ) {
        $title = self::normalize_title( get_the_title( $candidate_id ) );
        $start = substr( trim( (string) get_post_meta( $candidate_id, 'start_date', true ) ), 0, 10 );
        $event = trim( (string) get_post_meta( $candidate_id, 'event_url', true ) );
        $source= trim( (string) get_post_meta( $candidate_id, 'source_url', true ) );

        if ( ! $title || ! $start ) {
            return '';
        }

        $event_identity = self::url_identity( $event );
        $source_identity= self::url_identity( $source );
        $host = self::url_host( $event ?: $source );
        if ( ! $host ) {
            return '';
        }

        if ( $event_identity && ! self::is_generic_url_identity( $event_identity ) ) {
            return sha1( 'detail|' . $event_identity . '|' . $start );
        }

        return sha1( 'listing|' . $host . '|' . $title . '|' . $start . '|' . ( $source_identity ? self::url_path( $source_identity ) : '' ) );
    }

    private static function better_candidate( $left_id, $right_id ) {
        $left  = self::candidate_rank( $left_id );
        $right = self::candidate_rank( $right_id );

        if ( $left === $right ) {
            return min( $left_id, $right_id );
        }
        return $left > $right ? $left_id : $right_id;
    }

    private static function candidate_rank( $candidate_id ) {
        $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
        $status_rank = array(
            'imported'   => 1000,
            'changed'    => 900,
            'existing'   => 850,
            'new'        => 500,
            'incomplete' => 400,
            'ignored'    => 100,
        );
        $rank = isset( $status_rank[ $status ] ) ? $status_rank[ $status ] : 300;

        $confidence = (string) get_post_meta( $candidate_id, 'candidate_confidence_level', true );
        $confidence_rank = array( 'high' => 90, 'medium' => 50, 'low' => 10 );
        $rank += isset( $confidence_rank[ $confidence ] ) ? $confidence_rank[ $confidence ] : 0;
        $rank += min( 100, absint( get_post_meta( $candidate_id, 'candidate_confidence_score', true ) ) );

        return $rank;
    }

    private static function can_auto_ignore_duplicate( $candidate_id ) {
        $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
        return in_array( $status, array( 'new', 'incomplete' ), true );
    }

    private static function is_filtered_request() {
        foreach ( array( 'candidate_confidence', 'candidate_match_status', 'candidate_parser', 'candidate_quality', 's', 'm' ) as $key ) {
            if ( isset( $_GET[ $key ] ) && '' !== trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) ) {
                return true;
            }
        }
        return false;
    }

    private static function month_map() {
        return array(
            'ocak'=>1,'january'=>1,'jan'=>1,
            'subat'=>2,'şubat'=>2,'february'=>2,'feb'=>2,
            'mart'=>3,'march'=>3,'mar'=>3,
            'nisan'=>4,'april'=>4,'apr'=>4,
            'mayis'=>5,'mayıs'=>5,'may'=>5,
            'haziran'=>6,'june'=>6,'jun'=>6,
            'temmuz'=>7,'july'=>7,'jul'=>7,
            'agustos'=>8,'ağustos'=>8,'august'=>8,'aug'=>8,
            'eylul'=>9,'eylül'=>9,'september'=>9,'sep'=>9,
            'ekim'=>10,'october'=>10,'oct'=>10,
            'kasim'=>11,'kasım'=>11,'november'=>11,'nov'=>11,
            'aralik'=>12,'aralık'=>12,'december'=>12,'dec'=>12,
        );
    }

    private static function month_number( $value ) {
        $key = strtolower( remove_accents( self::clean_text( $value ) ) );
        $map = self::month_map();
        foreach ( $map as $name => $number ) {
            if ( strtolower( remove_accents( $name ) ) === $key ) {
                return $number;
            }
        }
        return 0;
    }

    private static function valid_date( $year, $month, $day ) {
        if ( ! checkdate( (int) $month, (int) $day, (int) $year ) ) {
            return '';
        }
        return sprintf( '%04d-%02d-%02d', (int) $year, (int) $month, (int) $day );
    }

    private static function normalize_title( $value ) {
        $value = strtolower( remove_accents( self::clean_text( $value ) ) );
        $value = preg_replace( '/\b20\d{2}\b/', ' ', $value );
        $value = preg_replace( '/\b\d{1,2}\s+(ocak|subat|mart|nisan|mayis|haziran|temmuz|agustos|eylul|ekim|kasim|aralik|january|february|march|april|may|june|july|august|september|october|november|december)\b/i', ' ', $value );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function url_identity( $url ) {
        $parts = wp_parse_url( trim( (string) $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }
        $host = strtolower( preg_replace( '/^www\./', '', rtrim( $parts['host'], '.' ) ) );
        $path = isset( $parts['path'] ) ? '/' . trim( $parts['path'], '/' ) : '/';
        return $host . $path;
    }

    private static function url_host( $url ) {
        $host = strtolower( (string) wp_parse_url( trim( (string) $url ), PHP_URL_HOST ) );
        return preg_replace( '/^www\./', '', rtrim( $host, '.' ) );
    }

    private static function url_path( $identity ) {
        $pos = strpos( $identity, '/' );
        return false === $pos ? '/' : substr( $identity, $pos );
    }

    private static function is_generic_url_identity( $identity ) {
        $path = self::url_path( $identity );
        return '/' === $path || (bool) preg_match( '#^/(?:tr|en)?/?(?:default|index|home)?(?:\.(?:html?|php))?/?$#i', $path );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return trim( (string) $value );
    }
}
