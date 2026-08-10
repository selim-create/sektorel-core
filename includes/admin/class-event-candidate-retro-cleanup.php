<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * One-time retroactive cleanup for the legacy HTML candidate pool.
 *
 * Re-applies only already-established conservative quality signals to records
 * that are still unresolved. Processed/matched/imported/ignored records are
 * never changed and no candidate is deleted.
 */
class Sektorel_Event_Candidate_Retro_Cleanup {

    const ENGINE_VERSION = '1340';
    const OPTION_KEY     = 'sektorel_candidate_retro_cleanup_1340';
    const BATCH_SIZE     = 500;
    const STALE_DAYS     = 45;

    private static $lock = false;

    public static function init() {
        add_action( 'load-edit.php', array( __CLASS__, 'maybe_run_cleanup' ), 34 );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ), 34 );
    }

    public static function maybe_run_cleanup() {
        global $typenow;

        if ( self::$lock || 'event_candidate' !== $typenow || ! current_user_can( 'manage_options' ) || self::is_filtered_request() ) {
            return;
        }

        if ( get_option( self::OPTION_KEY ) ) {
            return;
        }

        self::$lock = true;

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
                array( 'key' => 'candidate_status', 'value' => array( 'new', 'incomplete' ), 'compare' => 'IN' ),
            ),
        ) );

        $report = array(
            'version'               => self::ENGINE_VERSION,
            'checked'               => 0,
            'ignored'               => 0,
            'stale_start_date'      => 0,
            'low_confidence'        => 0,
            'listing_contamination' => 0,
            'kept'                  => 0,
            'completed_at'          => current_time( 'mysql' ),
        );

        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            if ( ! $candidate_id ) {
                continue;
            }

            $report['checked']++;
            $reason = self::cleanup_reason( $candidate_id );

            update_post_meta( $candidate_id, 'candidate_retro_cleanup_version', self::ENGINE_VERSION );
            update_post_meta( $candidate_id, 'candidate_retro_cleanup_checked_at', current_time( 'mysql' ) );

            if ( ! $reason ) {
                $report['kept']++;
                continue;
            }

            $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
            if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
                $report['kept']++;
                continue;
            }

            update_post_meta( $candidate_id, 'candidate_retro_cleanup_previous_status', $status );
            update_post_meta( $candidate_id, 'candidate_retro_cleanup_reason', sanitize_key( $reason ) );
            update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
            update_post_meta( $candidate_id, 'candidate_resolution', 'retro_cleanup_false_positive' );
            update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );

            $report['ignored']++;
            if ( isset( $report[ $reason ] ) ) {
                $report[ $reason ]++;
            }
        }

        update_option( self::OPTION_KEY, $report, false );
        set_transient( self::notice_key(), $report, 10 * MINUTE_IN_SECONDS );

        self::$lock = false;
    }

    private static function cleanup_reason( $candidate_id ) {
        if ( self::is_stale_start_date( get_post_meta( $candidate_id, 'start_date', true ) ) ) {
            return 'stale_start_date';
        }

        $confidence = (string) get_post_meta( $candidate_id, 'candidate_confidence_level', true );
        $score      = get_post_meta( $candidate_id, 'candidate_confidence_score', true );
        if ( 'low' === $confidence || ( '' !== (string) $score && absint( $score ) < 30 ) ) {
            return 'low_confidence';
        }

        $listing_reason = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_listing_guard_reason', true ) );
        if ( in_array( $listing_reason, array(
            'event_url_year_mismatch',
            'webrazzi_non_event_detail',
            'webrazzi_detail_year_mismatch',
            'listing_container_collision',
        ), true ) ) {
            return 'listing_contamination';
        }

        return '';
    }

    private static function is_stale_start_date( $value ) {
        if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})/', trim( (string) $value ), $matches ) ) {
            return false;
        }

        try {
            $start = new DateTime( $matches[1], wp_timezone() );
            $start->setTime( 0, 0, 0 );

            $cutoff = new DateTime( 'now', wp_timezone() );
            $cutoff->modify( '-' . self::STALE_DAYS . ' days' );
            $cutoff->setTime( 0, 0, 0 );

            return $start < $cutoff;
        } catch ( Exception $e ) {
            return false;
        }
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

        $message = sprintf(
            'Retroaktif aday temizliği tamamlandı. Kontrol edilen: %1$d; yok sayılan: %2$d; korunan: %3$d. Nedenler — eski tarih: %4$d, düşük güven: %5$d, listing contamination: %6$d.',
            absint( $report['checked'] ),
            absint( $report['ignored'] ),
            absint( $report['kept'] ),
            absint( $report['stale_start_date'] ),
            absint( $report['low_confidence'] ),
            absint( $report['listing_contamination'] )
        );

        echo '<div class="notice notice-success is-dismissible"><p><strong>Candidate Cleanup 1.34.0:</strong> ' . esc_html( $message ) . '</p></div>';
    }

    private static function notice_key() {
        return 'sektorel_candidate_retro_cleanup_notice_' . absint( get_current_user_id() );
    }
}
