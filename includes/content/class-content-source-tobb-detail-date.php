<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Source-specific URL/date enrichment for TOBB content candidates.
 *
 * TOBB RSS feeds do not reliably expose publication dates and may emit
 * relative `Detay.php?...` links. Content sources historically used the site
 * root as base_url, which can turn those links into `/Detay.php?...` instead
 * of the canonical `/Sayfalar/Detay.php?...` URL. This class repairs that
 * deterministic TOBB-only case and then reads the publication date from the
 * canonical detail page before manual candidate triage.
 *
 * No AI call, draft creation or automatic publishing happens here.
 */
class Sektorel_Content_Source_TOBB_Detail_Date {

    const CACHE_VERSION = '2';
    const CACHE_TTL = 7 * DAY_IN_SECONDS;
    const MISS_CACHE_TTL = 5 * MINUTE_IN_SECONDS;
    const TIMEOUT = 10;
    const MAX_BODY_SIZE = 786432; // 768 KB.
    const CANONICAL_BASE_URL = 'https://www.tobb.org.tr/Sayfalar/';

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        // Keep future RSS relative-link resolution correct before a scan starts.
        add_action( 'wp_ajax_sektorel_content_prepare_scans', array( __CLASS__, 'normalize_source_configuration' ), 1 );
        add_action( 'wp_ajax_sektorel_content_scan_batch', array( __CLASS__, 'normalize_source_configuration' ), 1 );
        add_action( 'admin_post_sektorel_content_scan_source', array( __CLASS__, 'normalize_source_configuration' ), 1 );

