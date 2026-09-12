<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic adapter for Ticaret Bakanlığı İhracat Genel Müdürlüğü news.
 *
 * The audited listing is hosted on ihracat.ticaret.gov.tr and exposes curated
 * export/trade cards in ul.dizin-content > li. Most current cards point to the
 * canonical Ticaret Bakanlığı detail host (ticaret.gov.tr), while a minority use
 * relative /haberler/... links that would otherwise resolve back to the listing
 * subdomain. This adapter canonicalizes every accepted detail URL to the main
 * Ministry host and never treats the listing subdomain as the article detail.
 *
 * Detail pages expose a stable div.__header.with-image with the full headline
 * and visible Turkish publication date, followed by div.__content inside the
 * same div.__zone. Publication dates are taken only from the visible detail
 * header; title month/year and URL suffixes are never used as date evidence.
 */
class Sektorel_Content_Source_Ticaret_Ihracat_Adapter {

    const TIMEOUT       = 15;
    const MAX_BODY_SIZE = 1572864; // 1.5 MB.

    public static function fetch_items( $source_id, $limit ) {
        $source_id = absint( $source_id );
        $limit     = max( 1, min( 20, absint( $limit ) ) );
        $url       = trim( (string) get_post_meta( $source_id, 'feed_url', true ) );

        if ( ! $url ) {
            return new WP_Error( 'missing_listing_url', 'Ticaret Bakanlığı ihracat haber liste URL alanı eksik.' );
        }
        if ( ! self::is_listing_url( $url ) ) {
            return new WP_Error( 'unsafe_listing_url', 'İhracat haber liste URL resmi host allowlist ile eşleşmiyor.' );
        }

        $response = wp_safe_remote_get( $url, self::request_args( 2097152 ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'custom_source_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'custom_source_http_error', 'İhracat haber listesi HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'custom_source_empty', 'İhracat haber listesi boş yanıt döndürdü.' );
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
            '//ul[contains(concat(" ", normalize-space(@class), " "), " dizin-content ")]/li'
        );

        if ( ! $cards || ! $cards->length ) {
            return new WP_Error( 'custom_source_no_items', 'İhracat haber listesinde dizin-content haber kartı bulunamadı.' );
        }

        $items           = array();
        $seen            = array();
        $detail_attempts = 0;
        $detail_cap      = min( 30, max( 14, $limit + 8 ) );
        $current_year    = (int) current_time( 'Y' );

        foreach ( $cards as $card ) {
            if ( count( $items ) >= $limit || $detail_attempts >= $detail_cap ) {
                break;
            }
            if ( ! ( $card instanceof DOMElement ) ) {
                continue;
            }

            $listing_title   = self::listing_title( $xpath, $card );
            $listing_summary = self::listing_summary( $xpath, $card );
            $signal          = self::trade_signal( $listing_title . ' ' . $listing_summary );

            if (
                mb_strlen( $listing_title, 'UTF-8' ) < 8 ||
                ! $signal ||
                self::looks_mojibaked( $listing_title ) ||
                self::looks_mojibaked( $listing_summary )
            ) {
                continue;
            }

            $detail_url = self::detail_url_from_card( $xpath, $card );
            if ( ! $detail_url || isset( $seen[ $detail_url ] ) ) {
                continue;
            }
            $seen[ $detail_url ] = true;

            $listing_image = self::first_image_url( $xpath, $card, $listing_url );

            $detail_attempts++;
            $detail = self::fetch_detail( $detail_url );
            if ( is_wp_error( $detail ) ) {
                continue;
            }

            $title     = (string) $detail['title'];
            $summary   = (string) $detail['summary'];
            $published = (string) $detail['published_at'];
            $date_raw  = (string) $detail['date_raw'];
            $signal    = self::trade_signal( $title . ' ' . $summary );
            $image     = $listing_image ?: (string) $detail['image'];

            if (
                mb_strlen( $title, 'UTF-8' ) < 8 ||
                mb_strlen( $summary, 'UTF-8' ) < 80 ||
                ! $published ||
                ! $signal ||
                self::looks_mojibaked( $title ) ||
                self::looks_mojibaked( $summary )
            ) {
                continue;
            }

            // Source Coverage scans intentionally ingest only the current
            // publication year. The listing carries a long historical backlog;
            // never let a normal scan create stale candidates from prior years.
            if ( (int) substr( $published, 0, 4 ) !== $current_year ) {
                continue;
            }

            $path = (string) wp_parse_url( $detail_url, PHP_URL_PATH );
            if ( ! preg_match( '~^/haberler/([^/?#]+)/?$~i', $path, $match ) ) {
                continue;
            }

            $identity = self::source_identity( $match[1] );
            if ( ! $identity ) {
                continue;
            }

            $items[] = array(
                'source_item_key' => mb_substr( 'ticaret-ihracat:' . $identity, 0, 191 ),
                'title'           => $title,
                'url'             => esc_url_raw( $detail_url ),
                'published_at'    => $published,
                'summary'         => $summary,
                'date_source'     => 'detail_visible_date',
                'raw_payload'     => array(
                    'listing_url'       => esc_url_raw( $listing_url ),
                    'detail_path'       => $path,
                    'identity'          => $identity,
                    'listing_title'     => $listing_title,
                    'listing_summary'   => $listing_summary,
                    'listing_image'     => esc_url_raw( $listing_image ),
                    'title'             => $title,
                    'published'         => $date_raw,
                    'summary'           => $summary,
                    'lead_image'        => esc_url_raw( $image ),
                    'topic_signal'      => $signal,
                    'card_scope'        => 'ul.dizin-content > li',
                    'detail_header'     => 'div.__header.with-image',
                    'detail_scope'      => 'div.__zone div.__content',
                    'detail_host'       => 'ticaret.gov.tr',
                    'current_year_gate' => $current_year,
                    'listing_date_used' => false,
                    'url_date_used'     => false,
                ),
            );
        }

        if ( ! $items ) {
            return new WP_Error(
                'custom_source_no_items',
                'İhracat haber listesinde current-year, canonical detail, görünür tarih ve dış ticaret konu kontrolünü geçen güvenilir kayıt bulunamadı.'
            );
        }

        usort( $items, static function( $a, $b ) {
            return strcmp( (string) $b['published_at'], (string) $a['published_at'] );
        } );

        return array_slice( $items, 0, $limit );
    }

