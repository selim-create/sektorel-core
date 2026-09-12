<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic adapter for Yatırıma Destek government support updates.
 *
 * The audited advanced-search page embeds each support in a compact div.item:
 * - institution/title/status in div.item-showroom
 * - update/status/deadline in div.detail-props
 * - stable Yatırıma Destek URL in a.fizibilitePaylas[data-url]
 * - related legislation/guidance files in div.file-list
 *
 * The source is an official-government aggregator, not the canonical issuing
 * authority for every support. Normal scans therefore ingest only supports whose
 * explicit "Destek Güncelleme Tarihi" belongs to the current WordPress year and
 * preserve the issuing institution plus document evidence in raw_payload.
 */
class Sektorel_Content_Source_Yatirim_Destek_Adapter {

    const TIMEOUT       = 20;
    const MAX_BODY_SIZE = 2097152; // 2 MB.
    const MAX_DOCUMENTS = 20;

    public static function fetch_items( $source_id, $limit ) {
        $source_id = absint( $source_id );
        $limit     = max( 1, min( 20, absint( $limit ) ) );
        $url       = trim( (string) get_post_meta( $source_id, 'feed_url', true ) );

        if ( ! $url ) {
            return new WP_Error( 'missing_listing_url', 'Yatırıma Destek liste URL alanı eksik.' );
        }
        if ( ! self::is_listing_url( $url ) ) {
            return new WP_Error( 'unsafe_listing_url', 'Yatırıma Destek liste URL resmi host allowlist ile eşleşmiyor.' );
        }

        $response = wp_safe_remote_get( $url, self::request_args() );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'custom_source_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'custom_source_http_error', 'Yatırıma Destek liste sayfası HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'custom_source_empty', 'Yatırıma Destek liste sayfası boş yanıt döndürdü.' );
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
            '//div[contains(concat(" ", normalize-space(@class), " "), " item ") and .//div[contains(concat(" ", normalize-space(@class), " "), " item-showroom ")] and .//div[contains(concat(" ", normalize-space(@class), " "), " detail-props ")]]'
        );

        if ( ! $cards || ! $cards->length ) {
            return new WP_Error( 'custom_source_no_items', 'Yatırıma Destek sayfasında doğrulanmış destek containerı bulunamadı.' );
        }

        $current_year = (int) current_time( 'Y' );
        $items        = array();
        $seen         = array();

        foreach ( $cards as $card ) {
            if ( ! ( $card instanceof DOMElement ) ) {
                continue;
            }

            $institution = self::showroom_value( $xpath, $card, 'col1' );
            $title       = self::showroom_value( $xpath, $card, 'col2' );
            $show_status = self::showroom_value( $xpath, $card, 'col3' );
            $props       = self::detail_properties( $xpath, $card );

            $update_raw = (string) ( $props['destek guncelleme tarihi'] ?? '' );
            $active     = (string) ( $props['aktiflik durumu'] ?? $show_status );
            $deadline   = (string) ( $props['son basvuru tarihi'] ?? '' );
            $published  = self::normalize_numeric_date( $update_raw );

            if (
                mb_strlen( $institution, 'UTF-8' ) < 3 ||
                mb_strlen( $title, 'UTF-8' ) < 5 ||
                ! $update_raw ||
                ! $published ||
                (int) substr( $published, 0, 4 ) !== $current_year ||
                self::looks_mojibaked( $institution ) ||
                self::looks_mojibaked( $title )
            ) {
                continue;
            }

            $canonical = self::canonical_support_url( $xpath, $card );
            if ( ! $canonical ) {
                continue;
            }

            $identity = self::support_identity( $canonical );
            if ( ! $identity || isset( $seen[ $identity ] ) ) {
                continue;
            }
            $seen[ $identity ] = true;

            $institution_url = self::institution_url( $xpath, $card );
            $documents       = self::documents( $xpath, $card );
            $summary         = self::summary(
                $institution,
                $title,
                $update_raw,
                $active,
                $deadline,
                $documents
            );

            if ( mb_strlen( $summary, 'UTF-8' ) < 80 || self::looks_mojibaked( $summary ) ) {
                continue;
            }

            $path = (string) wp_parse_url( $canonical, PHP_URL_PATH );
            $slug = '';
            $numeric_id = 0;
            if ( preg_match( '~^/destek/([^/?#]+)/(\d+)/?$~i', $path, $match ) ) {
                $slug       = sanitize_title( $match[1] );
                $numeric_id = absint( $match[2] );
            }

            $items[] = array(
                'source_item_key' => mb_substr( 'yatirim-destek:' . $identity, 0, 191 ),
                'title'           => $title,
                'url'             => esc_url_raw( $canonical ),
                'published_at'    => $published,
                'summary'         => $summary,
                'date_source'     => 'support_update_date',
                'raw_payload'     => array(
                    'listing_url'             => esc_url_raw( $listing_url ),
                    'support_id'              => $numeric_id,
                    'support_slug'            => $slug,
                    'institution'             => $institution,
                    'institution_url'         => esc_url_raw( $institution_url ),
                    'support_update_date'     => $update_raw,
                    'active_status'           => $active,
                    'application_deadline'    => $deadline,
                    'documents'               => $documents,
                    'card_scope'              => 'div.item',
                    'showroom_scope'          => 'div.item-showroom',
                    'props_scope'             => 'div.detail-props',
                    'canonical_source'        => 'a.fizibilitePaylas[data-url]',
                    'listing_embedded_detail' => true,
                    'current_year_gate'       => $current_year,
                ),
            );
        }

        if ( ! $items ) {
            return new WP_Error(
                'custom_source_no_items',
                'Yatırıma Destek sayfasında current-year update tarihi, stable support URL ve destek metadata kontrolünü geçen kayıt bulunamadı.'
            );
        }

        usort( $items, static function( $a, $b ) {
            $date_cmp = strcmp( (string) $b['published_at'], (string) $a['published_at'] );
            if ( 0 !== $date_cmp ) {
                return $date_cmp;
            }
            return strcmp( (string) $a['title'], (string) $b['title'] );
        } );

        return array_slice( $items, 0, $limit );
    }

    private static function showroom_value( DOMXPath $xpath, DOMElement $card, $column_class ) {
        $nodes = $xpath->query(
            './/div[contains(concat(" ", normalize-space(@class), " "), " item-showroom ")]//div[contains(concat(" ", normalize-space(@class), " "), " ' . $column_class . ' ")][1]',
            $card
        );
        if ( ! $nodes || ! $nodes->length || ! ( $nodes->item( 0 ) instanceof DOMElement ) ) {
            return '';
        }

        $node  = $nodes->item( 0 );
        $title = self::clean_text( $node->getAttribute( 'title' ), 1000 );
        if ( $title ) {
            return $title;
        }

        $desc = $xpath->query(
            './/div[contains(concat(" ", normalize-space(@class), " "), " desc ")][1]',
            $node
        );
        if ( $desc && $desc->length ) {
            return self::clean_text( $desc->item( 0 )->textContent, 1000 );
        }

        return self::clean_text( $node->textContent, 1000 );
    }

    private static function detail_properties( DOMXPath $xpath, DOMElement $card ) {
        $rows = $xpath->query(
            './/div[contains(concat(" ", normalize-space(@class), " "), " detail-props ")]//div[contains(concat(" ", normalize-space(@class), " "), " dprow ")]',
            $card
        );

        $props = array();
        if ( ! $rows ) {
            return $props;
        }

        foreach ( $rows as $row ) {
            if ( ! ( $row instanceof DOMElement ) ) {
                continue;
            }

            $labels = $xpath->query(
                './/div[contains(concat(" ", normalize-space(@class), " "), " dplabel ")][1]',
                $row
            );
            $values = $xpath->query(
                './/div[contains(concat(" ", normalize-space(@class), " "), " dpval ")][1]',
                $row
            );

            if ( ! $labels || ! $labels->length || ! $values || ! $values->length ) {
                continue;
            }

            $label = self::normalize_match_text( $labels->item( 0 )->textContent );
            $value = self::clean_text( $values->item( 0 )->textContent, 1000 );
            if ( $label && $value ) {
                $props[ $label ] = $value;
            }
        }

        return $props;
    }

    private static function canonical_support_url( DOMXPath $xpath, DOMElement $card ) {
        $nodes = $xpath->query(
            './/a[contains(concat(" ", normalize-space(@class), " "), " fizibilitePaylas ") and @data-url]',
            $card
        );
        if ( ! $nodes || ! $nodes->length || ! ( $nodes->item( 0 ) instanceof DOMElement ) ) {
            return '';
        }

        $url = html_entity_decode(
            trim( (string) $nodes->item( 0 )->getAttribute( 'data-url' ) ),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return self::is_support_url( $url ) ? esc_url_raw( $url ) : '';
    }

    private static function support_identity( $url ) {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( preg_match( '~^/destek/[^/?#]+/(\d+)/?$~i', $path, $match ) ) {
            return (string) absint( $match[1] );
        }
        return '';
    }

    private static function institution_url( DOMXPath $xpath, DOMElement $card ) {
        $nodes = $xpath->query(
            './/div[contains(concat(" ", normalize-space(@class), " "), " detail-title ")]//div[contains(concat(" ", normalize-space(@class), " "), " baslik ")]//a[@href][1]',
            $card
        );
        if ( ! $nodes || ! $nodes->length || ! ( $nodes->item( 0 ) instanceof DOMElement ) ) {
            return '';
        }

        $url = trim( (string) $nodes->item( 0 )->getAttribute( 'href' ) );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        return in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true )
            ? esc_url_raw( $url )
            : '';
    }

    private static function documents( DOMXPath $xpath, DOMElement $card ) {
        $nodes = $xpath->query(
            './/div[contains(concat(" ", normalize-space(@class), " "), " file-list ")]//a[@href]',
            $card
        );
        $documents = array();
        $seen      = array();

        if ( ! $nodes ) {
            return $documents;
        }

        foreach ( $nodes as $node ) {
            if ( ! ( $node instanceof DOMElement ) ) {
                continue;
            }

            $url = html_entity_decode(
                trim( (string) $node->getAttribute( 'href' ) ),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            if ( ! self::is_document_url( $url ) || isset( $seen[ $url ] ) ) {
                continue;
            }
            $seen[ $url ] = true;

            $label = self::clean_text( $node->textContent, 500 );
            if ( ! $label ) {
                $label = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
            }

            $documents[] = array(
                'label' => $label,
                'url'   => esc_url_raw( $url ),
            );

            if ( count( $documents ) >= self::MAX_DOCUMENTS ) {
                break;
            }
        }

        return $documents;
    }

    private static function summary( $institution, $title, $update_raw, $active, $deadline, $documents ) {
        $parts = array(
            'Kurum: ' . $institution . '.',
            'Destek: ' . $title . '.',
            'Destek güncelleme tarihi: ' . $update_raw . '.',
        );

        if ( $active ) {
            $parts[] = 'Aktiflik durumu: ' . $active . '.';
        }
        if ( $deadline ) {
            $parts[] = 'Son başvuru tarihi: ' . $deadline . '.';
        }

        $labels = array();
        foreach ( array_slice( (array) $documents, 0, 4 ) as $document ) {
            $label = self::clean_text( $document['label'] ?? '', 180 );
            if ( $label ) {
                $labels[] = $label;
            }
        }
        if ( $labels ) {
            $parts[] = 'İlgili dokümanlar: ' . implode( '; ', $labels ) . '.';
        }

        return self::clean_text( implode( ' ', $parts ), 4000 );
    }

    private static function normalize_numeric_date( $value ) {
        $value = trim( (string) $value );
        if ( ! preg_match( '~^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$~', $value, $match ) ) {
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
            return new WP_Error( 'invalid_source_encoding', 'Yatırıma Destek HTML UTF-8 olarak doğrulanamadı.' );
        }

        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument( '1.0', 'UTF-8' );
        $loaded   = $dom->loadHTML(
            '<?xml encoding="UTF-8"?>' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $loaded ? $dom : new WP_Error( 'invalid_source_html', 'Yatırıma Destek HTML ayrıştırılamadı.' );
    }

    private static function is_listing_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        $host = self::canonical_host( (string) $parts['host'] );
        $path = rtrim( (string) ( $parts['path'] ?? '' ), '/' );

        return 'https' === strtolower( (string) $parts['scheme'] ) &&
            'yatirimadestek.gov.tr' === $host &&
            '/gelismis-arama' === $path;
    }

    private static function is_support_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        $host = self::canonical_host( (string) $parts['host'] );
        $path = (string) ( $parts['path'] ?? '' );

        return 'https' === strtolower( (string) $parts['scheme'] ) &&
            'yatirimadestek.gov.tr' === $host &&
            (bool) preg_match( '~^/destek/[^/?#]+/\d+/?$~i', $path );
    }

    private static function is_document_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        $host = self::canonical_host( (string) $parts['host'] );
        $path = (string) ( $parts['path'] ?? '' );

        if (
            'https' !== strtolower( (string) $parts['scheme'] ) ||
            'yatirimadestek.gov.tr' !== $host ||
            0 !== strpos( $path, '/pdf/assets/upload/dosyalar/' )
        ) {
            return false;
        }

        return (bool) preg_match( '~\.(?:pdf|doc|docx|xls|xlsx)$~i', $path );
    }

    private static function canonical_host( $host ) {
        $host = strtolower( rtrim( (string) $host, '.' ) );
        return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
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
