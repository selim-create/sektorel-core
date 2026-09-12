<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic first-page adapter for İstanbul Sanayi Odası news.
 *
 * The audited listing renders each news item inside #innerContent > ul.content-list
 * > li.clearfix with a nested .list-content block, one canonical
 * /haberler/{section}/{slug}/ link, an explicit dd.mm.yyyy publication date,
 * summary paragraph and source image.
 */
class Sektorel_Content_Source_ISO_Adapter {

    const TIMEOUT       = 15;
    const MAX_BODY_SIZE = 2097152; // 2 MB.

    public static function fetch_items( $source_id, $limit ) {
        $source_id = absint( $source_id );
        $limit     = max( 1, min( 50, absint( $limit ) ) );
        $url       = trim( (string) get_post_meta( $source_id, 'feed_url', true ) );

        if ( ! $url ) {
            return new WP_Error( 'missing_listing_url', 'İSO haber liste URL alanı eksik.' );
        }
        if ( ! self::is_allowed_url( $url ) ) {
            return new WP_Error( 'unsafe_listing_url', 'İSO haber liste URL resmi host allowlist ile eşleşmiyor.' );
        }

        // Production diagnostics showed that ISO serves the current first-page
        // news list to the simple bot signature below, while the browser-like
        // Mozilla + Referer/Cache-Control request can return a stale archive
        // variant. Keep the request fingerprint aligned with the audited current
        // response and avoid adding headers that alter the upstream variant.
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => self::TIMEOUT,
                'redirection'         => 3,
                'limit_response_size' => self::MAX_BODY_SIZE,
                'user-agent'          => 'SektorelAjandaContentBot/1.2',
                'headers'             => array(
                    'Accept'          => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'tr-TR,tr;q=0.9',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'custom_source_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'custom_source_http_error', 'İSO haber listesi HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'custom_source_empty', 'İSO haber listesi boş yanıt döndürdü.' );
        }

        $items = self::parse_listing( $body, $url, $limit );
        if ( is_wp_error( $items ) ) {
            return $items;
        }

