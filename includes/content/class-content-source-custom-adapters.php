<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic, low-volume HTML adapters for official Content Engine sources.
 *
 * These adapters intentionally parse only the first public listing page of each
 * source. They do not crawl pagination, call AI, create posts, or guess links
 * outside the source-specific allowlist.
 */
class Sektorel_Content_Source_Custom_Adapters {

    const TIMEOUT       = 15;
    const MAX_BODY_SIZE = 2097152; // 2 MB.
    const MAX_LINKS     = 120;

    public static function supported( $adapter ) {
        return in_array(
            sanitize_key( $adapter ),
            array( 'kosgeb_news_html', 'sanayi_news_html' ),
            true
        );
    }

    public static function fetch_items( $adapter, $source_id, $limit ) {
        $adapter   = sanitize_key( $adapter );
        $source_id = absint( $source_id );
        $limit     = max( 1, min( 50, absint( $limit ) ) );

        if ( ! self::supported( $adapter ) ) {
            return new WP_Error( 'unsupported_content_adapter', 'Desteklenmeyen içerik adapterı.' );
        }

        $listing_url = trim( (string) get_post_meta( $source_id, 'feed_url', true ) );
        if ( ! $listing_url ) {
            return new WP_Error( 'missing_listing_url', 'Kaynak liste URL alanı eksik.' );
        }

        $allowed_hosts = self::allowed_hosts( $adapter );
        if ( ! self::is_allowed_url( $listing_url, $allowed_hosts ) ) {
            return new WP_Error( 'unsafe_listing_url', 'Kaynak liste URL resmi host allowlist ile eşleşmiyor.' );
        }

        $response = self::fetch_document( $listing_url );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $items = 'kosgeb_news_html' === $adapter
            ? self::parse_kosgeb_news( $response['body'], $listing_url, $limit )
            : self::parse_sanayi_news( $response['body'], $listing_url, $limit );

        if ( is_wp_error( $items ) ) {
            return $items;
        }

        return array(
            'items'       => $items,
            'http_status' => (int) $response['http_status'],
            'fetch_url'   => $listing_url,
        );
    }

    private static function parse_kosgeb_news( $html, $listing_url, $limit ) {
        $dom = self::load_dom( $html );
        if ( is_wp_error( $dom ) ) {
            return $dom;
        }

        $groups = self::collect_link_groups(
            $dom,
            $listing_url,
            '#/site/tr/genel/detay/(\d+)/[^?#]+#i'
        );

        $items = array();
        foreach ( $groups as $url => $group ) {
            if ( ! preg_match( '#/site/tr/genel/detay/(\d+)/#i', $url, $match ) ) {
                continue;
            }

            $title_anchor = self::best_title_anchor( $group['anchors'] );
            if ( ! $title_anchor ) {
                continue;
            }

            $title = self::clean_text( $title_anchor->textContent, 1000 );
            if ( mb_strlen( $title, 'UTF-8' ) < 8 ) {
                continue;
            }

            $container = self::compact_container( $title_anchor, $title );
            $context   = $container ? self::clean_text( $container->textContent, 5000 ) : $title;
            $date_raw  = self::extract_turkish_date( $context );
            $published = self::normalize_date( $date_raw );
            $summary   = self::summary_from_context( $context, $title, $date_raw );

            $items[] = array(
                'source_item_key' => 'kosgeb:' . absint( $match[1] ),
                'title'           => $title,
                'url'             => esc_url_raw( $url ),
                'published_at'    => $published,
                'summary'         => $summary,
                'date_source'     => $date_raw ? 'listing_turkish_date' : '',
                'raw_payload'     => array(
                    'listing_url' => esc_url_raw( $listing_url ),
                    'detail_id'   => absint( $match[1] ),
                    'title'       => $title,
                    'published'   => $date_raw,
                    'summary'     => $summary,
                ),
            );
        }

        return self::finalize_items( $items, $limit, 'KOSGEB haber listesinde güvenilir kayıt bulunamadı.' );
    }

    private static function parse_sanayi_news( $html, $listing_url, $limit ) {
        $dom = self::load_dom( $html );
        if ( is_wp_error( $dom ) ) {
            return $dom;
        }

        $groups = self::collect_link_groups(
            $dom,
            $listing_url,
            '#/medya/(?:haber|haber-detayi|haberleri)/[^/?#]+/?(?:[?#].*)?$#i'
        );

        $items = array();
        foreach ( $groups as $url => $group ) {
            $title_anchor = self::best_title_anchor( $group['anchors'] );
            if ( ! $title_anchor ) {
                continue;
            }

            $title = self::clean_text( $title_anchor->textContent, 1000 );
            if ( mb_strlen( $title, 'UTF-8' ) < 8 ) {
                continue;
            }

            $container = self::compact_container( $title_anchor, $title );
            $context   = $container ? self::clean_text( $container->textContent, 5000 ) : $title;
            $date_raw  = self::extract_numeric_date( $context );
            $published = self::normalize_date( $date_raw );
            $summary   = self::summary_from_context( $context, $title, $date_raw );
            $path      = (string) wp_parse_url( $url, PHP_URL_PATH );
            $slug      = sanitize_title( basename( untrailingslashit( $path ) ) );
            $item_key  = $slug ? 'sanayi:' . $slug : 'sanayi:' . sha1( $url );

            $items[] = array(
                'source_item_key' => mb_substr( $item_key, 0, 191 ),
                'title'           => $title,
                'url'             => esc_url_raw( $url ),
                'published_at'    => $published,
                'summary'         => $summary,
                'date_source'     => $date_raw ? 'listing_numeric_date' : '',
                'raw_payload'     => array(
                    'listing_url' => esc_url_raw( $listing_url ),
                    'detail_path' => $path,
                    'title'       => $title,
                    'published'   => $date_raw,
                    'summary'     => $summary,
                ),
            );
        }

        return self::finalize_items( $items, $limit, 'Sanayi Bakanlığı haber listesinde güvenilir kayıt bulunamadı.' );
    }

