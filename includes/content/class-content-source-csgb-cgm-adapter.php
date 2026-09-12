<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic adapter for Çalışma Genel Müdürlüğü (ÇGM) news.
 *
 * The audited listing exposes all news cards in div.card.card-news. Each card
 * carries a visible Turkish publication date, a title, an image and a canonical
 * /cgm/haberler/{ddmmyyyy[-n]}/ detail URL. Detail pages expose a stable
 * section.content.news-item with the full h2.page-title, exact visible date,
 * div.editor-content paragraphs and source images.
 *
 * The stream is broader than the İnsan & Yönetim desk, therefore a narrow title
 * signal gate is applied before any detail request. Publication dates are never
 * inferred from the canonical URL because production audit found at least one
 * official URL whose path year differs from the visible 2026 publication date.
 */
class Sektorel_Content_Source_CSGM_Adapter {

    const TIMEOUT       = 15;
    const MAX_BODY_SIZE = 1572864; // 1.5 MB.

    public static function fetch_items( $source_id, $limit ) {
        $source_id = absint( $source_id );
        $limit     = max( 1, min( 20, absint( $limit ) ) );
        $url       = trim( (string) get_post_meta( $source_id, 'feed_url', true ) );

        if ( ! $url ) {
            return new WP_Error( 'missing_listing_url', 'ÇGM haber liste URL alanı eksik.' );
        }
        if ( ! self::is_allowed_url( $url ) ) {
            return new WP_Error( 'unsafe_listing_url', 'ÇGM haber liste URL resmi host allowlist ile eşleşmiyor.' );
        }

        $response = wp_safe_remote_get( $url, self::request_args( 2097152 ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'custom_source_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'custom_source_http_error', 'ÇGM haber listesi HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'custom_source_empty', 'ÇGM haber listesi boş yanıt döndürdü.' );
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
            '//*[contains(concat(" ", normalize-space(@class), " "), " card ") and contains(concat(" ", normalize-space(@class), " "), " card-news ")]'
        );

        if ( ! $cards || ! $cards->length ) {
            return new WP_Error( 'custom_source_no_items', 'ÇGM haber listesinde card-news haber kartı bulunamadı.' );
        }

        $items          = array();
        $seen           = array();
        $detail_attempts = 0;
        $detail_cap     = min( 40, max( 20, $limit * 2 ) );

        foreach ( $cards as $card ) {
            if ( count( $items ) >= $limit || $detail_attempts >= $detail_cap ) {
                break;
            }
            if ( ! ( $card instanceof DOMElement ) ) {
                continue;
            }

            $detail_url = self::detail_url_from_card( $xpath, $card, $listing_url );
            if ( ! $detail_url || isset( $seen[ $detail_url ] ) ) {
                continue;
            }
            $seen[ $detail_url ] = true;

            $context   = self::clean_text( $card->textContent, 3000 );
            $date_raw  = self::extract_turkish_date( $context );
            $published = self::normalize_turkish_date( $date_raw );
            $list_title = self::listing_title( $xpath, $card );
            $signal    = self::people_signal( $list_title );

            if (
                ! $date_raw ||
                ! $published ||
                mb_strlen( $list_title, 'UTF-8' ) < 8 ||
                ! $signal ||
                self::is_broad_stream_item( $list_title ) ||
                self::looks_mojibaked( $list_title )
            ) {
                continue;
            }

            $detail_attempts++;
            $detail = self::fetch_detail( $detail_url );
            if ( is_wp_error( $detail ) ) {
                continue;
            }

            if ( $detail['published_at'] !== $published ) {
                continue;
            }

            $title   = (string) $detail['title'];
            $summary = (string) $detail['summary'];
            $image   = (string) $detail['image'];
            $signal  = self::people_signal( $title . ' ' . $summary );

            if (
                mb_strlen( $title, 'UTF-8' ) < 8 ||
                mb_strlen( $summary, 'UTF-8' ) < 60 ||
                ! $signal ||
                self::is_broad_stream_item( $title ) ||
                self::looks_mojibaked( $title ) ||
                self::looks_mojibaked( $summary )
            ) {
                continue;
            }

            $path = (string) wp_parse_url( $detail_url, PHP_URL_PATH );
            if ( ! preg_match( '~^/cgm/haberler/([0-9]{8}(?:-[0-9]+)?)/?$~i', $path, $match ) ) {
                continue;
            }

            $identity = sanitize_key( $match[1] );
            if ( ! $identity ) {
                continue;
            }

            $items[] = array(
                'source_item_key' => mb_substr( 'csgb-cgm:' . $identity, 0, 191 ),
                'title'           => $title,
                'url'             => esc_url_raw( $detail_url ),
                'published_at'    => $published,
                'summary'         => $summary,
                'date_source'     => 'listing_turkish_date',
                'raw_payload'     => array(
                    'listing_url'       => esc_url_raw( $listing_url ),
                    'detail_path'       => $path,
                    'identity'          => $identity,
                    'listing_title'     => $list_title,
                    'title'             => $title,
                    'published'         => $date_raw,
                    'summary'           => $summary,
                    'lead_image'        => esc_url_raw( $image ),
                    'topic_signal'      => $signal,
                    'card_scope'        => 'div.card.card-news',
                    'detail_scope'      => 'section.content.news-item div.editor-content',
                    'url_date_ignored'  => true,
                ),
            );
        }

        if ( ! $items ) {
            return new WP_Error(
                'custom_source_no_items',
                'ÇGM haber listesinde tarih, detail doğrulaması ve İnsan & Yönetim konu kontrolünü geçen güvenilir kayıt bulunamadı.'
            );
        }

        usort( $items, static function( $a, $b ) {
            return strcmp( (string) $b['published_at'], (string) $a['published_at'] );
        } );

        return array_slice( $items, 0, $limit );
    }

