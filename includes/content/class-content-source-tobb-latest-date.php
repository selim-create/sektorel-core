<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolve the newest TOBB publication dates from the unpaginated archive page.
 *
 * TOBB's bare archive URL is not equivalent to `?s=0`: the unpaginated page
 * exposes the newest records while the offset variant can return an older
 * window. This helper is intentionally tried before the paginated archive
 * resolver and falls back silently when it cannot match a candidate.
 */
class Sektorel_Content_Source_TOBB_Latest_Date {

    const VERSION = '1';
    const CACHE_TTL = 10 * MINUTE_IN_SECONDS;
    const TIMEOUT = 12;
    const MAX_BODY_SIZE = 1048576;

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

        $map = self::latest_map( $source_key );
        if ( is_wp_error( $map ) ) {
            return $row;
        }

        $rid = self::rid_from_candidate( $row );
        $title_key = self::normalize_title( $row['title'] ?? '' );
        $match = null;

        if ( $rid && ! empty( $map['by_rid'][ $rid ] ) ) {
            $match = $map['by_rid'][ $rid ];
        } elseif ( $title_key && ! empty( $map['by_title'][ $title_key ] ) ) {
            $match = $map['by_title'][ $title_key ];
        }

        if ( ! is_array( $match ) || empty( $match['published_at'] ) ) {
            return $row;
        }

