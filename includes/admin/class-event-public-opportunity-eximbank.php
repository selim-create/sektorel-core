<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Türk Eximbank bounded public-opportunity provider.
 *
 * Eximbank financing products are mostly evergreen and therefore must not be
 * turned into artificial deadline Events. This adapter scans official
 * announcements and returns rows only when an application period / deadline is
 * explicitly present in the same official detail page.
 */
class Sektorel_Event_Public_Opportunity_Eximbank {

    const INDEX_URL   = 'https://www.eximbank.gov.tr/tr/duyurular/2026-yili-duyurulari';
    const MAX_DETAILS = 30;

    public static function discover( $year ) {
        $result = array(
            'rows'   => array(),
            'errors' => array(),
            'stats'  => array( 'links' => 0, 'verified' => 0 ),
        );

        $html = self::fetch_html( self::INDEX_URL );
        if ( is_wp_error( $html ) ) {
            $result['errors'][] = 'Türk Eximbank duyuru listesi alınamadı: ' . $html->get_error_message();
            return $result;
        }

        $dom = self::load_dom( $html );
        if ( is_wp_error( $dom ) ) {
            $result['errors'][] = 'Türk Eximbank duyuru listesi ayrıştırılamadı.';
            return $result;
        }

        $links = self::collect_detail_links( $dom );
        $links = array_slice( $links, 0, self::MAX_DETAILS, true );
        $result['stats']['links'] = count( $links );

        $today       = current_time( 'Y-m-d' );
        $horizon_end = sprintf( '%04d-12-31', absint( $year ) );
        $detail_errors = 0;

        foreach ( $links as $url => $fallback_title ) {
            $detail = self::fetch_html( $url );
            if ( is_wp_error( $detail ) ) {
                $detail_errors++;
                continue;
            }

            $row = self::parse_detail( $url, $detail, $fallback_title, $year );
            if ( ! $row ) {
                continue;
            }
            if ( $row['application_deadline'] < $today || $row['application_start'] > $horizon_end ) {
                continue;
            }

            $result['rows'][] = $row;
        }

        if ( $detail_errors ) {
            $result['errors'][] = 'Türk Eximbank duyuru detaylarının ' . $detail_errors . ' tanesi alınamadı veya ayrıştırılamadı.';
        }

        $result['stats']['verified'] = count( $result['rows'] );
        return $result;
    }

    private static function collect_detail_links( $dom ) {
        $xpath = new DOMXPath( $dom );
        $links = array();

        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $url = self::absolute_url( trim( (string) $anchor->getAttribute( 'href' ) ), self::INDEX_URL );
            if ( ! $url || ! self::allowed_eximbank_url( $url ) || self::url_key( $url ) === self::url_key( self::INDEX_URL ) ) {
                continue;
            }

            $label = self::clean_text( $anchor->textContent );
            $key   = self::normalized_text( $label );
            if ( ! $key ) {
                continue;
            }
            if ( ! self::contains_any( $key, array( 'basvuru', 'program', 'destek', 'kredi', 'finansman', 'ihracat' ) ) ) {
                continue;
            }
            if ( self::contains_any( $key, array( 'faiz oran', 'genel kurul', 'faaliyet raporu', 'surdurulebilirlik raporu', 'ihale', 'personel' ) ) ) {
                continue;
            }

            $links[ self::url_key( $url ) ] = array( 'url' => $url, 'label' => $label );
        }

