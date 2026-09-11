<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-content-source-tobb-candidate-identity.php';

/**
 * Source-specific URL/date enrichment for TOBB content candidates.
 *
 * Execution is intentionally inline with the triage loop so the exact
 * candidate being evaluated is enriched first and then re-read from DB.
 */
class Sektorel_Content_Source_TOBB_Detail_Date {

    const CACHE_VERSION = '4';
    const CACHE_TTL = 7 * DAY_IN_SECONDS;
    const MISS_CACHE_TTL = 5 * MINUTE_IN_SECONDS;
    const TIMEOUT = 10;
    const MAX_BODY_SIZE = 786432;
    const CANONICAL_BASE_URL = 'https://www.tobb.org.tr/Sayfalar/';

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        Sektorel_Content_Source_TOBB_Candidate_Identity::init();

        add_action( 'wp_ajax_sektorel_content_prepare_scans', array( __CLASS__, 'normalize_source_configuration' ), 1 );
        add_action( 'wp_ajax_sektorel_content_scan_batch', array( __CLASS__, 'normalize_source_configuration' ), 1 );
        add_action( 'admin_post_sektorel_content_scan_source', array( __CLASS__, 'normalize_source_configuration' ), 1 );
    }

    public static function normalize_source_configuration() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $definitions = array(
            'tobb_news'          => 'https://www.tobb.org.tr/Sayfalar/RssFeeder.php?List=Haberler',
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

    /**
     * Enrich the exact TOBB candidate that triage is about to evaluate.
     * Always returns the freshest DB row available.
     */
    public static function enrich_candidate_for_triage( $row ) {
        $row = is_array( $row ) ? $row : array();
        $candidate_id = absint( $row['id'] ?? 0 );
        $source_key   = sanitize_key( $row['source_key'] ?? '' );

        if ( ! $candidate_id || ! in_array( $source_key, array( 'tobb_news', 'tobb_announcements' ), true ) ) {
            return $row;
        }

        if ( ! empty( $row['published_at'] ) ) {
            return $row;
        }

        $row = Sektorel_Content_Source_TOBB_Candidate_Identity::repair_candidate( $row );
        $raw_url = trim( (string) ( ! empty( $row['canonical_url'] ) ? $row['canonical_url'] : ( $row['source_url'] ?? '' ) ) );
        $url = self::canonicalize_tobb_detail_url( $raw_url );

        if ( ! $url ) {
            self::record_resolution_failure( $row, 'unsupported_url', array( 'attempted_url' => $raw_url ) );
            return self::fresh_row( $candidate_id, $row );
        }

        $result = self::resolve_date( $url );
        if ( empty( $result['published_at'] ) ) {
            self::record_resolution_failure(
                $row,
                ! empty( $result['error_code'] ) ? $result['error_code'] : 'date_resolution_failed',
                array(
                    'attempted_url' => $url,
                    'http_status'   => absint( $result['http_status'] ?? 0 ),
                    'message'       => sanitize_text_field( $result['message'] ?? '' ),
                )
            );
            return self::fresh_row( $candidate_id, $row );
        }

        self::persist_success( $row, $url, $result );
        return self::fresh_row( $candidate_id, $row );
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

        return false !== $wpdb->update(
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
        if ( '/detay.php' === $path_lower || '/sayfalar/detay.php' === $path_lower ) {
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

    private static function fresh_row( $candidate_id, $fallback ) {
        global $wpdb;
        $fresh = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Sektorel_Content_Candidates::table_name() . ' WHERE id = %d LIMIT 1',
                absint( $candidate_id )
            ),
            ARRAY_A
        );
        return is_array( $fresh ) ? $fresh : ( is_array( $fallback ) ? $fallback : array() );
    }

    private static function decode_json_array( $value ) {
        if ( ! $value ) {
            return array();
        }
        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
