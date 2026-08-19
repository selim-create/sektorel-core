<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * TÜBİTAK public-opportunity provider.
 *
 * Uses the official Açık Çağrılar list as discovery and validates each
 * business-relevant call against its official detail page before returning a
 * normalized opportunity row to the shared live stage.
 */
class Sektorel_Event_Public_Opportunity_Tubitak {

    const INDEX_URL = 'https://tubitak.gov.tr/tr/acik-cagrilar';
    const MAX_DETAILS = 40;

    public static function discover( $year ) {
        $result = array(
            'rows'   => array(),
            'errors' => array(),
            'stats'  => array( 'links' => 0, 'verified' => 0 ),
        );

        $html = self::fetch_html( self::INDEX_URL );
        if ( is_wp_error( $html ) ) {
            $result['errors'][] = 'TÜBİTAK Açık Çağrılar sayfası alınamadı: ' . $html->get_error_message();
            return $result;
        }

        $dom = self::load_dom( $html );
        if ( is_wp_error( $dom ) ) {
            $result['errors'][] = 'TÜBİTAK Açık Çağrılar sayfası ayrıştırılamadı.';
            return $result;
        }

        $links = self::collect_call_links( $dom );
        $links = array_slice( $links, 0, self::MAX_DETAILS, true );
        $result['stats']['links'] = count( $links );

        $today       = current_time( 'Y-m-d' );
        $horizon_end = sprintf( '%04d-12-31', absint( $year ) );
        $detail_errors = 0;

        foreach ( $links as $url => $card ) {
            if ( empty( $card['start'] ) || empty( $card['deadline'] ) ) {
                continue;
            }
            if ( $card['deadline'] < $today || $card['start'] > $horizon_end ) {
                continue;
            }

            $detail_html = self::fetch_html( $url );
            if ( is_wp_error( $detail_html ) ) {
                $detail_errors++;
                continue;
            }

            $detail_dom = self::load_dom( $detail_html );
            if ( is_wp_error( $detail_dom ) ) {
                $detail_errors++;
                continue;
            }

            $detail_text = self::document_text( $detail_dom );
            $normalized  = self::normalized_text( $detail_text );
            if ( ! self::business_relevant( $normalized ) ) {
                continue;
            }

            $title = self::extract_heading( $detail_dom, $card['title'] );
            if ( ! $title ) {
                continue;
            }

            $application_url = self::extract_application_url( $detail_dom, $url );
            if ( ! $application_url ) {
                $application_url = $url;
            }

            $kind     = self::infer_kind( $normalized );
            $audience = self::infer_audience( $normalized );
            $amount   = self::extract_amount( $detail_text );
            $title    = self::deadline_title( $title );

            $result['rows'][] = array(
                'occurrence_key'       => 'tubitak_' . substr( md5( self::url_key( $url ) ), 0, 20 ),
                'title'                => sanitize_text_field( $title ),
                'application_start'    => $card['start'],
                'application_deadline' => $card['deadline'],
                'provider'             => 'tubitak',
                'provider_name'        => 'TÜBİTAK',
                'kind'                 => $kind,
                'audience'             => $audience,
                'description'          => sanitize_textarea_field(
                    'TÜBİTAK tarafından yayımlanan başvuruya açık çağrının doğrulanmış başvuru dönemi '
                    . self::display_date( $card['start'] ) . ' – ' . self::display_date( $card['deadline'] )
                    . ' arasındadır. Uygunluk koşulları ve güncel başvuru adımları resmî çağrı sayfasından doğrulanmalıdır.'
                ),
                'source_url'           => esc_url_raw( $url, array( 'http', 'https' ) ),
                'application_url'      => esc_url_raw( $application_url, array( 'http', 'https' ) ),
                'amount'               => sanitize_text_field( $amount ),
                'date_basis'           => 'live_tubitak_open_calls',
            );
        }

        if ( $detail_errors ) {
            $result['errors'][] = 'TÜBİTAK çağrı detaylarının ' . $detail_errors . ' tanesi alınamadı veya ayrıştırılamadı.';
        }

        $result['stats']['verified'] = count( $result['rows'] );
        return $result;
    }

    private static function collect_call_links( $dom ) {
        $xpath = new DOMXPath( $dom );
        $links = array();

        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $href = trim( (string) $anchor->getAttribute( 'href' ) );
            $url  = self::absolute_url( $href, self::INDEX_URL );
            if ( ! $url || ! self::is_tubitak_call_url( $url ) ) {
                continue;
            }

            $title = self::clean_text( $anchor->textContent );
            if ( ! $title || false === strpos( self::normalized_text( $title ), 'cagri' ) ) {
                continue;
            }

            $card_text = self::nearest_call_text( $anchor );
            $dates     = self::extract_range( $card_text );
            if ( ! $dates ) {
                continue;
            }

            $key = self::url_key( $url );
            if ( ! isset( $links[ $key ] ) ) {
                $links[ $key ] = array(
                    'url'      => $url,
                    'title'    => $title,
                    'start'    => $dates['start'],
                    'deadline' => $dates['deadline'],
                );
            }
        }

