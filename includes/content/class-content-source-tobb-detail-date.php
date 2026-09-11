<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-content-source-tobb-candidate-identity.php';
require_once __DIR__ . '/class-content-source-tobb-latest-date.php';
require_once __DIR__ . '/class-content-source-tobb-archive-date.php';
require_once __DIR__ . '/class-content-detail-extractor.php';
require_once __DIR__ . '/class-content-ai-draft-processor.php';
require_once dirname( __DIR__ ) . '/core/class-core-settings.php';
require_once dirname( __DIR__ ) . '/admin/class-content-ai-draft-admin.php';
require_once dirname( __DIR__ ) . '/admin/class-core-console.php';

/**
 * Backward-compatible TOBB date enrichment facade.
 *
 * Triage calls this class, but publication dates are resolved from TOBB's
 * archive surfaces rather than one detail-page HTTP request per candidate.
 */
class Sektorel_Content_Source_TOBB_Detail_Date {

    const CACHE_VERSION = '6';
    const CANONICAL_BASE_URL = 'https://www.tobb.org.tr/Sayfalar/';

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        // wp_salt() is a pluggable function and is not guaranteed to exist
        // while active plugin files are still being included. The settings
        // service decrypts panel-stored credentials, so bootstrap it only
        // after WordPress has loaded pluggable.php and fired plugins_loaded.
        add_action( 'plugins_loaded', array( 'Sektorel_Core_Settings', 'init' ), 1 );

        Sektorel_Content_Source_TOBB_Candidate_Identity::init();
        Sektorel_Content_AI_Draft_Processor::init();
        Sektorel_Content_AI_Draft_Admin::init();
        Sektorel_Core_Console::init();

        add_action( 'wp_ajax_sektorel_content_prepare_scans', array( __CLASS__, 'normalize_source_configuration' ), 1 );
        add_action( 'wp_ajax_sektorel_content_scan_batch', array( __CLASS__, 'normalize_source_configuration' ), 1 );
        add_action( 'admin_post_sektorel_content_scan_source', array( __CLASS__, 'normalize_source_configuration' ), 1 );
    }

    public static function normalize_source_configuration() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $definitions = array(
            'tobb_news'          => 'http://www.tobb.org.tr/Sayfalar/RssFeeder.php?List=Haberler',
            'tobb_announcements' => 'http://www.tobb.org.tr/Sayfalar/RssFeeder.php?List=DuyurularListesi',
        );

        foreach ( $definitions as $source_key => $feed_url ) {
            $ids = get_posts( array(
                'post_type'      => 'content_source',
                'post_status'    => array( 'publish', 'private', 'draft' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'     => 'source_key',
                        'value'   => $source_key,
                        'compare' => '=',
                    ),
                ),
            ) );

            if ( ! $ids ) {
                continue;
            }

            $source_id = absint( $ids[0] );
            update_post_meta( $source_id, 'base_url', self::CANONICAL_BASE_URL );
            update_post_meta( $source_id, 'feed_url', $feed_url );
            update_post_meta( $source_id, 'tobb_content_url_normalized_version', self::CACHE_VERSION );
        }
    }

    /**
     * Enrich the exact TOBB candidate that triage is about to evaluate.
     */
    public static function enrich_candidate_for_triage( $row ) {
        $row = is_array( $row ) ? $row : array();
        $candidate_id = absint( $row['id'] ?? 0 );
        $source_key = sanitize_key( $row['source_key'] ?? '' );

        if ( ! $candidate_id || ! in_array( $source_key, array( 'tobb_news', 'tobb_announcements' ), true ) ) {
            return $row;
        }
        if ( ! empty( $row['published_at'] ) ) {
            return $row;
        }

        // Preserve deterministic candidate identity/canonical URL repair first.
        $row = Sektorel_Content_Source_TOBB_Candidate_Identity::repair_candidate( $row );

        // TOBB's bare archive URL exposes the newest records and is not
        // equivalent to the offset-based `s=0` archive view.
        $row = Sektorel_Content_Source_TOBB_Latest_Date::enrich_candidate_for_triage( $row );
        if ( ! empty( $row['published_at'] ) ) {
            return $row;
        }

        // Existing paginated archive resolver remains the fallback for older
        // records so the already-working history coverage is preserved.
        return Sektorel_Content_Source_TOBB_Archive_Date::enrich_candidate_for_triage( $row );
    }
}
