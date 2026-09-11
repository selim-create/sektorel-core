<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Safe source-text enrichment before AI editorial processing.
 *
 * TOBB keeps the RSS summary because detail-page requests are unreliable from
 * production. TCMB detail pages are fetched from a strict host allowlist.
 */
class Sektorel_Content_Detail_Extractor {

    const TIMEOUT = 15;
    const MAX_BODY_SIZE = 2097152; // 2 MB.
    const MAX_TEXT_LENGTH = 24000;
    const MIN_EXISTING_TEXT = 80;
    const MIN_FETCHED_TEXT = 160;

    public static function enrich_candidate( $row ) {
        $row = is_array( $row ) ? $row : array();
        $candidate_id = absint( $row['id'] ?? 0 );
        $source_key = sanitize_key( $row['source_key'] ?? '' );
        if ( ! $candidate_id || ! $source_key ) {
            return new WP_Error( 'invalid_content_candidate', 'Geçersiz content candidate.' );
        }

        $existing = self::clean_text( $row['extracted_text'] ?? '', self::MAX_TEXT_LENGTH );

        // TOBB detail requests are intentionally not reintroduced. The RSS
        // description is official source material and is used when sufficient.
        if ( in_array( $source_key, array( 'tobb_news', 'tobb_announcements' ), true ) ) {
            if ( mb_strlen( $existing ) < self::MIN_EXISTING_TEXT ) {
                return new WP_Error( 'content_detail_too_short', 'TOBB candidate için kullanılabilir kaynak özeti yetersiz.' );
            }
            self::persist_detail_evidence( $row, $existing, 'rss_summary', $row['source_url'] ?? '' );
            return self::fresh_row( $candidate_id, $row );
        }

        // If a future source already carries a substantial official body in
        // its feed, prefer it to an unnecessary network request.
        if ( 'tcmb_press' !== $source_key && mb_strlen( $existing ) >= self::MIN_EXISTING_TEXT ) {
            self::persist_detail_evidence( $row, $existing, 'source_payload', $row['source_url'] ?? '' );
            return self::fresh_row( $candidate_id, $row );
        }

        $url = trim( (string) ( ! empty( $row['canonical_url'] ) ? $row['canonical_url'] : ( $row['source_url'] ?? '' ) ) );
        if ( ! self::is_allowed_source_url( $source_key, $url ) ) {
            return new WP_Error( 'content_detail_url_not_allowed', 'Detay URL bu kaynak için güvenli allowlist ile eşleşmiyor.' );
        }

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => self::TIMEOUT,
                'redirection'         => 3,
                'limit_response_size' => self::MAX_BODY_SIZE,
                'user-agent'          => 'SektorelAjandaContentBot/1.0; +' . home_url( '/' ),
                'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2' ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'content_detail_fetch_error', $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $html = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'content_detail_http_error', 'Detay sayfası HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $html ) ) {
            return new WP_Error( 'content_detail_empty', 'Detay sayfası boş yanıt döndürdü.' );
        }

        $text = self::extract_main_text( $html );
        if ( mb_strlen( $text ) < self::MIN_FETCHED_TEXT ) {
            return new WP_Error( 'content_detail_extract_short', 'Detay sayfasından yeterli kaynak metni çıkarılamadı.' );
        }

