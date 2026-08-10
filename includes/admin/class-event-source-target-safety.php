<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Safety layer for ES-4B source target discovery.
 *
 * v1.29.0 proved that a high lexical score alone is insufficient: pages such
 * as fair-about, rules, support and single news posts can contain event words.
 * This layer rolls back previously auto-applied targets unless the URL shape
 * itself clearly represents a reusable listing/calendar endpoint. It also
 * disables the v1.29.0 discovery UI until the next stricter discovery engine.
 */
class Sektorel_Event_Source_Target_Safety {

    const VERSION = '1291';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'disable_unsafe_discovery_menu' ), 99 );
        add_action( 'load-edit.php', array( __CLASS__, 'rollback_unsafe_targets' ), 15 );
        add_filter( 'manage_event_source_posts_columns', array( __CLASS__, 'columns' ), 70 );
        add_action( 'manage_event_source_posts_custom_column', array( __CLASS__, 'render_column' ), 70, 2 );
    }

    public static function disable_unsafe_discovery_menu() {
        remove_submenu_page( 'edit.php?post_type=event', 'sektorel-source-target-discovery' );
    }

    public static function rollback_unsafe_targets() {
        global $typenow;

        if ( 'event_source' !== $typenow || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => 'target_discovery_status',
            'meta_value'     => 'applied',
        ) );

        foreach ( $ids as $source_id ) {
            self::review_applied_target( absint( $source_id ) );
        }
    }

    private static function review_applied_target( $source_id ) {
        if ( self::VERSION === (string) get_post_meta( $source_id, 'target_safety_version', true ) ) {
            return;
        }

        $current  = trim( (string) get_post_meta( $source_id, 'source_url', true ) );
        $original = trim( (string) get_post_meta( $source_id, 'source_original_url', true ) );

        if ( ! $current || ! $original ) {
            update_post_meta( $source_id, 'target_safety_version', self::VERSION );
            return;
        }

        if ( self::is_clear_listing_target( $current ) ) {
            update_post_meta( $source_id, 'target_discovery_status', 'verified_listing' );
            update_post_meta( $source_id, 'target_safety_version', self::VERSION );
            update_post_meta( $source_id, 'target_safety_reason', 'clear_listing_url' );
            return;
        }

        update_post_meta( $source_id, 'target_discovery_rejected_url', $current );
        update_post_meta( $source_id, 'target_discovery_status', 'rolled_back_unsafe' );
        update_post_meta( $source_id, 'target_safety_reason', 'not_clear_listing_url' );
        update_post_meta( $source_id, 'target_safety_version', self::VERSION );
        update_post_meta( $source_id, 'source_url', esc_url_raw( $original, array( 'http', 'https' ) ) );

        // The source health/parser data may belong either to the original URL
        // or to the rejected target. Force a clean check before scanning.
        delete_post_meta( $source_id, 'check_state' );
        delete_post_meta( $source_id, 'detected_parser' );
        delete_post_meta( $source_id, 'last_http_status' );
        delete_post_meta( $source_id, 'last_candidate_scan_at' );
        delete_post_meta( $source_id, 'last_candidate_count' );
    }

    private static function is_clear_listing_target( $url ) {
        $path = strtolower( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
        $path = '/' . trim( $path, '/' );

        if ( ! $path || '/' === $path ) {
            return false;
        }

        // Negative patterns always win. These are content/detail pages, not
        // reusable calendars/listings.
        if ( preg_match( '#/(?:about|hakkinda|hakkımızda|fair-about|fuar-hakkinda|kurallar|rules?|supports?|fair-supports|company-events|market-insights|basin-bultenleri|press|news|haber|blog|iletisim|contact|site-haritasi|sitemap)(?:/|$)#iu', $path ) ) {
            return false;
        }

        // High-confidence reusable list/calendar URL shapes.
        $patterns = array(
            '#/(?:tr|en)?/?(?:gelecek-etkinlikler|yaklasan-etkinlikler|upcoming-events)(?:\.[a-z0-9]+)?/?$#i',
            '#/(?:tr|en)?/?(?:etkinlikler|events|fuarlar|webinars|seminars)/?$#i',
            '#/(?:tr|en)?/?(?:etkinlik|event)/?$#i',
            '#/(?:tr|en)?/?(?:etkinlik-takvimi|event-calendar|events-calendar)/?$#i',
            '#/(?:[^/]+/)*(?:etkinlik-takvimi|event-calendar)/?$#i',
            '#/(?:[^/]+/)*(?:utikad-etkinlikleri)/?$#i',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $path ) ) {
                return true;
            }
        }

        return false;
    }

    public static function columns( $columns ) {
        if ( isset( $columns['target_discovery'] ) ) {
            $columns['target_discovery'] = 'Kaynak Hedef Durumu';
        }
        return $columns;
    }

    public static function render_column( $column, $post_id ) {
        if ( 'target_discovery' !== $column ) {
            return;
        }

        $status   = (string) get_post_meta( $post_id, 'target_discovery_status', true );
        $rejected = (string) get_post_meta( $post_id, 'target_discovery_rejected_url', true );

        if ( 'verified_listing' === $status ) {
            echo '<strong style="color:#116329;">Doğrulanmış liste</strong>';
        } elseif ( 'rolled_back_unsafe' === $status ) {
            echo '<strong style="color:#b32d2e;">Geri alındı</strong>';
            if ( $rejected ) {
                echo '<br><span style="font-size:11px;color:#646970;">' . esc_html( wp_parse_url( $rejected, PHP_URL_PATH ) ?: $rejected ) . '</span>';
            }
        }
    }
}
