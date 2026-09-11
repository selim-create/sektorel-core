<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Source-specific date enrichment for TOBB content candidates.
 *
 * TOBB RSS feeds do not reliably expose a publication-date element. The
 * canonical Detay.php pages do expose a stable `DD.MM.YYYY / ...` publication
 * line. We resolve that date only when a TOBB candidate has no published_at.
 *
 * This class runs only immediately before manual candidate triage. It never
 * creates posts and never changes candidate status by itself.
 */
class Sektorel_Content_Source_TOBB_Detail_Date {

    const CACHE_TTL = 7 * DAY_IN_SECONDS;
    const TIMEOUT = 10;
    const MAX_BODY_SIZE = 786432; // 768 KB.

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        // Run before Sektorel_Content_Candidate_Triage (default priority 10).
        add_action( 'wp_ajax_sektorel_content_triage_batch', array( __CLASS__, 'enrich_next_batch' ), 1 );
    }

    public static function enrich_next_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // The triage callback performs the authoritative nonce check. Do the
        // same here without terminating the request, so this enrichment stays
        // inert for unrelated/invalid AJAX calls.
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, Sektorel_Content_Candidate_Triage::NONCE_ACTION ) ) {
            return;
        }

        global $wpdb;
        $table = Sektorel_Content_Candidates::table_name();

        // Prefer NEW rows. On a re-triage run all rows may still be REVIEW at
        // the beginning of the first request, so fall back to REVIEW rows.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, source_key, source_url, canonical_url, published_at, evidence_json, normalized_payload
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

        if ( ! $rows ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, source_key, source_url, canonical_url, published_at, evidence_json, normalized_payload
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
        global $wpdb;

        $candidate_id = absint( $row['id'] ?? 0 );
        $url = trim( (string) ( $row['canonical_url'] ?: $row['source_url'] ) );
        if ( ! $candidate_id || ! self::is_supported_tobb_detail_url( $url ) ) {
            return false;
        }

        $result = self::resolve_date( $url );
        if ( ! is_array( $result ) || empty( $result['published_at'] ) ) {
            return false;
        }

        $evidence = array();
        if ( ! empty( $row['evidence_json'] ) ) {
            $decoded = json_decode( (string) $row['evidence_json'], true );
            if ( is_array( $decoded ) ) {
                $evidence = $decoded;
            }
        }
        $evidence['publication_date'] = array(
            'source'      => 'tobb_detail_page',
            'source_url'  => $url,
            'raw_value'   => $result['raw_value'],
            'resolved_at' => gmdate( 'c' ),
        );

        $normalized = array();
        if ( ! empty( $row['normalized_payload'] ) ) {
            $decoded = json_decode( (string) $row['normalized_payload'], true );
            if ( is_array( $decoded ) ) {
                $normalized = $decoded;
            }
        }
        $normalized['published_at'] = $result['published_at'];
        $normalized['published_source'] = 'tobb_detail_page';

        $updated = $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'published_at'       => $result['published_at'],
                'evidence_json'      => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'normalized_payload' => wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'         => current_time( 'mysql', true ),
            ),
            array( 'id' => $candidate_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        return false !== $updated;
    }

    private static function resolve_date( $url ) {
        $cache_key = 'sektorel_tobb_content_date_' . md5( $url );
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
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $html   = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 || '' === trim( $html ) ) {
            return null;
        }

        $raw_date = self::extract_detail_date( $html );
        $published_at = self::normalize_ddmmyyyy( $raw_date );
        $result = array(
            'published_at' => $published_at,
            'raw_value'    => $raw_date ?: '',
        );

        // Cache successful dates for a week. Cache a miss briefly so one bad
        // detail page does not get hammered multiple times during one session.
        set_transient( $cache_key, $result, $published_at ? self::CACHE_TTL : 15 * MINUTE_IN_SECONDS );
        return $result;
    }

    private static function extract_detail_date( $html ) {
        // Structured metadata first when TOBB exposes it.
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

        // TOBB Detay.php renders publication metadata as `08.09.2026 / Ankara`
        // (or `/ <category>` for announcements). Require the slash so dates in
        // article body copy are not mistaken for the publication date.
        $decoded = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( preg_match( '/\b(\d{1,2}\.\d{1,2}\.\d{4})\s*(?:\x{00A0}|\s)*\/\s*[^<\r\n]{1,120}/u', $decoded, $match ) ) {
            return $match[1];
        }

        $text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( preg_match( '/\b(\d{1,2}\.\d{1,2}\.\d{4})\s*\/\s*[^\r\n]{1,120}/u', $text, $match ) ) {
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

    private static function is_supported_tobb_detail_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
            return false;
        }

        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        if ( ! in_array( $host, array( 'tobb.org.tr', 'www.tobb.org.tr' ), true ) ) {
            return false;
        }

        $path = strtolower( (string) $parts['path'] );
        return '/sayfalar/detay.php' === $path || '/sayfalar/eng/detay.php' === $path;
    }
}
