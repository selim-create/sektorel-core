<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Repairs a narrow set of English/brand casing artifacts caused by applying
 * Turkish I/İ casing rules to all-uppercase English event titles.
 *
 * Examples seen in production:
 * - Internatıonal -> International
 * - Beautyıstanbul -> BeautyIstanbul
 * - Texhıbıtıon -> Texhibition
 * - Fashıon Connectıon -> Fashion Connection
 * - Wın Eurasia -> WIN Eurasia
 *
 * Turkish title casing is intentionally left untouched.
 */
class Sektorel_Event_Title_Casing_Fix {

    const ENGINE_VERSION = '1341';
    const OPTION_KEY     = 'sektorel_event_title_casing_fix_1341';
    const BATCH_SIZE     = 500;

    private static $lock = false;

    public static function init() {
        add_filter( 'wp_insert_post_data', array( __CLASS__, 'normalize_post_data' ), 46, 2 );
        add_action( 'load-edit.php', array( __CLASS__, 'repair_existing_candidates' ), 35 );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ), 35 );
    }

    public static function normalize_post_data( $data, $postarr ) {
        $post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';
        if ( ! in_array( $post_type, array( 'event_candidate', 'event_source', 'event' ), true ) || empty( $data['post_title'] ) ) {
            return $data;
        }

        $data['post_title'] = self::repair_title( $data['post_title'] );
        return $data;
    }

    public static function repair_existing_candidates() {
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

        $checked = 0;
        $changed = 0;

        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            if ( ! $candidate_id ) {
                continue;
            }

            $checked++;
            $current = (string) get_the_title( $candidate_id );
            $fixed   = self::repair_title( $current );

            update_post_meta( $candidate_id, 'candidate_title_casing_version', self::ENGINE_VERSION );
            update_post_meta( $candidate_id, 'candidate_title_casing_checked_at', current_time( 'mysql' ) );

            if ( ! $fixed || $fixed === $current ) {
                continue;
            }

            update_post_meta( $candidate_id, 'candidate_title_before_casing_fix', $current );
            wp_update_post( array(
                'ID'         => $candidate_id,
                'post_title' => $fixed,
            ) );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );
            $changed++;
        }

        $report = array(
            'version'      => self::ENGINE_VERSION,
            'checked'      => $checked,
            'changed'      => $changed,
            'completed_at' => current_time( 'mysql' ),
        );

        update_option( self::OPTION_KEY, $report, false );
        set_transient( self::notice_key(), $report, 10 * MINUTE_IN_SECONDS );

        self::$lock = false;
    }

    private static function repair_title( $title ) {
        $title = (string) $title;
        if ( '' === $title ) {
            return '';
        }

        $patterns = array(
            '/(?<![\p{L}\p{N}])internat[ıi]onal(?![\p{L}\p{N}])/iu' => 'International',
            '/(?<![\p{L}\p{N}])exh[ıi]b[ıi]t[ıi]on(?![\p{L}\p{N}])/iu' => 'Exhibition',
            '/(?<![\p{L}\p{N}])fash[ıi]on(?![\p{L}\p{N}])/iu' => 'Fashion',
            '/(?<![\p{L}\p{N}])connect[ıi]on(?![\p{L}\p{N}])/iu' => 'Connection',
            '/(?<![\p{L}\p{N}])beauty[ıi]stanbul(?![\p{L}\p{N}])/iu' => 'BeautyIstanbul',
            '/(?<![\p{L}\p{N}])texh[ıi]b[ıi]t[ıi]on(?![\p{L}\p{N}])/iu' => 'Texhibition',
            '/(?<![\p{L}\p{N}])w[ıi]n\s+eurasia(?![\p{L}\p{N}])/iu' => 'WIN Eurasia',
        );

        foreach ( $patterns as $pattern => $replacement ) {
            $title = preg_replace( $pattern, $replacement, $title );
        }

        return trim( preg_replace( '/\s+/u', ' ', $title ) );
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
        echo '<div class="notice notice-success is-dismissible"><p><strong>Title Casing 1.34.1:</strong> ' . esc_html(
            sprintf(
                'Kontrol edilen unresolved HTML aday: %1$d; düzeltilen başlık: %2$d.',
                absint( $report['checked'] ),
                absint( $report['changed'] )
            )
        ) . '</p></div>';
    }

    private static function notice_key() {
        return 'sektorel_event_title_casing_notice_' . absint( get_current_user_id() );
    }
}