        $normalized = array();
        foreach ( $links as $row ) {
            $normalized[ $row['url'] ] = $row;
        }
        return $normalized;
    }

    private static function nearest_call_text( $anchor ) {
        $node = $anchor;
        $best = self::clean_text( $anchor->textContent );

        for ( $depth = 0; $depth < 6 && $node; $depth++ ) {
            $text = self::clean_text( $node->textContent );
            $key  = self::normalized_text( $text );
            if ( false !== strpos( $key, 'basvuru araligi' ) && strlen( $text ) < 6000 ) {
                return $text;
            }
            if ( strlen( $text ) > strlen( $best ) && strlen( $text ) < 6000 ) {
                $best = $text;
            }
            $node = $node->parentNode;
        }

        return $best;
    }

    private static function extract_range( $text ) {
        $text = self::clean_text( $text );
        $key  = self::normalized_text( $text );
        $pos  = strpos( $key, 'basvuru araligi' );
        if ( false === $pos ) {
            return null;
        }

        // Work on the original text because Turkish month names carry accents.
        if ( preg_match_all( '/(\d{1,2})\s+([\p{L}\.]+)\s+(\d{4})/u', $text, $matches, PREG_SET_ORDER ) ) {
            $dates = array();
            foreach ( $matches as $match ) {
                $date = self::named_month_date( $match[1], $match[2], $match[3] );
                if ( $date && ! in_array( $date, $dates, true ) ) {
                    $dates[] = $date;
                }
            }
            if ( count( $dates ) >= 2 ) {
                return array( 'start' => $dates[0], 'deadline' => $dates[1] );
            }
        }
        return null;
    }

    private static function business_relevant( $text ) {
        foreach ( array( 'teydeb', 'kobi', 'firma', 'sirket', 'kurulus', 'isletme', 'konsorsiyum', 'sanayi' ) as $needle ) {
            if ( false !== strpos( $text, $needle ) ) {
                return true;
            }
        }
        return false;
    }

    private static function infer_kind( $text ) {
        if ( false !== strpos( $text, 'mentorluk' ) || false !== strpos( $text, 'danismanlik' ) ) {
            return 'support_call';
        }
        if ( false !== strpos( $text, 'kredi' ) || false !== strpos( $text, 'finansman' ) ) {
            return 'credit_support';
        }
        return 'grant_call';
    }

    private static function infer_audience( $text ) {
        $audience = array();
        if ( false !== strpos( $text, 'kobi' ) ) {
            $audience[] = 'sme';
        }
        if ( false !== strpos( $text, 'sirket' ) || false !== strpos( $text, 'firma' ) || false !== strpos( $text, 'kurulus' ) ) {
            $audience[] = 'company';
        }
        if ( false !== strpos( $text, 'universite' ) || false !== strpos( $text, 'arastirma merkezi' ) || false !== strpos( $text, 'arastirma enstitusu' ) ) {
            $audience[] = 'research_institution';
        }
        if ( false !== strpos( $text, 'konsorsiyum' ) ) {
            $audience[] = 'consortium';
        }
        return $audience ? array_values( array_unique( $audience ) ) : array( 'tubitak_eligible_organization' );
    }

    private static function extract_amount( $text ) {
        if ( preg_match( '/(?:hibe|destek)[^\.]{0,100}?(?:en\s+fazla|azami)\s*([\d\.,]+)\s*(M|milyon|bin)?\s*TL/iu', $text, $m ) ) {
            $unit = ! empty( $m[2] ) ? ' ' . $m[2] : '';
            return trim( $m[1] . $unit . ' TL’ye kadar' );
        }
        return '';
    }

    private static function extract_application_url( $dom, $source_url ) {
        $xpath = new DOMXPath( $dom );
        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $label = self::normalized_text( $anchor->textContent );
            if ( false === strpos( $label, 'basvuru' ) && false === strpos( $label, 'prodis' ) ) {
                continue;
            }
            $url = self::absolute_url( trim( (string) $anchor->getAttribute( 'href' ) ), $source_url );
            if ( ! $url ) {
                continue;
            }
            $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            if ( $host && ( 'tubitak.gov.tr' === $host || 'www.tubitak.gov.tr' === $host || self::ends_with( $host, '.tubitak.gov.tr' ) ) ) {
                return $url;
            }
        }
        return '';
    }

    private static function extract_heading( $dom, $fallback ) {
        $xpath = new DOMXPath( $dom );
        foreach ( $xpath->query( '//h1' ) as $node ) {
            $text = self::clean_text( $node->textContent );
            if ( $text ) {
                return $text;
            }
        }
        return self::clean_text( $fallback );
    }

    private static function document_text( $dom ) {
        $xpath = new DOMXPath( $dom );
        $body  = $xpath->query( '//body' )->item( 0 );
        return $body ? self::clean_text( $body->textContent ) : '';
    }

    private static function fetch_html( $url ) {
        if ( ! self::allowed_tubitak_url( $url ) ) {
            return new WP_Error( 'tubitak_host_blocked', 'TÜBİTAK URL allowlist dışında.' );
        }
        $response = wp_safe_remote_get( $url, array(
            'timeout'     => 15,
            'redirection' => 3,
            'user-agent'  => 'SektorelAjanda/1.52 (+https://sektorelajanda.com)',
            'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'tubitak_http_error', 'HTTP ' . $code );
        }
        if ( strlen( $body ) < 200 ) {
            return new WP_Error( 'tubitak_empty_body', 'TÜBİTAK sayfa gövdesi boş veya yetersiz.' );
        }
        return $body;
    }

    private static function allowed_tubitak_url( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        return $host && ( 'tubitak.gov.tr' === $host || 'www.tubitak.gov.tr' === $host );
    }

    private static function is_tubitak_call_url( $url ) {
        if ( ! self::allowed_tubitak_url( $url ) ) {
            return false;
        }
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        return false !== strpos( $path, '/cagri-' );
    }

    private static function load_dom( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'tubitak_dom_missing', 'PHP DOM uzantısı bulunamadı.' );
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        return $loaded ? $dom : new WP_Error( 'tubitak_dom_invalid', 'TÜBİTAK HTML DOM oluşturulamadı.' );
    }

    private static function named_month_date( $day, $month, $year ) {
        $months = array(
            'oca' => 1, 'ocak' => 1, 'jan' => 1, 'january' => 1,
            'sub' => 2, 'subat' => 2, 'feb' => 2, 'february' => 2,
            'mar' => 3, 'mart' => 3, 'march' => 3,
            'nis' => 4, 'nisan' => 4, 'apr' => 4, 'april' => 4,
            'may' => 5, 'mayis' => 5,
            'haz' => 6, 'haziran' => 6, 'jun' => 6, 'june' => 6,
            'tem' => 7, 'temmuz' => 7, 'jul' => 7, 'july' => 7,
            'agu' => 8, 'agustos' => 8, 'aug' => 8, 'august' => 8,
            'eyl' => 9, 'eylul' => 9, 'sep' => 9, 'september' => 9,
            'eki' => 10, 'ekim' => 10, 'oct' => 10, 'october' => 10,
            'kas' => 11, 'kasim' => 11, 'nov' => 11, 'november' => 11,
            'ara' => 12, 'aralik' => 12, 'dec' => 12, 'december' => 12,
        );
        $key = str_replace( '.', '', self::normalized_text( $month ) );
        if ( ! isset( $months[ $key ] ) ) {
            return '';
        }
        $day = absint( $day );
        $year = absint( $year );
        if ( ! checkdate( $months[ $key ], $day, $year ) ) {
            return '';
        }
        return sprintf( '%04d-%02d-%02d', $year, $months[ $key ], $day );
    }

    private static function absolute_url( $href, $base_url ) {
        $href = html_entity_decode( trim( (string) $href ), ENT_QUOTES, 'UTF-8' );
        if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'javascript:' ) || 0 === strpos( $href, 'mailto:' ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $href ) ) {
            return esc_url_raw( $href, array( 'http', 'https' ) );
        }
        $base = wp_parse_url( $base_url );
        if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
            return '';
        }
        if ( 0 === strpos( $href, '/' ) ) {
            return esc_url_raw( $base['scheme'] . '://' . $base['host'] . $href, array( 'http', 'https' ) );
        }
        $path = isset( $base['path'] ) ? $base['path'] : '/';
        return esc_url_raw( $base['scheme'] . '://' . $base['host'] . trailingslashit( dirname( $path ) ) . $href, array( 'http', 'https' ) );
    }

    private static function deadline_title( $title ) {
        return false !== strpos( self::normalized_text( $title ), 'son basvuru' ) ? $title : $title . ' — Son Başvuru';
    }

    private static function display_date( $date ) {
        return substr( $date, 8, 2 ) . '.' . substr( $date, 5, 2 ) . '.' . substr( $date, 0, 4 );
    }

    private static function url_key( $url ) {
        return untrailingslashit( strtolower( esc_url_raw( (string) $url, array( 'http', 'https' ) ) ) );
    }

    private static function normalized_text( $text ) {
        $text = strtolower( remove_accents( self::clean_text( $text ) ) );
        $text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = str_replace( array( "\xC2\xA0", "\r", "\n", "\t" ), ' ', $value );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }

    private static function ends_with( $haystack, $needle ) {
        return '' === $needle || substr( $haystack, -strlen( $needle ) ) === $needle;
    }
}