        // Run before Sektorel_Content_Candidate_Triage (default priority 10).
        add_action( 'wp_ajax_sektorel_content_triage_batch', array( __CLASS__, 'enrich_next_batch' ), 1 );
    }

    /**
     * Repair the seeded TOBB source base path so relative `Detay.php` links are
     * resolved against `/Sayfalar/` on all future scans.
     */
    public static function normalize_source_configuration() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $definitions = array(
            'tobb_news' => 'https://www.tobb.org.tr/Sayfalar/RssFeeder.php?List=Haberler',
            'tobb_announcements' => 'https://www.tobb.org.tr/Sayfalar/RssFeeder.php?List=DuyurularListesi',
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

    public static function enrich_next_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, Sektorel_Content_Candidate_Triage::NONCE_ACTION ) ) {
            return;
        }

        global $wpdb;
        $table = Sektorel_Content_Candidates::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, source_key, source_url, canonical_url, normalized_url, canonical_hash, published_at, evidence_json, normalized_payload
                 FROM {$table}
                 WHERE status = %s
                   AND ( published_at IS NULL OR published_at = '' )
                   AND source_key IN ( 'tobb_news', 'tobb_announcements' )
                 ORDER BY id ASC
                 LIMIT %d",
                Sektorel_Content_Candidates::STATUS_NEW,
                Sektorel_Content_Candidate_Triage::BATCH_SIZE
            ),
            ARRAY_A
        );

        // At the beginning of a re-triage run rows are still REVIEW until the
        // priority-10 triage callback reopens them, so enrich REVIEW as fallback.
        if ( ! $rows ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, source_key, source_url, canonical_url, normalized_url, canonical_hash, published_at, evidence_json, normalized_payload
                     FROM {$table}
                     WHERE status = %s
                       AND ( published_at IS NULL OR published_at = '' )
                       AND source_key IN ( 'tobb_news', 'tobb_announcements' )
                     ORDER BY id ASC
                     LIMIT %d",
                    Sektorel_Content_Candidates::STATUS_REVIEW,
                    Sektorel_Content_Candidate_Triage::BATCH_SIZE
                ),
                ARRAY_A
            );
        }

        foreach ( (array) $rows as $row ) {
            self::enrich_candidate( $row );
        }
    }

    private static function enrich_candidate( $row ) {
        $candidate_id = absint( $row['id'] ?? 0 );
        $raw_url = trim( (string) ( ! empty( $row['canonical_url'] ) ? $row['canonical_url'] : ( $row['source_url'] ?? '' ) ) );

        if ( ! $candidate_id ) {
            return false;
        }

        $url = self::canonicalize_tobb_detail_url( $raw_url );
        if ( ! $url ) {
            self::record_resolution_failure( $row, 'unsupported_url', array( 'attempted_url' => $raw_url ) );
            return false;
        }

        // Persist the canonical repair even if the remote date lookup fails, so
        // the next scan/triage and admin links no longer carry the root Detay.php URL.
        if ( $url !== $raw_url ) {
            self::repair_candidate_url( $row, $url );
            $row['source_url'] = $url;
            $row['canonical_url'] = $url;
            $row['normalized_url'] = $url;
            $row['canonical_hash'] = hash( 'sha256', $url );
        }

        $result = self::resolve_date( $url );
        if ( ! is_array( $result ) || empty( $result['published_at'] ) ) {
            self::record_resolution_failure(
                $row,
                is_array( $result ) && ! empty( $result['error_code'] ) ? $result['error_code'] : 'date_resolution_failed',
                array(
                    'attempted_url' => $url,
                    'http_status'   => is_array( $result ) ? absint( $result['http_status'] ?? 0 ) : 0,
                    'message'       => is_array( $result ) ? sanitize_text_field( $result['message'] ?? '' ) : '',
                )
            );
            return false;
        }

        return self::persist_success( $row, $url, $result );
    }

    private static function repair_candidate_url( $row, $url ) {
        global $wpdb;

        $candidate_id = absint( $row['id'] ?? 0 );
        if ( ! $candidate_id || ! $url ) {
            return false;
        }

        $normalized = self::decode_json_array( $row['normalized_payload'] ?? '' );
        $normalized['url'] = $url;

        $updated = $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'source_url'         => $url,
                'canonical_url'      => $url,
                'normalized_url'     => $url,
                'canonical_hash'     => hash( 'sha256', $url ),
                'normalized_payload' => wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'         => current_time( 'mysql', true ),
            ),
            array( 'id' => $candidate_id ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        return false !== $updated;
    }

    private static function persist_success( $row, $url, $result ) {
        global $wpdb;

        $candidate_id = absint( $row['id'] ?? 0 );
        if ( ! $candidate_id ) {
            return false;
        }

        $evidence = self::decode_json_array( $row['evidence_json'] ?? '' );
        $evidence['publication_date'] = array(
            'status'      => 'resolved',
            'source'      => 'tobb_detail_page',
            'source_url'  => $url,
            'raw_value'   => sanitize_text_field( $result['raw_value'] ?? '' ),
            'http_status' => absint( $result['http_status'] ?? 0 ),
            'resolved_at' => gmdate( 'c' ),
            'version'     => self::CACHE_VERSION,
        );
        unset( $evidence['publication_date_resolution'] );

        $normalized = self::decode_json_array( $row['normalized_payload'] ?? '' );
        $normalized['url'] = $url;
        $normalized['published_at'] = $result['published_at'];
        $normalized['published_source'] = 'tobb_detail_page';

        $updated = $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'source_url'         => $url,
                'canonical_url'      => $url,
                'normalized_url'     => $url,
                'canonical_hash'     => hash( 'sha256', $url ),
                'published_at'       => $result['published_at'],
                'evidence_json'      => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'normalized_payload' => wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'         => current_time( 'mysql', true ),
            ),
            array( 'id' => $candidate_id ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        return false !== $updated;
    }

    private static function record_resolution_failure( $row, $code, $details = array() ) {
        global $wpdb;

        $candidate_id = absint( $row['id'] ?? 0 );
        if ( ! $candidate_id ) {
            return;
        }

        $evidence = self::decode_json_array( $row['evidence_json'] ?? '' );
        $evidence['publication_date_resolution'] = array(
            'status'        => 'failed',
            'error_code'    => sanitize_key( $code ),
            'attempted_url' => esc_url_raw( $details['attempted_url'] ?? '' ),
            'http_status'   => absint( $details['http_status'] ?? 0 ),
            'message'       => sanitize_text_field( $details['message'] ?? '' ),
            'checked_at'    => gmdate( 'c' ),
            'version'       => self::CACHE_VERSION,
        );

        $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'evidence_json' => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'    => current_time( 'mysql', true ),
            ),
            array( 'id' => $candidate_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    private static function resolve_date( $url ) {
        $cache_key = 'sektorel_tobb_content_date_v' . self::CACHE_VERSION . '_' . md5( $url );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && array_key_exists( 'published_at', $cached ) ) {
            return $cached;
        }

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => self::TIMEOUT,
                'redirection'         => 2,
                'limit_response_size' => self::MAX_BODY_SIZE,
                'user-agent'          => 'SektorelAjandaContentBot/1.0; +' . home_url( '/' ),
                'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2' ),
            )
        );

        if ( is_wp_error( $response ) ) {
            $result = array(
                'published_at' => null,
                'raw_value'    => '',
                'http_status'  => 0,
                'error_code'   => 'fetch_error',
                'message'      => $response->get_error_message(),
            );
            set_transient( $cache_key, $result, self::MISS_CACHE_TTL );
            return $result;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $html   = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 ) {
            $result = array(
                'published_at' => null,
                'raw_value'    => '',
                'http_status'  => $status,
                'error_code'   => 'http_status',
                'message'      => 'TOBB detay sayfası HTTP ' . $status . ' döndürdü.',
            );
            set_transient( $cache_key, $result, self::MISS_CACHE_TTL );
            return $result;
        }

        if ( '' === trim( $html ) ) {
            $result = array(
                'published_at' => null,
                'raw_value'    => '',
                'http_status'  => $status,
                'error_code'   => 'empty_body',
                'message'      => 'TOBB detay sayfası boş gövde döndürdü.',
            );
            set_transient( $cache_key, $result, self::MISS_CACHE_TTL );
            return $result;
        }

        $raw_date = self::extract_detail_date( $html );
        $published_at = self::normalize_ddmmyyyy( $raw_date );
        $result = array(
            'published_at' => $published_at,
            'raw_value'    => $raw_date ?: '',
            'http_status'  => $status,
            'error_code'   => $published_at ? '' : 'date_not_found',
            'message'      => $published_at ? '' : 'TOBB detay HTML içinde yayın tarihi bulunamadı.',
        );

        set_transient( $cache_key, $result, $published_at ? self::CACHE_TTL : self::MISS_CACHE_TTL );
        return $result;
    }

    private static function extract_detail_date( $html ) {
        if ( preg_match( '/<meta[^>]+(?:property|name)=["\']article:published_time["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $match ) ) {
            $timestamp = strtotime( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( false !== $timestamp ) {
                return gmdate( 'd.m.Y', $timestamp );
            }
        }

        if ( preg_match( '/["\']datePublished["\']\s*:\s*["\']([^"\']+)["\']/i', $html, $match ) ) {
            $timestamp = strtotime( html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            if ( false !== $timestamp ) {
                return gmdate( 'd.m.Y', $timestamp );
            }
        }

        $text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', (string) $text );
        if ( preg_match( '/\b(\d{1,2}\.\d{1,2}\.\d{4})\s*\/\s*[^\r\n]{1,160}/u', $text, $match ) ) {
            return $match[1];
        }

        return '';
    }

    private static function normalize_ddmmyyyy( $value ) {
        $value = trim( (string) $value );
        if ( ! preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $match ) ) {
            return null;
        }

        $day   = (int) $match[1];
        $month = (int) $match[2];
        $year  = (int) $match[3];
        if ( ! checkdate( $month, $day, $year ) ) {
            return null;
        }

        return sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day );
    }

    /**
     * Canonicalize only the known TOBB detail-page shapes.
     */
    private static function canonicalize_tobb_detail_url( $url ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( ! $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
            return '';
        }

        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        if ( ! in_array( $host, array( 'tobb.org.tr', 'www.tobb.org.tr' ), true ) ) {
            return '';
        }

        $path = '/' . ltrim( (string) $parts['path'], '/' );
        $path_lower = strtolower( $path );
        if ( '/detay.php' === $path_lower ) {
            $path = '/Sayfalar/Detay.php';
        } elseif ( '/sayfalar/detay.php' === $path_lower ) {
            $path = '/Sayfalar/Detay.php';
        } elseif ( '/sayfalar/eng/detay.php' === $path_lower ) {
            $path = '/Sayfalar/Eng/Detay.php';
        } else {
            return '';
        }

        $query = array();
        if ( ! empty( $parts['query'] ) ) {
            parse_str( html_entity_decode( (string) $parts['query'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $query );
        }

        $canonical = 'https://www.tobb.org.tr' . $path;
        if ( $query ) {
            $canonical .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }

        return esc_url_raw( $canonical, array( 'https' ) );
    }

    private static function decode_json_array( $value ) {
        if ( ! $value ) {
            return array();
        }

        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