    private static function fetch_detail( $url ) {
        if ( ! self::is_detail_url( $url ) ) {
            return new WP_Error( 'unsafe_detail_url', 'Ticaret Bakanlığı detail URL resmi host allowlist ile eşleşmiyor.' );
        }

        $response = wp_safe_remote_get( $url, self::request_args( self::MAX_BODY_SIZE ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'detail_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 || '' === trim( $body ) ) {
            return new WP_Error( 'detail_http_error', 'Ticaret Bakanlığı detail sayfası güvenilir HTML döndürmedi.' );
        }

        $dom = self::load_dom( $body );
        if ( is_wp_error( $dom ) ) {
            return $dom;
        }

        $xpath = new DOMXPath( $dom );
        $headers = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " __header ") and contains(concat(" ", normalize-space(@class), " "), " with-image ")]'
        );

        if ( ! $headers || ! $headers->length || ! ( $headers->item( 0 ) instanceof DOMElement ) ) {
            return new WP_Error( 'detail_scope_missing', 'Ticaret Bakanlığı detail sayfasında __header.with-image bulunamadı.' );
        }

        $header = $headers->item( 0 );
        $title  = self::detail_title( $xpath, $header );
        $date   = self::extract_turkish_date( self::clean_text( $header->textContent, 1200 ) );
        $published = self::normalize_turkish_date( $date );

        $zone = $header->parentNode;
        if ( ! ( $zone instanceof DOMElement ) || ! self::has_class( $zone, '__zone' ) ) {
            return new WP_Error( 'detail_zone_missing', 'Ticaret Bakanlığı detail sayfasında header ile aynı __zone bulunamadı.' );
        }

        $content_nodes = $xpath->query(
            './/div[contains(concat(" ", normalize-space(@class), " "), " __content ")]',
            $zone
        );
        if ( ! $content_nodes || ! $content_nodes->length || ! ( $content_nodes->item( 0 ) instanceof DOMElement ) ) {
            return new WP_Error( 'detail_content_missing', 'Ticaret Bakanlığı detail sayfasında haber __content gövdesi bulunamadı.' );
        }

        $content = $content_nodes->item( 0 );
        $summary = self::clean_text( $content->textContent, 4000 );
        $image   = self::first_image_url( $xpath, $zone, $url );

        if (
            mb_strlen( $title, 'UTF-8' ) < 8 ||
            ! $date ||
            ! $published ||
            mb_strlen( $summary, 'UTF-8' ) < 80
        ) {
            return new WP_Error( 'detail_incomplete', 'Ticaret Bakanlığı detail sayfasında başlık, görünür tarih veya haber gövdesi eksik.' );
        }

        return array(
            'title'        => $title,
            'date_raw'     => $date,
            'published_at' => $published,
            'summary'      => $summary,
            'image'        => $image,
        );
    }

    private static function detail_url_from_card( DOMXPath $xpath, DOMElement $card ) {
        $anchors = $xpath->query( './/a[@href]', $card );
        if ( ! $anchors ) {
            return '';
        }

        foreach ( $anchors as $anchor ) {
            if ( ! ( $anchor instanceof DOMElement ) ) {
                continue;
            }

            $raw = html_entity_decode( trim( (string) $anchor->getAttribute( 'href' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( ! $raw || 0 === strpos( $raw, '#' ) || 0 === stripos( $raw, 'javascript:' ) ) {
                continue;
            }

            $path = '';
            if ( 0 === strpos( $raw, '/' ) ) {
                $path = (string) wp_parse_url( $raw, PHP_URL_PATH );
            } elseif ( preg_match( '#^https?://#i', $raw ) ) {
                $parts = wp_parse_url( $raw );
                if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
                    continue;
                }
                $host = self::canonical_host( (string) $parts['host'] );
                if ( ! in_array( $host, array( 'ticaret.gov.tr', 'ihracat.ticaret.gov.tr' ), true ) ) {
                    continue;
                }
                $path = (string) ( $parts['path'] ?? '' );
            }

            if ( ! preg_match( '~^/haberler/[^/?#]+/?$~i', $path ) ) {
                continue;
            }

            return esc_url_raw( 'https://www.ticaret.gov.tr' . rtrim( $path, '/' ) );
        }

        return '';
    }

    private static function listing_title( DOMXPath $xpath, DOMElement $card ) {
        $heads = $xpath->query( './/h1|.//h2|.//h3|.//h4|.//h5|.//h6', $card );
        if ( ! $heads ) {
            return '';
        }
        foreach ( $heads as $head ) {
            $title = self::clean_text( $head->textContent, 1000 );
            if ( mb_strlen( $title, 'UTF-8' ) >= 8 ) {
                return $title;
            }
        }
        return '';
    }

    private static function listing_summary( DOMXPath $xpath, DOMElement $card ) {
        $paragraphs = $xpath->query( './/p[normalize-space()]', $card );
        if ( ! $paragraphs ) {
            return '';
        }
        foreach ( $paragraphs as $paragraph ) {
            $text = self::clean_text( $paragraph->textContent, 1200 );
            if ( mb_strlen( $text, 'UTF-8' ) >= 20 ) {
                return $text;
            }
        }
        return '';
    }

    private static function detail_title( DOMXPath $xpath, DOMElement $header ) {
        $heads = $xpath->query( './/h2[normalize-space()] | .//h1[normalize-space()]', $header );
        if ( ! $heads || ! $heads->length ) {
            return '';
        }

        $title = self::clean_text( $heads->item( 0 )->textContent, 1000 );
        $title = preg_replace( '/\s*[-–—]?\s*\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4}\s*$/u', '', $title );
        return trim( (string) $title );
    }

    private static function first_image_url( DOMXPath $xpath, DOMElement $scope, $base_url ) {
        $images = $xpath->query( './/img[@src or @data-src]', $scope );
        if ( ! $images ) {
            return '';
        }

        foreach ( $images as $image ) {
            if ( ! ( $image instanceof DOMElement ) ) {
                continue;
            }
            $src = trim( (string) $image->getAttribute( 'src' ) );
            if ( ! $src ) {
                $src = trim( (string) $image->getAttribute( 'data-src' ) );
            }
            $resolved = self::resolve_url( $src, $base_url );
            if ( $resolved && self::is_image_url( $resolved ) ) {
                return $resolved;
            }
        }

        return '';
    }

    private static function trade_signal( $text ) {
        $text = self::normalize_match_text( $text );
        $signals = array(
            'dis ticaret',
            'e ihracat',
            'ihracat',
            'ithalat',
            'ihracatci',
            'ticaret heyeti',
            'alim heyeti',
            'serbest ticaret',
            'ticaret anlas',
            'dis pazar',
            'gumruk',
            'ticari iliski',
        );

        foreach ( $signals as $signal ) {
            if ( false !== strpos( $text, $signal ) ) {
                return $signal;
            }
        }

        return '';
    }

    private static function source_identity( $slug ) {
        $slug = sanitize_key( trim( (string) $slug ) );
        if ( ! $slug ) {
            return '';
        }
        if ( strlen( $slug ) <= 150 ) {
            return $slug;
        }
        return substr( $slug, 0, 125 ) . '-' . substr( sha1( $slug ), 0, 20 );
    }

    private static function extract_turkish_date( $text ) {
        if ( preg_match(
            '~\b(\d{1,2})\s+(Ocak|Şubat|Subat|Mart|Nisan|Mayıs|Mayis|Haziran|Temmuz|Ağustos|Agustos|Eylül|Eylul|Ekim|Kasım|Kasim|Aralık|Aralik)\s+(\d{4})\b~iu',
            (string) $text,
            $match
        ) ) {
            return $match[1] . ' ' . $match[2] . ' ' . $match[3];
        }
        return '';
    }

    private static function normalize_turkish_date( $value ) {
        if ( ! preg_match( '~^(\d{1,2})\s+([^\s]+)\s+(\d{4})$~u', trim( (string) $value ), $match ) ) {
            return null;
        }

        $month_name = mb_strtolower( remove_accents( $match[2] ), 'UTF-8' );
        $months = array(
            'ocak'    => 1,
            'subat'   => 2,
            'mart'    => 3,
            'nisan'   => 4,
            'mayis'   => 5,
            'haziran' => 6,
            'temmuz'  => 7,
            'agustos' => 8,
            'eylul'   => 9,
            'ekim'    => 10,
            'kasim'   => 11,
            'aralik'  => 12,
        );

        if ( ! isset( $months[ $month_name ] ) ) {
            return null;
        }

        $day   = (int) $match[1];
        $month = (int) $months[ $month_name ];
        $year  = (int) $match[3];
        if ( ! checkdate( $month, $day, $year ) ) {
            return null;
        }

        return gmdate( 'Y-m-d H:i:s', gmmktime( 12, 0, 0, $month, $day, $year ) );
    }

    private static function request_args( $limit_response_size ) {
        return array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => absint( $limit_response_size ),
            'user-agent'          => 'SektorelAjandaContentBot/1.2',
            'headers'             => array(
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'tr-TR,tr;q=0.9',
            ),
        );
    }

    private static function load_dom( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'dom_unavailable', 'Sunucuda DOMDocument eklentisi bulunamadı.' );
        }

        $html = (string) $html;
        if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $html, 'UTF-8' ) ) {
            return new WP_Error( 'invalid_source_encoding', 'Ticaret Bakanlığı HTML UTF-8 olarak doğrulanamadı.' );
        }

        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument( '1.0', 'UTF-8' );
        $loaded   = $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $loaded ? $dom : new WP_Error( 'invalid_source_html', 'Ticaret Bakanlığı HTML ayrıştırılamadı.' );
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

    private static function is_listing_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        return 'https' === strtolower( (string) $parts['scheme'] ) && 'ihracat.ticaret.gov.tr' === $host;
    }

    private static function is_detail_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        $host = self::canonical_host( (string) $parts['host'] );
        $path = (string) ( $parts['path'] ?? '' );
        return in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) &&
            'ticaret.gov.tr' === $host &&
            (bool) preg_match( '~^/haberler/[^/?#]+/?$~i', $path );
    }

    private static function is_image_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        return in_array( $host, array( 'ticaret.gov.tr', 'ihracat.ticaret.gov.tr' ), true );
    }

    private static function canonical_host( $host ) {
        $host = strtolower( rtrim( (string) $host, '.' ) );
        return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
    }

    private static function has_class( DOMElement $node, $class ) {
        $classes = preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) );
        return in_array( $class, is_array( $classes ) ? $classes : array(), true );
    }

    private static function normalize_match_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = mb_strtolower( remove_accents( $text ), 'UTF-8' );
        $text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
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
    if ( 'ticaret_ihracat_news' !== sanitize_key( $source_key ) ) {
        return $hosts;
    }
    return array_values( array_unique( array_merge(
        (array) $hosts,
        array( 'ticaret.gov.tr', 'www.ticaret.gov.tr', 'ihracat.ticaret.gov.tr' )
    ) ) );
}, 20, 2 );
