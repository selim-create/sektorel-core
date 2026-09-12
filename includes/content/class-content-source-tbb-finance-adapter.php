<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic first-page adapter for finance-focused TBB news.
 *
 * The audited TBB /haberler listing exposes each news record inside
 * div.etkinlikler-item with an exact dd.mm.yyyy publication date, one title
 * paragraph, one source image, a card-level descriptive text and a canonical
 * /haberler/{slug} link. The page is editorially broad, so only records with an
 * explicit financing/credit signal are accepted into the Finansman desk.
 * Institutional visits, events and generic banking news fail closed.
 */
class Sektorel_Content_Source_TBB_Finance_Adapter {

    const TIMEOUT       = 15;
    const MAX_BODY_SIZE = 2097152; // 2 MB.

    public static function fetch_items( $source_id, $limit ) {
        $source_id = absint( $source_id );
        $limit     = max( 1, min( 20, absint( $limit ) ) );
        $url       = trim( (string) get_post_meta( $source_id, 'feed_url', true ) );

        if ( ! $url ) {
            return new WP_Error( 'missing_listing_url', 'TBB haber liste URL alanı eksik.' );
        }
        if ( ! self::is_allowed_url( $url ) ) {
            return new WP_Error( 'unsafe_listing_url', 'TBB haber liste URL resmi host allowlist ile eşleşmiyor.' );
        }

        $response = wp_safe_remote_get( $url, self::request_args() );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'custom_source_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'custom_source_http_error', 'TBB haber listesi HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'custom_source_empty', 'TBB haber listesi boş yanıt döndürdü.' );
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
            '//*[contains(concat(" ", normalize-space(@class), " "), " etkinlikler-item ")]'
        );

        if ( ! $cards || ! $cards->length ) {
            return new WP_Error( 'custom_source_no_items', 'TBB haber listesinde etkinlikler-item haber kartı bulunamadı.' );
        }

        $items = array();
        foreach ( $cards as $card ) {
            if ( ! ( $card instanceof DOMElement ) ) {
                continue;
            }

            $anchors = $xpath->query(
                './/a[@href and contains(concat(" ", normalize-space(@class), " "), " stretched-link ")]',
                $card
            );
            if ( ! $anchors || ! $anchors->length ) {
                continue;
            }

            $detail_url = self::resolve_url( $anchors->item( 0 )->getAttribute( 'href' ), $listing_url );
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

            $context   = self::clean_text( $card->textContent, 7000 );
            $date_raw  = self::extract_numeric_date( $context );
            $published = self::normalize_numeric_date( $date_raw );
            $title     = self::first_title( $xpath, $card );
            $summary   = self::summary_from_card_context( $context, $date_raw, $title );
            $image_url = self::first_image_url( $xpath, $card, $listing_url );
            $signal    = self::finance_signal( $title, $summary );

            if (
                mb_strlen( $title, 'UTF-8' ) < 8 ||
                ! $date_raw ||
                ! $published ||
                mb_strlen( $summary, 'UTF-8' ) < 40 ||
                ! $signal ||
                self::is_broad_stream_item( $title ) ||
                self::looks_mojibaked( $title ) ||
                self::looks_mojibaked( $summary )
            ) {
                continue;
            }

            $items[] = array(
                'source_item_key' => mb_substr( 'tbb-finance:' . $slug, 0, 191 ),
                'title'           => $title,
                'url'             => esc_url_raw( $detail_url ),
                'published_at'    => $published,
                'summary'         => $summary,
                'date_source'     => 'listing_numeric_date',
                'raw_payload'     => array(
                    'listing_url'  => esc_url_raw( $listing_url ),
                    'detail_path'  => $detail_path,
                    'slug'         => $slug,
                    'title'        => $title,
                    'published'    => $date_raw,
                    'summary'      => $summary,
                    'lead_image'   => esc_url_raw( $image_url ),
                    'topic_signal' => $signal,
                    'card_scope'   => 'div.etkinlikler-item a.stretched-link[href*="/haberler/"]',
                ),
            );
        }

        if ( ! $items ) {
            return new WP_Error(
                'custom_source_no_items',
                'TBB haber listesinde tarih, özet ve finansman konu kontrolünü geçen güvenilir kayıt bulunamadı.'
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

    /**
     * TBB cards currently expose the headline in their sole <p> element.
     * Do not treat that paragraph as the summary; production diagnostics proved
     * that doing so empties the derived title and rejects every card.
     */
    private static function first_title( DOMXPath $xpath, DOMElement $card ) {
        $paragraphs = $xpath->query( './/p', $card );
        if ( ! $paragraphs ) {
            return '';
        }

        foreach ( $paragraphs as $paragraph ) {
            $title = self::clean_text( $paragraph->textContent, 1000 );
            $title = preg_replace( '/\s*Haberin\s+Devamı\s*$/iu', '', $title );
            $title = trim( (string) $title );
            if ( mb_strlen( $title, 'UTF-8' ) >= 8 ) {
                return $title;
            }
        }

        return '';
    }

    /**
     * The descriptive summary is card text after the exact date and headline.
     * This keeps title, date and summary in one audited DOM scope and avoids
     * widening extraction to surrounding list/container text.
     */
    private static function summary_from_card_context( $context, $date_raw, $title ) {
        $summary = trim( (string) $context );

        if ( $date_raw ) {
            $summary = preg_replace(
                '/^\s*' . preg_quote( $date_raw, '/' ) . '\s*/u',
                '',
                $summary,
                1
            );
        }
        $summary = trim( (string) $summary );

        if ( $title && 0 === mb_strpos( $summary, $title, 0, 'UTF-8' ) ) {
            $summary = mb_substr( $summary, mb_strlen( $title, 'UTF-8' ), null, 'UTF-8' );
        }

        $summary = preg_replace( '/\s*Haberin\s+Devamı\s*$/iu', '', (string) $summary );
        return self::clean_text( $summary, 4000 );
    }

    private static function finance_signal( $title, $summary ) {
        $text = self::normalize_match_text( $title . ' ' . $summary );
        $signals = array(
            'iklim finansmani',
            'surdurulebilir finansman',
            'surdurulebilir finans',
            'yesil finansman',
            'yesil finans',
            'proje finansmani',
            'yatirim finansmani',
            'finansmana erisim',
            'finansman mekanizmasi',
            'finansman mekanizmalari',
            'finansman destegi',
            'finansman imkanlari',
            'finansman olanaklari',
            'kredi hacmi',
            'kredi buyumesi',
            'krediye erisim',
            'kredilere erisim',
            'ticari kredi',
            'kobi kred',
            'ihracat kred',
            'yatirim kred',
            'kredi garanti',
            'kredi kefalet',
        );

        foreach ( $signals as $signal ) {
            if ( false !== strpos( $text, $signal ) ) {
                return $signal;
            }
        }

        return '';
    }

    /**
     * The TBB stream mixes finance coverage with institutional visits, generic
     * banking seminars and training/event announcements. These audited title
     * patterns fail closed even if a strong finance phrase appears incidentally
     * later in the card description.
     */
    private static function is_broad_stream_item( $title ) {
        $title = self::normalize_match_text( $title );
        $patterns = array(
            'ziyaret etti',
            'kabul etti',
            'bankacilik sektoru anlatildi',
            'ekonomi sohbetleri',
            'egitim profesyonellerine',
            'ceza hukuku',
            'sertifikasyon egitim programi',
            'bir araya geldi',
        );

        foreach ( $patterns as $pattern ) {
            if ( false !== strpos( $title, $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    private static function extract_numeric_date( $text ) {
        if ( preg_match( '~\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b~u', (string) $text, $match ) ) {
            return $match[1] . '.' . $match[2] . '.' . $match[3];
        }
        return '';
    }

    private static function normalize_numeric_date( $value ) {
        if ( ! preg_match( '~^(\d{1,2})\.(\d{1,2})\.(\d{4})$~', trim( (string) $value ), $match ) ) {
            return null;
        }

        $day   = (int) $match[1];
        $month = (int) $match[2];
        $year  = (int) $match[3];
        if ( ! checkdate( $month, $day, $year ) ) {
            return null;
        }

        return gmdate( 'Y-m-d H:i:s', gmmktime( 12, 0, 0, $month, $day, $year ) );
    }

    private static function first_image_url( DOMXPath $xpath, DOMElement $card, $base_url ) {
        $images = $xpath->query( './/img[@src]', $card );
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

    private static function request_args() {
        return array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_BODY_SIZE,
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
            return new WP_Error( 'invalid_source_encoding', 'TBB HTML UTF-8 olarak doğrulanamadı.' );
        }

        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument( '1.0', 'UTF-8' );
        $loaded   = $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $loaded ? $dom : new WP_Error( 'invalid_source_html', 'TBB HTML ayrıştırılamadı.' );
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

        return 'tbb.org.tr' === $host;
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
    if ( 'tbb_finance_news' !== sanitize_key( $source_key ) ) {
        return $hosts;
    }
    return array_values( array_unique( array_merge( (array) $hosts, array( 'tbb.org.tr', 'www.tbb.org.tr' ) ) ) );
}, 20, 2 );