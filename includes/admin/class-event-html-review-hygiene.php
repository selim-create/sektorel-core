<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conservative hygiene for unresolved HTML candidate review.
 *
 * Does not create or publish events. It only:
 * - marks impossible date ranges incomplete;
 * - consolidates highly specific fragmented multi-day event clusters where
 *   the same source/title produced 3+ fragments inside a 14-day window.
 */
class Sektorel_Event_HTML_Review_Hygiene {

    const ENGINE_VERSION   = '1350';
    const OPTION_KEY       = 'sektorel_html_review_hygiene_1350';
    const MAX_CLUSTER_DAYS = 14;

    public static function init() {
        add_action( 'load-edit.php', array( __CLASS__, 'run_once' ), 37 );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ), 37 );
    }

    public static function run_once() {
        global $typenow;

        if ( 'event_candidate' !== $typenow || ! current_user_can( 'manage_options' ) || self::is_filtered_request() ) {
            return;
        }

        if ( get_option( self::OPTION_KEY ) ) {
            return;
        }

        $ids = self::unresolved_html_ids();
        $invalid_ranges = 0;
        $clusters = 0;
        $cluster_ignored = 0;

        foreach ( $ids as $candidate_id ) {
            if ( self::mark_invalid_range( $candidate_id ) ) {
                $invalid_ranges++;
            }
        }

        foreach ( self::fragment_groups( $ids ) as $group ) {
            $result = self::consolidate_group( $group );
            if ( $result['consolidated'] ) {
                $clusters++;
                $cluster_ignored += $result['ignored'];
            }
        }

        $report = array(
            'checked'         => count( $ids ),
            'invalid_ranges'  => $invalid_ranges,
            'clusters'        => $clusters,
            'cluster_ignored' => $cluster_ignored,
            'version'         => self::ENGINE_VERSION,
        );

        update_option( self::OPTION_KEY, $report, false );
        set_transient( self::notice_key(), $report, 10 * MINUTE_IN_SECONDS );
    }

    private static function mark_invalid_range( $candidate_id ) {
        $start = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
        $end   = self::date_part( get_post_meta( $candidate_id, 'end_date', true ) );

        if ( ! $start || ! $end || $end >= $start ) {
            return false;
        }

        $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
        if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
            return false;
        }

        update_post_meta( $candidate_id, 'candidate_status', 'incomplete' );
        update_post_meta( $candidate_id, 'candidate_quality_reason', 'end_before_start' );
        update_post_meta( $candidate_id, 'candidate_review_hygiene_version', self::ENGINE_VERSION );
        delete_post_meta( $candidate_id, 'candidate_match_signature' );
        return true;
    }

    private static function fragment_groups( $ids ) {
        $groups = array();

        foreach ( $ids as $candidate_id ) {
            $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
            if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
                continue;
            }

            $title = self::normalize_key( get_the_title( $candidate_id ) );
            $start = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
            if ( ! $title || ! $start || ! self::multi_day_event_title( $title ) ) {
                continue;
            }

            $source_id = absint( get_post_meta( $candidate_id, 'source_id', true ) );
            if ( ! $source_id ) {
                continue;
            }

            $key = sha1( $source_id . '|' . $title );
            if ( ! isset( $groups[ $key ] ) ) {
                $groups[ $key ] = array();
            }
            $groups[ $key ][] = absint( $candidate_id );
        }

        return array_filter( $groups, function( $group ) {
            return count( $group ) >= 3;
        } );
    }

    private static function consolidate_group( $ids ) {
        $rows = array();
        foreach ( $ids as $candidate_id ) {
            $start = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
            $end   = self::date_part( get_post_meta( $candidate_id, 'end_date', true ) );
            if ( ! $start ) {
                continue;
            }

            $rows[] = array(
                'id'    => absint( $candidate_id ),
                'start' => $start,
                'end'   => $end ?: $start,
            );
        }

        if ( count( $rows ) < 3 ) {
            return array( 'consolidated' => false, 'ignored' => 0 );
        }

        usort( $rows, function( $a, $b ) {
            return strcmp( $a['start'], $b['start'] );
        } );

        $min_start = $rows[0]['start'];
        $max_end   = $rows[0]['end'];
        foreach ( $rows as $row ) {
            if ( $row['end'] > $max_end ) {
                $max_end = $row['end'];
            }
            if ( $row['start'] > $max_end ) {
                $max_end = $row['start'];
            }
        }

        try {
            $a = new DateTime( $min_start, wp_timezone() );
            $b = new DateTime( $max_end, wp_timezone() );
            $span = (int) $a->diff( $b )->format( '%a' );
        } catch ( Exception $e ) {
            return array( 'consolidated' => false, 'ignored' => 0 );
        }

        if ( $span < 1 || $span > self::MAX_CLUSTER_DAYS ) {
            return array( 'consolidated' => false, 'ignored' => 0 );
        }

        $keeper = $rows[0]['id'];
        update_post_meta( $keeper, 'start_date', $min_start . self::time_suffix( get_post_meta( $keeper, 'start_date', true ) ) );
        update_post_meta( $keeper, 'end_date', $max_end . 'T00:00' );
        update_post_meta( $keeper, 'candidate_review_hygiene_version', self::ENGINE_VERSION );
        update_post_meta( $keeper, 'candidate_review_hygiene_reason', 'fragmented_multi_day_event' );
        delete_post_meta( $keeper, 'candidate_match_signature' );

        $ignored = 0;
        foreach ( $rows as $row ) {
            if ( $row['id'] === $keeper ) {
                continue;
            }

            $status = (string) get_post_meta( $row['id'], 'candidate_status', true );
            if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
                continue;
            }

            update_post_meta( $row['id'], 'candidate_status', 'ignored' );
            update_post_meta( $row['id'], 'candidate_resolution', 'fragmented_multi_day_event' );
            update_post_meta( $row['id'], 'candidate_quality_reason', 'fragmented_multi_day_event' );
            update_post_meta( $row['id'], 'candidate_resolved_at', current_time( 'mysql' ) );
            update_post_meta( $row['id'], 'candidate_review_hygiene_version', self::ENGINE_VERSION );
            delete_post_meta( $row['id'], 'candidate_match_signature' );
            $ignored++;
        }

        return array( 'consolidated' => true, 'ignored' => $ignored );
    }

    private static function unresolved_html_ids() {
        return array_values( array_map( 'absint', get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
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
        ) ) ) );
    }

    private static function multi_day_event_title( $title ) {
        return (bool) preg_match( '/\b(fuar|fair|expo|exhibition|kongre|congress|conference|konferans|festival)\b/i', $title );
    }

    private static function date_part( $value ) {
        return preg_match( '/^(\d{4}-\d{2}-\d{2})/', (string) $value, $m ) ? $m[1] : '';
    }

    private static function time_suffix( $value ) {
        return preg_match( '/T(\d{2}:\d{2})/', (string) $value, $m ) ? 'T' . $m[1] : 'T00:00';
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
        echo '<div class="notice notice-success is-dismissible"><p><strong>HTML Review Hygiene 1.35.0:</strong> ' . esc_html( sprintf(
            'Kontrol edilen: %1$d; geçersiz tarih aralığı: %2$d; konsolide edilen parçalı etkinlik: %3$d; yok sayılan fragment: %4$d.',
            absint( $report['checked'] ),
            absint( $report['invalid_ranges'] ),
            absint( $report['clusters'] ),
            absint( $report['cluster_ignored'] )
        ) ) . '</p></div>';
    }

    private static function notice_key() {
        return 'sektorel_html_review_hygiene_notice_' . absint( get_current_user_id() );
    }
}