        self::persist_success( $row, $match );
        return self::fresh_row( $candidate_id, $row );
    }

    private static function latest_map( $source_key ) {
        $cache_key = 'sektorel_tobb_latest_dates_v' . self::VERSION . '_' . sanitize_key( $source_key );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['by_rid'], $cached['by_title'] ) ) {
            return $cached;
        }

        $map = array( 'by_rid' => array(), 'by_title' => array() );
        $errors = array();

        foreach ( self::latest_urls( $source_key ) as $url ) {
            $result = self::fetch_page( $url );
            if ( is_wp_error( $result ) ) {
                $errors[] = $result->get_error_message();
                continue;
            }

            foreach ( self::parse_entries( $result['body'], $url ) as $entry ) {
                if ( ! empty( $entry['rid'] ) ) {
                    $map['by_rid'][ (string) $entry['rid'] ] = $entry;
                }
                $title_key = self::normalize_title( $entry['title'] ?? '' );
                if ( $title_key ) {
                    $map['by_title'][ $title_key ] = $entry;
                }
            }
        }

        if ( ! $map['by_rid'] && ! $map['by_title'] ) {
            return new WP_Error(
                'tobb_latest_archive_unavailable',
                $errors ? implode( ' | ', array_unique( $errors ) ) : 'TOBB güncel arşiv sayfasından kayıt okunamadı.'
            );
        }

        set_transient( $cache_key, $map, self::CACHE_TTL );
        return $map;
    }

    private static function latest_urls( $source_key ) {
        if ( 'tobb_news' === $source_key ) {
            return array(
                'http://www.tobb.org.tr/Sayfalar/Arsiv.php?lst=Haberler',
            );
        }

        return array(
            'http://www.tobb.org.tr/Sayfalar/Arsiv.php?lst=DuyurularListesi',
            'http://www.tobb.org.tr/Sayfalar/Arsiv.php?kategori=Duyurular&lst=DuyurularListesi',
        );
    }

    private static function fetch_page( $url ) {
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
            return new WP_Error( 'tobb_latest_fetch_error', $response->get_error_message() );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'tobb_latest_http_error', 'TOBB güncel arşivi HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'tobb_latest_empty', 'TOBB güncel arşivi boş yanıt döndürdü.' );
        }

        return array( 'body' => $body, 'http_status' => $status );
    }

    private static function parse_entries( $html, $source_url ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return array();
        }

        $previous = libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return array();
        }

        $xpath = new DOMXPath( $dom );
        $anchors = $xpath->query( '//a[contains(translate(@href,"DETAYPHP","detayphp"),"detay.php")]' );
        if ( ! $anchors ) {
            return array();
        }

        $entries = array();
        foreach ( $anchors as $anchor ) {
            $title = self::clean_text( $anchor->textContent );
            if ( mb_strlen( $title ) < 4 ) {
                continue;
            }

            $href = html_entity_decode( trim( (string) $anchor->getAttribute( 'href' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $date = self::find_nearby_date( $anchor );
            $published_at = self::normalize_ddmmyyyy( $date );
            if ( ! $published_at ) {
                continue;
            }

            $entries[] = array(
                'rid'          => self::rid_from_url( $href ),
                'title'        => $title,
                'raw_date'     => $date,
                'published_at' => $published_at,
                'archive_url'  => $source_url,
            );
        }

        return $entries;
    }

    private static function find_nearby_date( DOMNode $node ) {
        $current = $node;
        for ( $depth = 0; $depth < 6 && $current; $depth++ ) {
            $text = self::clean_text( $current->textContent );
            if ( preg_match( '/\b(\d{1,2}\.\d{1,2}\.\d{4})\b/u', $text, $match ) ) {
                return $match[1];
            }
            $current = $current->parentNode;
        }

        $current = $node->parentNode;
        for ( $depth = 0; $depth < 5 && $current; $depth++ ) {
            $sibling = $current->previousSibling;
            for ( $i = 0; $i < 6 && $sibling; $i++, $sibling = $sibling->previousSibling ) {
                $text = self::clean_text( $sibling->textContent );
                if ( preg_match( '/\b(\d{1,2}\.\d{1,2}\.\d{4})\b/u', $text, $match ) ) {
                    return $match[1];
                }
            }
            $current = $current->parentNode;
        }

        return '';
    }

    private static function persist_success( $row, $match ) {
        global $wpdb;
        $candidate_id = absint( $row['id'] ?? 0 );
        if ( ! $candidate_id ) {
            return false;
        }

        $evidence = self::decode_json_array( $row['evidence_json'] ?? '' );
        $evidence['publication_date'] = array(
            'status'      => 'resolved',
            'source'      => 'tobb_archive_latest_page',
            'source_url'  => esc_url_raw( $match['archive_url'] ?? '' ),
            'raw_value'   => sanitize_text_field( $match['raw_date'] ?? '' ),
            'rid'         => absint( $match['rid'] ?? 0 ),
            'resolved_at' => gmdate( 'c' ),
            'version'     => self::VERSION,
        );
        unset( $evidence['publication_date_resolution'] );

        $normalized = self::decode_json_array( $row['normalized_payload'] ?? '' );
        $normalized['published_at'] = $match['published_at'];
        $normalized['published_source'] = 'tobb_archive_latest_page';

        return false !== $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'published_at'       => $match['published_at'],
                'evidence_json'      => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'normalized_payload' => wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'         => current_time( 'mysql', true ),
            ),
            array( 'id' => $candidate_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    private static function rid_from_candidate( $row ) {
        foreach ( array( 'canonical_url', 'source_url', 'normalized_url', 'source_item_key' ) as $key ) {
            $rid = self::rid_from_url( $row[ $key ] ?? '' );
            if ( $rid ) {
                return $rid;
            }
        }

        foreach ( array( 'raw_payload', 'normalized_payload' ) as $json_key ) {
            $payload = self::decode_json_array( $row[ $json_key ] ?? '' );
            foreach ( $payload as $value ) {
                if ( is_scalar( $value ) ) {
                    $rid = self::rid_from_url( (string) $value );
                    if ( $rid ) {
                        return $rid;
                    }
                }
            }
        }

        return 0;
    }

    private static function rid_from_url( $value ) {
        $value = html_entity_decode( trim( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( preg_match( '/(?:[?&]|\b)rid\s*=\s*(\d+)/i', $value, $match ) ) {
            return absint( $match[1] );
        }
        return 0;
    }

    private static function normalize_title( $title ) {
        $title = remove_accents( self::clean_text( $title ) );
        $title = mb_strtolower( $title, 'UTF-8' );
        $title = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $title );
        return trim( preg_replace( '/\s+/u', ' ', (string) $title ) );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }

    private static function normalize_ddmmyyyy( $value ) {
        if ( ! preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim( (string) $value ), $match ) ) {
            return null;
        }
        $day = (int) $match[1];
        $month = (int) $match[2];
        $year = (int) $match[3];
        if ( ! checkdate( $month, $day, $year ) ) {
            return null;
        }
        return sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day );
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