        $normalized = array();
        foreach ( $links as $row ) {
            $normalized[ $row['url'] ] = $row['label'];
        }
        return $normalized;
    }

    private static function parse_detail( $url, $html, $fallback_title, $year ) {
        $dom = self::load_dom( $html );
        if ( is_wp_error( $dom ) ) {
            return null;
        }

        $title = self::extract_heading( $dom, $fallback_title );
        $text  = self::document_text( $dom );
        $key   = self::normalized_text( $title . ' ' . $text );

        // Explicitly fail closed for ordinary evergreen loan/product pages.
        if ( ! self::contains_any( $key, array( 'son basvuru', 'basvuru son tarihi', 'basvuru donemi', 'basvurular', 'tarihine kadar' ) ) ) {
            return null;
        }
        if ( ! self::contains_any( $key, array( 'kredi', 'finansman', 'destek', 'program', 'ihracatci' ) ) ) {
            return null;
        }
        if ( self::contains_any( $key, array( 'ihale', 'personel alimi', 'faaliyet raporu', 'genel kurul' ) ) ) {
            return null;
        }

        $published = self::extract_published_date( $dom );
        $dates     = self::extract_application_dates( $text, $published );
        if ( ! $dates ) {
            return null;
        }
        if ( (int) substr( $dates['deadline'], 0, 4 ) !== (int) $year || $dates['deadline'] < current_time( 'Y-m-d' ) ) {
            return null;
        }

        $application_url = self::extract_application_url( $dom, $url );
        if ( ! $application_url ) {
            $application_url = $url;
        }

        return array(
            'occurrence_key'       => 'eximbank_' . substr( md5( self::url_key( $url ) ), 0, 20 ),
            'title'                => sanitize_text_field( self::deadline_title( $title ) ),
            'application_start'    => $dates['start'],
            'application_deadline' => $dates['deadline'],
            'provider'             => 'eximbank',
            'provider_name'        => 'Türk Eximbank',
            'kind'                 => 'credit_support',
            'audience'             => array( 'exporter', 'company' ),
            'description'          => sanitize_textarea_field(
                'Türk Eximbank resmî duyurusunda doğrulanan başvuru dönemi '
                . self::display_date( $dates['start'] ) . ' – ' . self::display_date( $dates['deadline'] )
                . ' arasındadır. Finansman koşulları ve güncel başvuru adımları resmî duyurudan doğrulanmalıdır.'
            ),
            'source_url'           => esc_url_raw( $url, array( 'http', 'https' ) ),
            'application_url'      => esc_url_raw( $application_url, array( 'http', 'https' ) ),
            'amount'               => '',
            'date_basis'           => 'live_eximbank_bounded_announcement',
        );
    }

    private static function extract_application_dates( $text, $published ) {
        $window = self::application_window( $text );
        if ( ! $window ) {
            return null;
        }
        $dates = self::dates_from_text( $window );
        if ( count( $dates ) >= 2 && $dates[1] >= $dates[0] ) {
            return array( 'start' => $dates[0], 'deadline' => $dates[1] );
        }
        if ( 1 === count( $dates ) && $published && $dates[0] >= $published ) {
            $key = self::normalized_text( $window );
            if ( self::contains_any( $key, array( 'son basvuru', 'tarihine kadar', 'basvuru son tarihi' ) ) ) {
                return array( 'start' => $published, 'deadline' => $dates[0] );
            }
        }
        return null;
    }

    private static function application_window( $text ) {
        if ( ! preg_match( '/başvuru|basvuru/iu', $text, $match, PREG_OFFSET_CAPTURE ) ) {
            return '';
        }
        return substr( $text, max( 0, (int) $match[0][1] - 120 ), 1000 );
    }

    private static function dates_from_text( $text ) {
        $found = array();
        $pattern = '/\b(\d{1,2})\s+([\p{L}\.]+)\s+(20\d{2})\b|\b(\d{1,2})[\.\/-](\d{1,2})[\.\/-](20\d{2})\b|\b(20\d{2})-(\d{2})-(\d{2})\b/iu';
        if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
            return array();
        }
        foreach ( $matches as $m ) {
            $date = '';
            if ( ! empty( $m[1] ) ) {
                $date = self::named_month_date( $m[1], $m[2], $m[3] );
            } elseif ( ! empty( $m[4] ) ) {
                $date = self::numeric_date( $m[4], $m[5], $m[6] );
            } elseif ( ! empty( $m[7] ) ) {
                $date = self::numeric_date( $m[9], $m[8], $m[7] );
            }
            if ( $date && ! in_array( $date, $found, true ) ) {
                $found[] = $date;
            }
        }
        return $found;
    }

    private static function extract_application_url( $dom, $source_url ) {
        $xpath = new DOMXPath( $dom );
        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $label = self::normalized_text( $anchor->textContent . ' ' . $anchor->getAttribute( 'href' ) );
            if ( ! self::contains_any( $label, array( 'basvuru', 'internet sube', 'online' ) ) ) {
                continue;
            }
            $url = self::absolute_url( trim( (string) $anchor->getAttribute( 'href' ) ), $source_url );
            if ( $url && self::allowed_eximbank_url( $url ) ) {
                return $url;
            }
        }
        return '';
    }

    private static function extract_published_date( $dom ) {
        $xpath = new DOMXPath( $dom );
        foreach ( array( '//time/@datetime', "//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'date')][1]" ) as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes ) {
                continue;
            }
            foreach ( $nodes as $node ) {
                $value = self::clean_text( (string) $node->nodeValue );
                $dates = self::dates_from_text( $value );
                if ( $dates ) {
                    return $dates[0];
                }
                if ( preg_match( '/^(20\d{2})-(\d{2})-(\d{2})/', $value, $m ) ) {
                    return self::numeric_date( $m[3], $m[2], $m[1] );
                }
            }
        }
        return '';
    }

    private static function extract_heading( $dom, $fallback ) {
        $xpath = new DOMXPath( $dom );
        foreach ( array( '//h1', '//h2' ) as $query ) {
            foreach ( $xpath->query( $query ) as $node ) {
                $text = self::clean_text( $node->textContent );
                if ( $text && strlen( $text ) >= 5 ) {
                    return $text;
                }
            }
        }
        return self::clean_text( $fallback );
    }

    private static function document_text( $dom ) {
        $xpath = new DOMXPath( $dom );
        foreach ( array( '//main', '//article', "//*[@role='main']" ) as $query ) {
            $node = $xpath->query( $query )->item( 0 );
            if ( $node ) {
                $text = self::clean_text( $node->textContent );
                if ( strlen( $text ) >= 80 ) {
                    return $text;
                }
            }
        }
        $body = $xpath->query( '//body' )->item( 0 );
        return $body ? self::clean_text( $body->textContent ) : '';
    }

    private static function fetch_html( $url ) {
        if ( ! self::allowed_eximbank_url( $url ) ) {
            return new WP_Error( 'eximbank_host_blocked', 'Türk Eximbank URL allowlist dışında.' );
        }
        $response = wp_safe_remote_get( $url, array(
            'timeout'     => 15,
            'redirection' => 3,
            'user-agent'  => 'SektorelAjanda/1.53 (+https://sektorelajanda.com)',
            'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'eximbank_http_error', 'HTTP ' . $code );
        }
        if ( strlen( $body ) < 200 ) {
            return new WP_Error( 'eximbank_empty_body', 'Türk Eximbank sayfa gövdesi boş veya yetersiz.' );
        }
        return $body;
    }

    private static function allowed_eximbank_url( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        return $host && ( 'eximbank.gov.tr' === $host || 'www.eximbank.gov.tr' === $host || self::ends_with( $host, '.eximbank.gov.tr' ) );
    }

    private static function load_dom( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'eximbank_dom_missing', 'PHP DOM uzantısı bulunamadı.' );
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        return $loaded ? $dom : new WP_Error( 'eximbank_dom_invalid', 'Türk Eximbank HTML DOM oluşturulamadı.' );
    }

    private static function named_month_date( $day, $month, $year ) {
        $months = array(
            'ocak'=>1,'january'=>1,'jan'=>1,'subat'=>2,'february'=>2,'feb'=>2,'mart'=>3,'march'=>3,'mar'=>3,
            'nisan'=>4,'april'=>4,'apr'=>4,'mayis'=>5,'may'=>5,'haziran'=>6,'june'=>6,'jun'=>6,
            'temmuz'=>7,'july'=>7,'jul'=>7,'agustos'=>8,'august'=>8,'aug'=>8,'eylul'=>9,'september'=>9,'sep'=>9,
            'ekim'=>10,'october'=>10,'oct'=>10,'kasim'=>11,'november'=>11,'nov'=>11,'aralik'=>12,'december'=>12,'dec'=>12,
        );
        $key = str_replace( ' ', '', self::normalized_text( $month ) );
        if ( ! isset( $months[ $key ] ) ) {
            return '';
        }
        return self::numeric_date( $day, $months[ $key ], $year );
    }

    private static function numeric_date( $day, $month, $year ) {
        $day = absint( $day ); $month = absint( $month ); $year = absint( $year );
        if ( ! checkdate( $month, $day, $year ) ) {
            return '';
        }
        return sprintf( '%04d-%02d-%02d', $year, $month, $day );
    }

    private static function absolute_url( $url, $base_url ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( ! $url || 0 === strpos( $url, '#' ) || preg_match( '#^(?:mailto|tel|javascript):#i', $url ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $url ) ) {
            return esc_url_raw( $url, array( 'http', 'https' ) );
        }
        $base = wp_parse_url( $base_url );
        if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
            return '';
        }
        $origin = $base['scheme'] . '://' . $base['host'];
        if ( 0 === strpos( $url, '//' ) ) {
            return esc_url_raw( $base['scheme'] . ':' . $url, array( 'http', 'https' ) );
        }
        if ( 0 === strpos( $url, '/' ) ) {
            return esc_url_raw( $origin . $url, array( 'http', 'https' ) );
        }
        $path = isset( $base['path'] ) ? (string) $base['path'] : '/';
        $dir  = '/' === substr( $path, -1 ) ? $path : trailingslashit( dirname( $path ) );
        return esc_url_raw( $origin . $dir . $url, array( 'http', 'https' ) );
    }

    private static function url_key( $url ) {
        $parts = wp_parse_url( (string) $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }
        $path = isset( $parts['path'] ) ? '/' . trim( (string) $parts['path'], '/' ) : '/';
        return strtolower( $parts['host'] ) . rtrim( $path, '/' );
    }

    private static function deadline_title( $title ) {
        return false !== strpos( self::normalized_text( $title ), 'son basvuru' ) ? $title : $title . ' — Son Başvuru';
    }

    private static function display_date( $date ) {
        return substr( $date, 8, 2 ) . '.' . substr( $date, 5, 2 ) . '.' . substr( $date, 0, 4 );
    }

    private static function normalized_text( $text ) {
        $text = strtolower( remove_accents( self::clean_text( $text ) ) );
        $text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }

    private static function contains_any( $text, $needles ) {
        foreach ( $needles as $needle ) {
            if ( false !== strpos( $text, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    private static function ends_with( $haystack, $needle ) {
        return '' !== $needle && strlen( $haystack ) >= strlen( $needle ) && substr( $haystack, -strlen( $needle ) ) === $needle;
    }
}