    private static function fetch_detail( $url ) {
        if ( ! self::is_allowed_url( $url ) ) {
            return new WP_Error( 'unsafe_detail_url', 'ÇGM detail URL resmi host allowlist ile eşleşmiyor.' );
        }

        $response = wp_safe_remote_get( $url, self::request_args( self::MAX_BODY_SIZE ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'detail_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 || '' === trim( $body ) ) {
            return new WP_Error( 'detail_http_error', 'ÇGM detail sayfası güvenilir HTML döndürmedi.' );
        }

        $dom = self::load_dom( $body );
        if ( is_wp_error( $dom ) ) {
            return $dom;
        }

        $xpath = new DOMXPath( $dom );
        $sections = $xpath->query(
            '//*[self::section and contains(concat(" ", normalize-space(@class), " "), " news-item ")]'
        );
        if ( ! $sections || ! $sections->length || ! ( $sections->item( 0 ) instanceof DOMElement ) ) {
            return new WP_Error( 'detail_scope_missing', 'ÇGM detail sayfasında news-item gövdesi bulunamadı.' );
        }

        $section = $sections->item( 0 );
        $title   = self::detail_title( $xpath, $section );
        $date    = self::extract_turkish_date( self::clean_text( $section->textContent, 5000 ) );
        $published = self::normalize_turkish_date( $date );
        $summary = self::detail_summary( $xpath, $section, $title );
        $image   = self::first_image_url( $xpath, $section, $url );

        if ( ! $title || ! $published || mb_strlen( $summary, 'UTF-8' ) < 60 ) {
            return new WP_Error( 'detail_incomplete', 'ÇGM detail sayfasında başlık, tarih veya haber gövdesi eksik.' );
        }

        return array(
            'title'        => $title,
            'published_at' => $published,
            'summary'      => $summary,
            'image'        => $image,
        );
    }

    private static function detail_url_from_card( DOMXPath $xpath, DOMElement $card, $listing_url ) {
        $anchors = $xpath->query( './/a[@href]', $card );
        if ( ! $anchors ) {
            return '';
        }

        foreach ( $anchors as $anchor ) {
            if ( ! ( $anchor instanceof DOMElement ) ) {
                continue;
            }
            $resolved = self::resolve_url( $anchor->getAttribute( 'href' ), $listing_url );
            if ( ! $resolved || ! self::is_allowed_url( $resolved ) ) {
                continue;
            }
            $path = (string) wp_parse_url( $resolved, PHP_URL_PATH );
            if ( preg_match( '~^/cgm/haberler/[0-9]{8}(?:-[0-9]+)?/?$~i', $path ) ) {
                return self::canonicalize_detail_url( $resolved );
            }
        }

        return '';
    }

    private static function canonicalize_detail_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
            return '';
        }
        return esc_url_raw( 'https://www.csgb.gov.tr' . rtrim( (string) $parts['path'], '/' ) . '/' );
    }

    private static function listing_title( DOMXPath $xpath, DOMElement $card ) {
        $heads = $xpath->query( './/h1|.//h2|.//h3|.//h4|.//h5|.//h6', $card );
        if ( ! $heads ) {
            return '';
        }

        foreach ( $heads as $head ) {
            $title = self::clean_text( $head->textContent, 1000 );
            if ( mb_strlen( $title, 'UTF-8' ) >= 8 ) {
                return preg_replace( '/\s*Haber\s+Detay\s*$/iu', '', $title );
            }
        }

        return '';
    }

    private static function detail_title( DOMXPath $xpath, DOMElement $section ) {
        $heads = $xpath->query(
            './/h2[contains(concat(" ", normalize-space(@class), " "), " page-title ")]',
            $section
        );
        if ( ! $heads || ! $heads->length ) {
            return '';
        }

        return self::clean_text( $heads->item( 0 )->textContent, 1000 );
    }

    private static function detail_summary( DOMXPath $xpath, DOMElement $section, $title ) {
        $paragraphs = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " editor-content ")]//p[normalize-space()]',
            $section
        );
        if ( ! $paragraphs ) {
            return '';
        }

        $parts = array();
        $length = 0;
        $normalized_title = self::normalize_match_text( $title );

        foreach ( $paragraphs as $paragraph ) {
            $text = self::clean_text( $paragraph->textContent, 1600 );
            if ( mb_strlen( $text, 'UTF-8' ) < 20 ) {
                continue;
            }
            if ( $normalized_title && self::normalize_match_text( rtrim( $text, '. ' ) ) === $normalized_title ) {
                continue;
            }

            $parts[] = $text;
            $length += mb_strlen( $text, 'UTF-8' );
            if ( $length >= 3000 || count( $parts ) >= 6 ) {
                break;
            }
        }

        return self::clean_text( implode( "\n\n", $parts ), 4000 );
    }

    private static function first_image_url( DOMXPath $xpath, DOMElement $section, $base_url ) {
        $images = $xpath->query( './/img[@src or @data-src]', $section );
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
            if ( $resolved && self::is_allowed_url( $resolved ) ) {
                return $resolved;
            }
        }

        return '';
    }

    private static function people_signal( $text ) {
        $text = self::normalize_match_text( $text );
        $signals = array(
            'istihdam',
            'isgucu',
            'is gucu',
            'calisma hayati',
            'iskolu',
            'isci',
            'isveren',
            'sendika',
            'toplu is',
            'kamu personel',
            'ucret',
            'sosyal diyalog',
            'adil gecis',
            'beceri',
            'yetenek',
            'mesleki egitim',
            'grev',
            'lokavt',
            '6356 sayili',
            '4688 sayili',
        );

        foreach ( $signals as $signal ) {
            if ( false !== strpos( $text, $signal ) ) {
                return $signal;
            }
        }

        return '';
    }

    private static function is_broad_stream_item( $title ) {
        $title = self::normalize_match_text( $title );
        $patterns = array(
            'stajyer',
            'ziyaret etti',
            'tanisma toplantisi',
            'ikili gorusme',
        );

        foreach ( $patterns as $pattern ) {
            if ( false !== strpos( $title, $pattern ) ) {
                return true;
            }
        }

        return false;
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
            return new WP_Error( 'invalid_source_encoding', 'ÇGM HTML UTF-8 olarak doğrulanamadı.' );
        }

        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument( '1.0', 'UTF-8' );
        $loaded   = $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $loaded ? $dom : new WP_Error( 'invalid_source_html', 'ÇGM HTML ayrıştırılamadı.' );
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

        return 'csgb.gov.tr' === $host;
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
    if ( 'csgb_cgm_news' !== sanitize_key( $source_key ) ) {
        return $hosts;
    }
    return array_values( array_unique( array_merge( (array) $hosts, array( 'csgb.gov.tr', 'www.csgb.gov.tr' ) ) ) );
}, 20, 2 );
