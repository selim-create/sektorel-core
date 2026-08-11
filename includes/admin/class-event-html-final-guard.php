<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_HTML_Final_Guard {

    const ENGINE_VERSION = '1348';
    const OPTION_KEY     = 'sektorel_html_final_guard_cleanup_1348';
    const BATCH_SIZE     = 500;
    const STALE_DAYS     = 45;

    private static $restoring = false;

    public static function init() {
        add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'reject_known_false_positive_insert' ), 100, 2 );
        add_action( 'added_post_meta', array( __CLASS__, 'preserve_ignored_resolution' ), 250, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'preserve_ignored_resolution' ), 250, 4 );
        add_action( 'load-edit.php', array( __CLASS__, 'cleanup_existing_unresolved' ), 36 );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ), 36 );
    }

    public static function reject_known_false_positive_insert( $maybe_empty, $postarr ) {
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
        return self::false_positive_title_reason( $title ) ? true : $maybe_empty;
    }

    public static function preserve_ignored_resolution( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$restoring || 'candidate_status' !== $meta_key || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        $incoming = sanitize_key( (string) $meta_value );
        if ( ! in_array( $incoming, array( 'new', 'incomplete' ), true ) ) {
            return;
        }

        $resolution  = sanitize_key( (string) get_post_meta( $object_id, 'candidate_resolution', true ) );
        $resolved_at = (string) get_post_meta( $object_id, 'candidate_resolved_at', true );
        if ( ! $resolved_at || ! self::resolution_means_ignored( $resolution ) ) {
            return;
        }

        self::$restoring = true;
        update_post_meta( $object_id, 'candidate_status', 'ignored' );
        self::$restoring = false;
    }

    public static function cleanup_existing_unresolved() {
        global $typenow;

        if ( 'event_candidate' !== $typenow || ! current_user_can( 'manage_options' ) || self::is_filtered_request() ) {
            return;
        }
        if ( get_option( self::OPTION_KEY ) ) {
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
                array( 'key' => 'candidate_status', 'value' => array( 'new', 'incomplete' ), 'compare' => 'IN' ),
            ),
        ) );

        $report = array( 'checked' => 0, 'ignored' => 0, 'stale' => 0, 'ui_chrome' => 0, 'marketing' => 0 );

        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            if ( ! $candidate_id ) {
                continue;
            }

            $report['checked']++;
            $reason = self::candidate_false_positive_reason( $candidate_id );
            if ( ! $reason ) {
                continue;
            }

            $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
            if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
                continue;
            }

            update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
            update_post_meta( $candidate_id, 'candidate_resolution', 'html_final_guard_false_positive' );
            update_post_meta( $candidate_id, 'candidate_quality_reason', sanitize_key( $reason ) );
            update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
            update_post_meta( $candidate_id, 'candidate_html_final_guard_version', self::ENGINE_VERSION );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );

            $report['ignored']++;
            if ( 'stale_start_date' === $reason ) {
                $report['stale']++;
            } elseif ( 'calendar_ui_chrome' === $reason ) {
                $report['ui_chrome']++;
            } elseif ( 'marketing_heading' === $reason ) {
                $report['marketing']++;
            }
        }

        update_option( self::OPTION_KEY, array_merge( array( 'version' => self::ENGINE_VERSION ), $report ), false );
        set_transient( self::notice_key(), $report, 10 * MINUTE_IN_SECONDS );
    }

    private static function candidate_false_positive_reason( $candidate_id ) {
        $title_reason = self::false_positive_title_reason( get_the_title( $candidate_id ) );
        if ( $title_reason ) {
            return $title_reason;
        }
        return self::is_stale_start_date( (string) get_post_meta( $candidate_id, 'start_date', true ) ) ? 'stale_start_date' : '';
    }

    private static function false_positive_title_reason( $title ) {
        $key = self::normalize_key( $title );
        if ( ! $key ) {
            return '';
        }

        $calendar_ui = array(
            'etkinlikler arama ve gorunumlerde gezinme',
            'etkinlik gorunumlerde gezinme',
            'events search and views navigation',
            'event views navigation',
            'search for events',
        );
        if ( in_array( $key, $calendar_ui, true ) ) {
            return 'calendar_ui_chrome';
        }

        $marketing = array(
            'international meeting point of steel industry',
            'meeting point of steel industry in eurasia',
            'eurasia rail the industry s must attend event',
            'isaf international hakkinda',
            'tarimin merkezine davetlisiniz',
            'beautyistanbul 2026 in numbers',
            'why beautyeurasia',
            'why emitt',
        );
        return in_array( $key, $marketing, true ) ? 'marketing_heading' : '';
    }

    private static function resolution_means_ignored( $resolution ) {
        if ( ! $resolution ) {
            return false;
        }
        if ( 'ignored' === $resolution || false !== strpos( $resolution, 'false_positive' ) ) {
            return true;
        }
        return in_array( $resolution, array( 'parser_false_positive', 'confidence_false_positive', 'retro_cleanup_false_positive', 'html_final_guard_false_positive' ), true );
    }

    private static function is_stale_start_date( $value ) {
        if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})/', (string) $value, $m ) ) {
            return false;
        }
        try {
            $date = new DateTime( $m[1], wp_timezone() );
            $date->setTime( 0, 0, 0 );
            $cutoff = new DateTime( 'now', wp_timezone() );
            $cutoff->modify( '-' . self::STALE_DAYS . ' days' );
            $cutoff->setTime( 0, 0, 0 );
            return $date < $cutoff;
        } catch ( Exception $e ) {
            return false;
        }
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
        echo '<div class="notice notice-success is-dismissible"><p><strong>HTML Final Guard 1.34.8:</strong> ' . esc_html( sprintf(
            'Kontrol edilen: %1$d; yok sayılan: %2$d. Nedenler — stale: %3$d, takvim arayüzü: %4$d, marketing başlığı: %5$d.',
            absint( $report['checked'] ), absint( $report['ignored'] ), absint( $report['stale'] ), absint( $report['ui_chrome'] ), absint( $report['marketing'] )
        ) ) . '</p></div>';
    }

    private static function notice_key() {
        return 'sektorel_html_final_guard_notice_' . absint( get_current_user_id() );
    }
}