        return array(
            'items'       => $items,
            'http_status' => $status,
            'fetch_url'   => $url,
        );
    }

    private static function parse_listing( $html, $listing_url, $limit ) {
        $dom = self::load_dom( $html );
        if ( is_wp_error( $dom ) ) {
            return $dom;
        }

        $xpath = new DOMXPath( $dom );
        $cards = $xpath->query(
            '//*[@id="innerContent"]/ul[contains(concat(" ", normalize-space(@class), " "), " content-list ")]/li[contains(concat(" ", normalize-space(@class), " "), " clearfix ")]'
        );

        if ( ! $cards || ! $cards->length ) {
            return new WP_Error( 'custom_source_no_items', 'İSO güncel haber listesinde #innerContent > ul.content-list > li.clearfix kartı bulunamadı.' );
        }

        $items = array();

        foreach ( $cards as $card ) {
            if ( ! ( $card instanceof DOMElement ) ) {
                continue;
            }

            $content_nodes = $xpath->query(
                './/div[contains(concat(" ", normalize-space(@class), " "), " list-content ")]',
                $card
            );
            $content = $content_nodes && $content_nodes->length ? $content_nodes->item( 0 ) : null;
            if ( ! ( $content instanceof DOMElement ) ) {
                continue;
            }

            $heading_nodes = $xpath->query( './/h1|.//h2|.//h3|.//h4', $content );
            if ( ! $heading_nodes || ! $heading_nodes->length ) {
                continue;
            }
            $title = self::clean_text( $heading_nodes->item( 0 )->textContent, 1000 );

            $detail_url = '';
            $detail_path = '';
            $section = '';
            $slug = '';
            $anchors = $xpath->query( './/a[@href]', $content );
            if ( $anchors ) {
                foreach ( $anchors as $anchor ) {
                    if ( ! ( $anchor instanceof DOMElement ) ) {
                        continue;
                    }
                    $resolved = self::resolve_url( $anchor->getAttribute( 'href' ), $listing_url );
                    if ( ! $resolved || ! self::is_allowed_url( $resolved ) ) {
                        continue;
                    }
                    $path = (string) wp_parse_url( $resolved, PHP_URL_PATH );
                    if ( ! preg_match( '~^/haberler/([^/?#]+)/([^/?#]+)/?$~i', $path, $match ) ) {
                        continue;
                    }
                    $detail_url  = $resolved;
                    $detail_path = $path;
                    $section     = sanitize_title( $match[1] );
                    $slug        = sanitize_title( $match[2] );
                    break;
                }
            }

            $context   = self::clean_text( $content->textContent, 6000 );
            $date_raw  = self::extract_numeric_date( $context );
            $published = self::normalize_numeric_date( $date_raw );

            $summary = '';
            $paragraphs = $xpath->query( './/p', $content );
            if ( $paragraphs ) {
                foreach ( $paragraphs as $paragraph ) {
                    $candidate = self::clean_text( $paragraph->textContent, 5000 );
                    if ( mb_strlen( $candidate, 'UTF-8' ) > mb_strlen( $summary, 'UTF-8' ) ) {
                        $summary = $candidate;
                    }
                }
            }

            $image_url = '';
            $images = $xpath->query( './/figure//img[@src] | .//img[@src]', $card );
            if ( $images && $images->length ) {
                foreach ( $images as $image ) {
                    if ( ! ( $image instanceof DOMElement ) ) {
                        continue;
                    }
                    $resolved_image = self::resolve_url( $image->getAttribute( 'src' ), $listing_url );
                    if ( $resolved_image && self::is_allowed_url( $resolved_image ) ) {
                        $image_url = $resolved_image;
                        break;
                    }
                }
            }

            if (
                mb_strlen( $title, 'UTF-8' ) < 8 ||
                ! $detail_url ||
                ! $slug ||
                ! $date_raw ||
                ! $published ||
                mb_strlen( $summary, 'UTF-8' ) < 20 ||
                self::looks_mojibaked( $title ) ||
                self::looks_mojibaked( $summary )
            ) {
                continue;
            }

            $items[] = array(
                'source_item_key' => mb_substr( 'iso:' . $slug, 0, 191 ),
                'title'           => $title,
                'url'             => esc_url_raw( $detail_url ),
                'published_at'    => $published,
                'summary'         => $summary,
                'date_source'     => 'listing_numeric_date',
                'raw_payload'     => array(
                    'listing_url' => esc_url_raw( $listing_url ),
                    'detail_path' => $detail_path,
                    'section'     => $section,
                    'slug'        => $slug,
                    'title'       => $title,
                    'published'   => $date_raw,
                    'summary'     => $summary,
                    'lead_image'  => esc_url_raw( $image_url ),
                    'card_scope'  => '#innerContent > ul.content-list > li.clearfix > div.list-content',
                ),
            );
        }

        if ( ! $items ) {
            return new WP_Error( 'custom_source_no_items', 'İSO güncel haber listesinde tarih, özet ve canonical detay URL kontrolünü geçen güvenilir kayıt bulunamadı.' );
        }

        $deduped = array();
        foreach ( $items as $item ) {
            $key = (string) $item['source_item_key'];
            if ( ! isset( $deduped[ $key ] ) ) {
                $deduped[ $key ] = $item;
            }
        }
        $items = array_values( $deduped );

        usort( $items, static function( $a, $b ) {
            return strcmp( (string) $b['published_at'], (string) $a['published_at'] );
        } );

        return array_slice( $items, 0, $limit );
    }

    private static function load_dom( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'dom_unavailable', 'Sunucuda DOMDocument eklentisi bulunamadı.' );
        }

        $html = (string) $html;
        if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $html, 'UTF-8' ) ) {
            if ( ! function_exists( 'mb_detect_encoding' ) || ! function_exists( 'mb_convert_encoding' ) ) {
                return new WP_Error( 'invalid_source_encoding', 'İSO HTML UTF-8 olarak doğrulanamadı.' );
            }
            $detected = mb_detect_encoding( $html, array( 'Windows-1254', 'ISO-8859-9', 'ISO-8859-1' ), true );
            if ( ! $detected ) {
                return new WP_Error( 'invalid_source_encoding', 'İSO HTML encoding tespit edilemedi.' );
            }
            $html = mb_convert_encoding( $html, 'UTF-8', $detected );
        }

        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument( '1.0', 'UTF-8' );
        $loaded   = $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $loaded ? $dom : new WP_Error( 'invalid_source_html', 'İSO haber HTML ayrıştırılamadı.' );
    }

    private static function extract_numeric_date( $text ) {
        if ( preg_match( '/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\b/u', (string) $text, $match ) ) {
            return trim( $match[0] );
        }
        return '';
    }

    private static function normalize_numeric_date( $value ) {
        if ( ! preg_match( '/\b(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})\b/u', (string) $value, $match ) ) {
            return null;
        }
        $timestamp = gmmktime( 12, 0, 0, (int) $match[2], (int) $match[1], (int) $match[3] );
        return gmdate( 'Y-m-d H:i:s', $timestamp );
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
        if ( 0 === strpos( $url, '//' ) ) {
            return esc_url_raw( $base['scheme'] . ':' . $url );
        }

        $origin = $base['scheme'] . '://' . $base['host'];
        if ( 0 === strpos( $url, '/' ) ) {
            return esc_url_raw( $origin . $url );
        }

        $path = isset( $base['path'] ) ? dirname( $base['path'] ) : '/';
        $path = '/' === $path ? '' : rtrim( $path, '/' );
        return esc_url_raw( $origin . $path . '/' . ltrim( $url, '/' ) );
    }

    private static function is_allowed_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }
        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        return 'iso.org.tr' === $host;
    }

    private static function clean_text( $value, $max_length ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = trim( preg_replace( '/\s+/u', ' ', $value ) );
        return mb_substr( $value, 0, max( 1, absint( $max_length ) ) );
    }

    private static function looks_mojibaked( $text ) {
        foreach ( array(
            'Ã¼', 'Ãœ', 'Ã¶', 'Ã–', 'Ã§', 'Ã‡',
            'Ä°', 'Ä±', 'ÄŸ', 'Äž', 'ÅŸ', 'Åž',
            'â€™', 'â€œ', 'â€', 'â€“', 'â€”', 'Â ',
        ) as $marker ) {
            if ( false !== strpos( (string) $text, $marker ) ) {
                return true;
            }
        }
        return false;
    }
}

add_filter( 'sektorel_content_detail_allowed_hosts', static function( $hosts, $source_key ) {
    if ( 'iso_news' !== sanitize_key( $source_key ) ) {
        return $hosts;
    }
    return array_values( array_unique( array_merge( (array) $hosts, array( 'iso.org.tr', 'www.iso.org.tr' ) ) ) );
}, 20, 2 );