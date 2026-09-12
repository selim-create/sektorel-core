<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic first-page adapter for BTK news.
 *
 * The audited listing exposes current news as canonical /haberler/{slug} links
 * inside a.group cards. Dates use Turkish month names. Most grid cards do not
 * carry a usable summary, so the adapter reads the canonical detail page and
 * extracts the first meaningful article paragraph. A narrow deterministic
 * technology-topic gate prevents institutional housekeeping news from being
 * forced into the Teknoloji & Dijital Dönüşüm desk.
 */
class Sektorel_Content_Source_BTK_Adapter {

    const TIMEOUT          = 15;
    const DETAIL_TIMEOUT   = 12;
    const MAX_BODY_SIZE    = 2097152; // 2 MB.
    const DETAIL_BODY_SIZE = 1572864; // 1.5 MB.

    public static function fetch_items( $source_id, $limit ) {
        $source_id = absint( $source_id );
        $limit     = max( 1, min( 20, absint( $limit ) ) );
        $url       = trim( (string) get_post_meta( $source_id, 'feed_url', true ) );

        if ( ! $url ) {
            return new WP_Error( 'missing_listing_url', 'BTK haber liste URL alanı eksik.' );
        }
        if ( ! self::is_allowed_url( $url ) ) {
            return new WP_Error( 'unsafe_listing_url', 'BTK haber liste URL resmi host allowlist ile eşleşmiyor.' );
        }

        $response = wp_safe_remote_get( $url, self::request_args( self::TIMEOUT, self::MAX_BODY_SIZE ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'custom_source_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'custom_source_http_error', 'BTK haber listesi HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'custom_source_empty', 'BTK haber listesi boş yanıt döndürdü.' );
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
            '//a[@href and contains(concat(" ", normalize-space(@class), " "), " group ")]'
        );

        if ( ! $cards || ! $cards->length ) {
            return new WP_Error( 'custom_source_no_items', 'BTK haber listesinde a.group haber kartı bulunamadı.' );
        }

        $items = array();
        foreach ( $cards as $card ) {
            if ( ! ( $card instanceof DOMElement ) ) {
                continue;
            }

            $detail_url = self::resolve_url( $card->getAttribute( 'href' ), $listing_url );
            if ( ! $detail_url || ! self::is_allowed_url( $detail_url ) ) {
                continue;
            }

            $detail_path = (string) wp_parse_url( $detail_url, PHP_URL_PATH );
            if ( ! preg_match( '~^/haberler/([^/?#]+)/?$~i', $detail_path, $match ) ) {
                continue;
            }
            $slug = sanitize_title( $match[1] );
            if ( ! $slug ) {
                continue;
            }

            $heading_nodes = $xpath->query( './/h1|.//h2|.//h3|.//h4|.//h5|.//h6', $card );
            if ( ! $heading_nodes || ! $heading_nodes->length ) {
                continue;
            }
            $title = self::clean_text( $heading_nodes->item( 0 )->textContent, 1000 );

            $card_text  = self::clean_text( $card->textContent, 5000 );
            $date_raw   = self::extract_turkish_date( $card_text );
            $published  = self::normalize_turkish_date( $date_raw );
            $summary    = '';
            $paragraphs = $xpath->query( './/p', $card );
            if ( $paragraphs ) {
                foreach ( $paragraphs as $paragraph ) {
                    $candidate = self::clean_text( $paragraph->textContent, 3000 );
                    if ( mb_strlen( $candidate, 'UTF-8' ) >= 40 ) {
                        $summary = $candidate;
                        break;
                    }
                }
            }

            $image_url = self::first_image_url( $xpath, $card, $listing_url );
            $detail    = array();
            if ( mb_strlen( $summary, 'UTF-8' ) < 40 ) {
                $detail = self::fetch_detail_excerpt( $detail_url, $title );
                if ( is_wp_error( $detail ) ) {
                    continue;
                }
                $summary = (string) ( $detail['summary'] ?? '' );
            }
            if ( ! $image_url && ! empty( $detail['image'] ) ) {
                $image_url = (string) $detail['image'];
            }

            $topic_signal = self::technology_signal( $title, $summary );
            if (
                mb_strlen( $title, 'UTF-8' ) < 8 ||
                ! $date_raw ||
                ! $published ||
                mb_strlen( $summary, 'UTF-8' ) < 40 ||
                ! $topic_signal ||
                self::looks_mojibaked( $title ) ||
                self::looks_mojibaked( $summary )
            ) {
                continue;
            }

            $items[] = array(
                'source_item_key' => mb_substr( 'btk-news:' . $slug, 0, 191 ),
                'title'           => $title,
                'url'             => esc_url_raw( $detail_url ),
                'published_at'    => $published,
                'summary'         => $summary,
                'date_source'     => 'listing_turkish_date',
                'raw_payload'     => array(
                    'listing_url'  => esc_url_raw( $listing_url ),
                    'detail_path'  => $detail_path,
                    'slug'         => $slug,
                    'title'        => $title,
                    'published'    => $date_raw,
                    'summary'      => $summary,
                    'lead_image'   => esc_url_raw( $image_url ),
                    'topic_signal' => $topic_signal,
                    'card_scope'   => 'a.group[href^="/haberler/"]',
                ),
            );
        }

        if ( ! $items ) {
            return new WP_Error(
                'custom_source_no_items',
                'BTK haber listesinde tarih, detay özeti ve teknoloji konu kontrolünü geçen güvenilir kayıt bulunamadı.'
            );
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

    private static function fetch_detail_excerpt( $url, $listing_title ) {
        $response = wp_safe_remote_get( $url, self::request_args( self::DETAIL_TIMEOUT, self::DETAIL_BODY_SIZE ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'btk_detail_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 || '' === trim( $body ) ) {
            return new WP_Error( 'btk_detail_http_error', 'BTK detay sayfası okunamadı.' );
        }

        $dom = self::load_dom( $body );
        if ( is_wp_error( $dom ) ) {
            return $dom;
        }
        $xpath = new DOMXPath( $dom );

        $heading = null;
        $headings = $xpath->query( '//h1[normalize-space()]' );
        if ( $headings ) {
            foreach ( $headings as $candidate ) {
                $candidate_title = self::clean_text( $candidate->textContent, 1000 );
                if ( self::titles_match( $candidate_title, $listing_title ) ) {
                    $heading = $candidate;
                    break;
                }
                if ( null === $heading ) {
                    $heading = $candidate;
                }
            }
        }

        $summary = '';
        $image   = '';
        if ( $heading instanceof DOMElement ) {
            $node = $heading->parentNode;
            for ( $depth = 0; $depth < 7 && $node; $depth++, $node = $node->parentNode ) {
                if ( ! ( $node instanceof DOMElement ) ) {
                    continue;
                }
                $paragraphs = $xpath->query( './/p', $node );
                if ( $paragraphs ) {
                    foreach ( $paragraphs as $paragraph ) {
                        $candidate = self::clean_text( $paragraph->textContent, 3000 );
                        if ( self::meaningful_paragraph( $candidate ) ) {
                            $summary = $candidate;
                            break 2;
                        }
                    }
                }
            }
        }

        if ( mb_strlen( $summary, 'UTF-8' ) < 40 ) {
            $paragraphs = $xpath->query( '//main//p | //article//p' );
            if ( $paragraphs ) {
                foreach ( $paragraphs as $paragraph ) {
                    $candidate = self::clean_text( $paragraph->textContent, 3000 );
                    if ( self::meaningful_paragraph( $candidate ) ) {
                        $summary = $candidate;
                        break;
                    }
                }
            }
        }

        if ( mb_strlen( $summary, 'UTF-8' ) < 40 ) {
            foreach ( array(
                '//meta[@property="og:description"]/@content',
                '//meta[@name="description"]/@content',
            ) as $query ) {
                $nodes = $xpath->query( $query );
                if ( $nodes && $nodes->length ) {
                    $candidate = self::clean_text( $nodes->item( 0 )->nodeValue, 3000 );
                    if ( self::meaningful_paragraph( $candidate ) ) {
                        $summary = $candidate;
                        break;
                    }
                }
            }
        }

        foreach ( array(
            '//meta[@property="og:image"]/@content',
            '//main//img[@src][1]/@src',
            '//article//img[@src][1]/@src',
        ) as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes || ! $nodes->length ) {
                continue;
            }
            $candidate = self::resolve_url( $nodes->item( 0 )->nodeValue, $url );
            if ( $candidate && self::is_allowed_url( $candidate ) ) {
                $image = $candidate;
                break;
            }
        }

        if ( mb_strlen( $summary, 'UTF-8' ) < 40 || self::looks_mojibaked( $summary ) ) {
            return new WP_Error( 'btk_detail_no_summary', 'BTK detay sayfasında güvenilir özet paragrafı bulunamadı.' );
        }

        return array(
            'summary' => $summary,
            'image'   => $image,
        );
    }

    private static function request_args( $timeout, $max_body_size ) {
        return array(
            'timeout'             => max( 5, absint( $timeout ) ),
            'redirection'         => 3,
            'limit_response_size' => max( 262144, absint( $max_body_size ) ),
            'user-agent'          => 'SektorelAjandaContentBot/1.2',
            'headers'             => array(
                'Accept'          => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'tr-TR,tr;q=0.9',
            ),
        );
    }

    private static function extract_turkish_date( $text ) {
        $months = 'Ocak|Şubat|Subat|Mart|Nisan|Mayıs|Mayis|Haziran|Temmuz|Ağustos|Agustos|Eylül|Eylul|Ekim|Kasım|Kasim|Aralık|Aralik';
        if ( preg_match( '~(\d{1,2})\s*(' . $months . ')\s*(\d{4})~iu', (string) $text, $match ) ) {
            return trim( $match[1] . ' ' . $match[2] . ' ' . $match[3] );
        }
        return '';
    }

    private static function normalize_turkish_date( $value ) {
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
        if ( ! preg_match( '~^(\d{1,2})\s+([^\s]+)\s+(\d{4})$~u', trim( (string) $value ), $match ) ) {
            return null;
        }
        $month_key = strtolower( remove_accents( $match[2] ) );
        $day       = (int) $match[1];
        $month     = isset( $months[ $month_key ] ) ? (int) $months[ $month_key ] : 0;
        $year      = (int) $match[3];
        if ( ! $month || ! checkdate( $month, $day, $year ) ) {
            return null;
        }
        return gmdate( 'Y-m-d H:i:s', gmmktime( 12, 0, 0, $month, $day, $year ) );
    }

    private static function technology_signal( $title, $summary ) {
        $text = self::normalize_match_text( $title . ' ' . $summary );
        $signals = array(
            'dijital donusum',
            'dijital yonetisim',
            'dijital dunya',
            'dijital cag',
            'dijital avrupa',
            'dijital ekosistem',
            'yapay zeka',
            'ai tomorrow',
            'siber guvenlik',
            'guvenli internet',
            'elektronik haberlesme',
            'veri merkezi',
            'nesnelerin interneti',
            'fiber',
            'telekom',
            'iot',
            '5g',
            '6g',
        );
        foreach ( $signals as $signal ) {
            if ( false !== strpos( $text, $signal ) ) {
                return $signal;
            }
        }
        return '';
    }

    private static function normalize_match_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = mb_strtolower( remove_accents( $text ), 'UTF-8' );
        $text = preg_replace( '/[^a-z0-9]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    private static function meaningful_paragraph( $text ) {
        $text = trim( (string) $text );
        if ( mb_strlen( $text, 'UTF-8' ) < 40 ) {
            return false;
        }
        $normalized = self::normalize_match_text( $text );
        foreach ( array( 'paylas', 'bilgi teknolojileri ve iletisim kurumu eskisehir yolu', 'btk tuketici iletisim merkezi' ) as $noise ) {
            if ( 0 === strpos( $normalized, $noise ) ) {
                return false;
            }
        }
        return true;
    }

    private static function titles_match( $a, $b ) {
        $a = self::normalize_match_text( $a );
        $b = self::normalize_match_text( $b );
        return $a && $b && ( $a === $b || false !== strpos( $a, $b ) || false !== strpos( $b, $a ) );
    }

    private static function first_image_url( DOMXPath $xpath, DOMElement $node, $base_url ) {
        $images = $xpath->query( './/img[@src]', $node );
        if ( ! $images ) {
            return '';
        }
        foreach ( $images as $image ) {
            if ( ! ( $image instanceof DOMElement ) ) {
                continue;
            }
            $resolved = self::resolve_url( $image->getAttribute( 'src' ), $base_url );
            if ( $resolved && self::is_allowed_url( $resolved ) ) {
                return $resolved;
            }
        }
        return '';
    }

    private static function load_dom( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'dom_unavailable', 'Sunucuda DOMDocument eklentisi bulunamadı.' );
        }
        $html = (string) $html;
        if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $html, 'UTF-8' ) ) {
            return new WP_Error( 'invalid_source_encoding', 'BTK HTML UTF-8 olarak doğrulanamadı.' );
        }
        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument( '1.0', 'UTF-8' );
        $loaded   = $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        return $loaded ? $dom : new WP_Error( 'invalid_source_html', 'BTK HTML ayrıştırılamadı.' );
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
        return 'btk.gov.tr' === $host;
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
    if ( 'btk_news' !== sanitize_key( $source_key ) ) {
        return $hosts;
    }
    return array_values( array_unique( array_merge( (array) $hosts, array( 'btk.gov.tr', 'www.btk.gov.tr' ) ) ) );
}, 20, 2 );