    private static function fetch_document( $url ) {
        $parts   = wp_parse_url( $url );
        $referer = is_array( $parts ) && ! empty( $parts['scheme'] ) && ! empty( $parts['host'] )
            ? $parts['scheme'] . '://' . $parts['host'] . '/'
            : home_url( '/' );

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => self::TIMEOUT,
                'redirection'         => 3,
                'limit_response_size' => self::MAX_BODY_SIZE,
                'user-agent'          => 'Mozilla/5.0 (compatible; SektorelAjandaContentBot/1.2; +' . home_url( '/' ) . ')',
                'headers'             => array(
                    'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
                    'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.5',
                    'Referer'         => $referer,
                    'Cache-Control'   => 'no-cache',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'custom_source_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'custom_source_http_error', 'Kaynak liste sayfası HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'custom_source_empty', 'Kaynak liste sayfası boş yanıt döndürdü.' );
        }

        return array( 'body' => $body, 'http_status' => $status );
    }

    private static function load_dom( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'dom_unavailable', 'Sunucuda DOMDocument eklentisi bulunamadı.' );
        }

        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument();
        $loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $loaded ? $dom : new WP_Error( 'invalid_source_html', 'Kaynak HTML ayrıştırılamadı.' );
    }

    private static function collect_link_groups( DOMDocument $dom, $page_url, $path_pattern ) {
        $xpath  = new DOMXPath( $dom );
        $nodes  = $xpath->query( '//a[@href]' );
        $groups = array();
        $seen   = 0;

        if ( ! $nodes ) {
            return $groups;
        }

        foreach ( $nodes as $node ) {
            if ( ! ( $node instanceof DOMElement ) ) {
                continue;
            }

            $url = self::resolve_url( $node->getAttribute( 'href' ), $page_url );
            if ( ! $url ) {
                continue;
            }
            $path = (string) wp_parse_url( $url, PHP_URL_PATH );
            if ( ! preg_match( $path_pattern, $path ) ) {
                continue;
            }
            if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $page_url, PHP_URL_HOST ) ) ) {
                continue;
            }

            if ( ! isset( $groups[ $url ] ) ) {
                $groups[ $url ] = array( 'anchors' => array() );
                $seen++;
            }
            $groups[ $url ]['anchors'][] = $node;

            if ( $seen >= self::MAX_LINKS ) {
                break;
            }
        }

        return $groups;
    }

    private static function best_title_anchor( $anchors ) {
        $best       = null;
        $best_score = -1;

        foreach ( (array) $anchors as $anchor ) {
            if ( ! ( $anchor instanceof DOMElement ) ) {
                continue;
            }
            $text = self::clean_text( $anchor->textContent, 1000 );
            if ( '' === $text || self::looks_like_date( $text ) ) {
                continue;
            }
            $lower = mb_strtolower( $text, 'UTF-8' );
            if ( in_array( $lower, array( 'devamı', 'devami', 'detay', 'detaylar', 'oku' ), true ) ) {
                continue;
            }

            $length = mb_strlen( $text, 'UTF-8' );
            if ( $length < 8 || $length > 350 ) {
                continue;
            }
            $score = min( 200, $length );
            if ( preg_match( '/[\p{L}]{4,}/u', $text ) ) {
                $score += 50;
            }
            if ( $score > $best_score ) {
                $best       = $anchor;
                $best_score = $score;
            }
        }

        return $best;
    }

    private static function compact_container( DOMElement $anchor, $title ) {
        $node = $anchor;
        $best = null;

        for ( $depth = 0; $depth < 7 && $node; $depth++, $node = $node->parentNode ) {
            if ( ! ( $node instanceof DOMElement ) ) {
                continue;
            }
            $text   = self::clean_text( $node->textContent, 6000 );
            $length = mb_strlen( $text, 'UTF-8' );
            if ( $length < mb_strlen( $title, 'UTF-8' ) || $length > 3500 ) {
                continue;
            }
            if ( false === mb_stripos( $text, $title, 0, 'UTF-8' ) ) {
                continue;
            }
            $best = $node;
            if ( self::extract_turkish_date( $text ) || self::extract_numeric_date( $text ) ) {
                break;
            }
        }

        return $best;
    }

    private static function summary_from_context( $context, $title, $date_raw ) {
        $summary = (string) $context;
        if ( $title ) {
            $summary = str_replace( $title, ' ', $summary );
        }
        if ( $date_raw ) {
            $summary = str_replace( $date_raw, ' ', $summary );
        }
        $summary = preg_replace( '/\b(?:devamı|devami|detaylar?|oku)\b/ui', ' ', $summary );
        $summary = self::clean_text( $summary, 20000 );

        return mb_strlen( $summary, 'UTF-8' ) >= 20 ? $summary : '';
    }

    private static function extract_turkish_date( $text ) {
        if ( preg_match(
            '/\b(\d{1,2})\s+(Ocak|Şubat|Subat|Mart|Nisan|Mayıs|Mayis|Haziran|Temmuz|Ağustos|Agustos|Eylül|Eylul|Ekim|Kasım|Kasim|Aralık|Aralik)\s+(\d{4})\b/ui',
            (string) $text,
            $match
        ) ) {
            return trim( $match[0] );
        }
        return '';
    }

    private static function extract_numeric_date( $text ) {
        if ( preg_match( '/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\b/u', (string) $text, $match ) ) {
            return trim( $match[0] );
        }
        return '';
    }

    private static function normalize_date( $value ) {
        $value = trim( (string) $value );
        if ( ! $value ) {
            return null;
        }

        $months = array(
            'Ocak' => 'January', 'Şubat' => 'February', 'Subat' => 'February',
            'Mart' => 'March', 'Nisan' => 'April', 'Mayıs' => 'May', 'Mayis' => 'May',
            'Haziran' => 'June', 'Temmuz' => 'July', 'Ağustos' => 'August', 'Agustos' => 'August',
            'Eylül' => 'September', 'Eylul' => 'September', 'Ekim' => 'October',
            'Kasım' => 'November', 'Kasim' => 'November', 'Aralık' => 'December', 'Aralik' => 'December',
        );
        $normalized = strtr( $value, $months );

        if ( preg_match( '/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\b/u', $normalized, $match ) ) {
            $timestamp = gmmktime( 12, 0, 0, (int) $match[2], (int) $match[1], (int) $match[3] );
            return gmdate( 'Y-m-d H:i:s', $timestamp );
        }

        $timestamp = strtotime( $normalized . ' 12:00:00 UTC' );
        return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
    }

    private static function looks_like_date( $text ) {
        $text = trim( (string) $text );
        return (bool) ( self::extract_turkish_date( $text ) || self::extract_numeric_date( $text ) );
    }

    private static function finalize_items( $items, $limit, $empty_message ) {
        if ( ! $items ) {
            return new WP_Error( 'custom_source_no_items', $empty_message );
        }

        usort( $items, static function( $a, $b ) {
            $a_ts = ! empty( $a['published_at'] ) ? strtotime( $a['published_at'] . ' UTC' ) : false;
            $b_ts = ! empty( $b['published_at'] ) ? strtotime( $b['published_at'] . ' UTC' ) : false;
            if ( $a_ts && $b_ts && $a_ts !== $b_ts ) {
                return $a_ts > $b_ts ? -1 : 1;
            }
            if ( $a_ts && ! $b_ts ) {
                return -1;
            }
            if ( ! $a_ts && $b_ts ) {
                return 1;
            }
            return strcmp( (string) $a['source_item_key'], (string) $b['source_item_key'] );
        } );

        return array_slice( $items, 0, max( 1, absint( $limit ) ) );
    }

    private static function resolve_url( $url, $base_url ) {
        $url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( ! $url || 0 === strpos( $url, '#' ) || 0 === stripos( $url, 'javascript:' ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $url ) ) {
            return esc_url_raw( $url );
        }

        $base = wp_parse_url( $base_url );
        if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
            return '';
        }
        $origin = $base['scheme'] . '://' . $base['host'];
        if ( 0 === strpos( $url, '//' ) ) {
            return esc_url_raw( $base['scheme'] . ':' . $url );
        }
        if ( 0 === strpos( $url, '/' ) ) {
            return esc_url_raw( $origin . $url );
        }

        $path = isset( $base['path'] ) ? dirname( $base['path'] ) : '/';
        $path = '/' === $path ? '' : rtrim( $path, '/' );
        return esc_url_raw( $origin . $path . '/' . ltrim( $url, '/' ) );
    }

    private static function allowed_hosts( $adapter ) {
        if ( 'kosgeb_news_html' === $adapter ) {
            return array( 'kosgeb.gov.tr', 'www.kosgeb.gov.tr' );
        }
        if ( 'sanayi_news_html' === $adapter ) {
            return array( 'sanayi.gov.tr', 'www.sanayi.gov.tr' );
        }
        return array();
    }

    private static function is_allowed_url( $url, $allowed_hosts ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }
        return in_array( strtolower( rtrim( (string) $parts['host'], '.' ) ), $allowed_hosts, true );
    }

    private static function clean_text( $value, $max_length ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = trim( preg_replace( '/\s+/u', ' ', $value ) );
        return mb_substr( $value, 0, max( 1, absint( $max_length ) ) );
    }
}