        self::persist_detail_evidence( $row, $text, 'detail_page', $url, $status );
        return self::fresh_row( $candidate_id, $row );
    }

    private static function is_allowed_source_url( $source_key, $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }

        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        $allowed = array(
            'tcmb_press' => array( 'tcmb.gov.tr', 'www.tcmb.gov.tr' ),
        );
        $hosts = isset( $allowed[ $source_key ] ) ? $allowed[ $source_key ] : array();
        $hosts = apply_filters( 'sektorel_content_detail_allowed_hosts', $hosts, $source_key, $url );
        $hosts = array_values( array_unique( array_map( static function( $item ) {
            return strtolower( rtrim( (string) $item, '.' ) );
        }, (array) $hosts ) ) );

        return in_array( $host, $hosts, true );
    }

    private static function extract_main_text( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return self::clean_text( wp_strip_all_tags( $html ), self::MAX_TEXT_LENGTH );
        }

        $previous = libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return '';
        }

        $xpath = new DOMXPath( $dom );
        foreach ( array( '//script', '//style', '//noscript', '//svg', '//form', '//nav', '//header', '//footer' ) as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes ) {
                continue;
            }
            $remove = array();
            foreach ( $nodes as $node ) {
                $remove[] = $node;
            }
            foreach ( $remove as $node ) {
                if ( $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        $queries = array(
            '//article',
            '//main',
            '//*[@role="main"]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " article-content ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " content-detail ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " main-content ")]',
            '//*[@id="content"]',
        );

        $best = '';
        foreach ( $queries as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes ) {
                continue;
            }
            foreach ( $nodes as $node ) {
                $text = self::node_text( $node );
                if ( mb_strlen( $text ) > mb_strlen( $best ) ) {
                    $best = $text;
                }
            }
        }

        if ( mb_strlen( $best ) < self::MIN_FETCHED_TEXT ) {
            $body = $xpath->query( '//body' );
            if ( $body && $body->length ) {
                $best = self::node_text( $body->item( 0 ) );
            }
        }

        return self::clean_text( $best, self::MAX_TEXT_LENGTH, true );
    }

    private static function node_text( DOMNode $node ) {
        $lines = array();
        if ( $node instanceof DOMElement ) {
            $xpath = new DOMXPath( $node->ownerDocument );
            $parts = $xpath->query( './/h1|.//h2|.//h3|.//p|.//li', $node );
            if ( $parts && $parts->length ) {
                foreach ( $parts as $part ) {
                    $line = self::clean_text( $part->textContent, 4000 );
                    if ( '' !== $line ) {
                        $lines[] = $line;
                    }
                }
            }
        }
        if ( ! $lines ) {
            return self::clean_text( $node->textContent, self::MAX_TEXT_LENGTH, true );
        }
        return implode( "\n\n", array_values( array_unique( $lines ) ) );
    }

    private static function persist_detail_evidence( $row, $text, $method, $source_url, $http_status = 0 ) {
        global $wpdb;
        $candidate_id = absint( $row['id'] ?? 0 );
        if ( ! $candidate_id ) {
            return false;
        }

        $evidence = self::decode_json_array( $row['evidence_json'] ?? '' );
        $evidence['detail_extraction'] = array(
            'status'       => 'resolved',
            'method'       => sanitize_key( $method ),
            'source_url'   => esc_url_raw( $source_url ),
            'http_status'  => absint( $http_status ),
            'text_length'  => mb_strlen( $text ),
            'extracted_at' => gmdate( 'c' ),
            'version'      => 1,
        );

        $normalized = self::decode_json_array( $row['normalized_payload'] ?? '' );
        $normalized['detail_text_length'] = mb_strlen( $text );
        $normalized['detail_source'] = sanitize_key( $method );

        return false !== $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'extracted_text'     => $text,
                'evidence_json'      => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'normalized_payload' => wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'         => current_time( 'mysql', true ),
            ),
            array( 'id' => $candidate_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    private static function clean_text( $value, $max_length, $preserve_breaks = false ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( $preserve_breaks ) {
            $value = preg_replace( "/[ \t]+/u", ' ', $value );
            $value = preg_replace( "/\s*\n\s*/u", "\n", $value );
            $value = preg_replace( "/\n{3,}/u", "\n\n", $value );
        } else {
            $value = preg_replace( '/\s+/u', ' ', $value );
        }
        $value = trim( (string) $value );
        return mb_substr( $value, 0, max( 1, absint( $max_length ) ) );
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
        return is_array( $fresh ) ? $fresh : $fallback;
    }

    private static function decode_json_array( $value ) {
        if ( ! $value ) {
            return array();
        }
        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
