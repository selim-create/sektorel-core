<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Kalkınma Ajansları public-opportunity provider.
 *
 * Uses the official Kalkınma Ajansları Genel Müdürlüğü central support list as
 * the canonical live discovery surface. The current public site serves the
 * support catalogue from /en/supports and labels dates as “Starting date” /
 * “End Date”, while agency/program names remain Turkish.
 */
class Sektorel_Event_Public_Opportunity_Development_Agencies {

    const INDEX_URL       = 'https://www.ka.gov.tr/en/supports';
    const APPLICATION_URL = 'https://kaysuygulama.sanayi.gov.tr/';

    public static function discover( $year ) {
        $result = array(
            'rows'   => array(),
            'errors' => array(),
            'stats'  => array( 'links' => 0, 'verified' => 0 ),
        );

        $html = self::fetch_html( self::INDEX_URL );
        if ( is_wp_error( $html ) ) {
            $result['errors'][] = 'Kalkınma Ajansları destek listesi alınamadı: ' . $html->get_error_message();
            return $result;
        }

        $text = self::document_text_from_html( $html );
        if ( ! $text ) {
            $result['errors'][] = 'Kalkınma Ajansları destek listesi ayrıştırılamadı.';
            return $result;
        }

        $entries = self::parse_entries( $text );
        $result['stats']['links'] = count( $entries );

        $today       = current_time( 'Y-m-d' );
        $horizon_end = sprintf( '%04d-12-31', absint( $year ) );
        $rows        = array();

        foreach ( $entries as $entry ) {
            if ( $entry['deadline'] < $today || $entry['start'] > $horizon_end ) {
                continue;
            }

            // Keep identity stable if the agency later extends the deadline.
            // The programme title + agency + occurrence year identify the call;
            // exact start/deadline values are intentionally excluded.
            $fingerprint = self::normalized_text(
                $entry['agency'] . ' ' . $entry['title'] . ' ' . substr( $entry['start'], 0, 4 )
            );
            $key        = substr( md5( $fingerprint ), 0, 20 );
            $source_url = self::INDEX_URL . '?search=' . rawurlencode( $entry['title'] );
            $kind       = self::infer_kind( self::normalized_text( $entry['title'] ) );
            $audience   = self::infer_audience( self::normalized_text( $entry['title'] ) );

            $rows[ $key ] = array(
                'occurrence_key'       => 'development_agency_' . $key,
                'title'                => sanitize_text_field( self::deadline_title( $entry['title'] ) ),
                'application_start'    => $entry['start'],
                'application_deadline' => $entry['deadline'],
                'provider'             => 'development_agencies',
                'provider_name'        => sanitize_text_field( $entry['agency'] ),
                'kind'                 => $kind,
                'audience'             => $audience,
                'description'          => sanitize_textarea_field(
                    $entry['agency'] . ' tarafından yürütülen destek programının Kalkınma Ajansları Genel Müdürlüğü merkezî destek listesinde doğrulanan başvuru dönemi '
                    . self::display_date( $entry['start'] ) . ' – ' . self::display_date( $entry['deadline'] )
                    . ' arasındadır. Uygun başvuru sahipleri ve program koşulları ilgili ajansın resmî program/başvuru rehberinden doğrulanmalıdır.'
                ),
                'source_url'           => esc_url_raw( $source_url, array( 'http', 'https' ) ),
                'application_url'      => self::APPLICATION_URL,
                'amount'               => '',
                'date_basis'           => 'live_development_agencies_central_supports',
            );
        }

        $result['rows'] = array_values( $rows );
        $result['stats']['verified'] = count( $rows );
        return $result;
    }

    private static function parse_entries( $text ) {
        $entries  = array();
        $agencies = self::agency_names();
        $escaped  = array_map( static function ( $agency ) {
            return preg_quote( $agency, '/' );
        }, $agencies );

        // Current official surface format:
        // Agency Programme Starting date 06 August 2026 End Date 31 December 2026
        // Programme names may themselves contain dates/years, therefore agency
        // and explicit date labels are the deterministic boundaries.
        $pattern = '/(' . implode( '|', $escaped ) . ')\s+(.{5,700}?)\s+Starting\s+date\s+(\d{1,2})\s+([\p{L}]+)\s+(\d{4})\s+End\s+Date\s+(\d{1,2})\s+([\p{L}]+)\s+(\d{4})/iu';

        if ( ! preg_match_all( $pattern, $text, $matches, PREG_SET_ORDER ) ) {
            return $entries;
        }

        foreach ( $matches as $match ) {
            $agency = self::clean_text( $match[1] );
            $title  = self::clean_text( $match[2] );
            if ( ! $agency || ! $title ) {
                continue;
            }

            $normalized_title = self::normalized_text( $title );
            if ( self::is_non_application_result( $normalized_title ) ) {
                continue;
            }

            $start    = self::named_month_date( $match[3], $match[4], $match[5] );
            $deadline = self::named_month_date( $match[6], $match[7], $match[8] );
            if ( ! $start || ! $deadline || $deadline < $start ) {
                continue;
            }

            $entries[] = array(
                'agency'   => $agency,
                'title'    => $title,
                'start'    => $start,
                'deadline' => $deadline,
            );
        }

        return $entries;
    }

    private static function is_non_application_result( $title ) {
        foreach ( array(
            'sonuclari aciklandi',
            'sonuclari ilan edildi',
            'degerlendirme sonuclari',
            'basvuru sonuclari',
            'results announced',
            'evaluation results',
        ) as $noise ) {
            if ( false !== strpos( $title, $noise ) ) {
                return true;
            }
        }
        return false;
    }

    private static function infer_kind( $title ) {
        if ( false !== strpos( $title, 'faizsiz kredi' ) || false !== strpos( $title, 'interest free loan' ) || false !== strpos( $title, 'finansman destegi' ) || false !== strpos( $title, 'geri odemeli' ) ) {
            return 'credit_support';
        }
        if ( false !== strpos( $title, 'mali destek' ) || false !== strpos( $title, 'hibe' ) || false !== strpos( $title, 'financial support' ) ) {
            return 'grant_call';
        }
        return 'support_call';
    }

    private static function infer_audience( $title ) {
        $audience = array();
        if ( false !== strpos( $title, 'girisim' ) || false !== strpos( $title, 'entrepreneur' ) ) {
            $audience[] = 'entrepreneur';
        }
        if ( false !== strpos( $title, 'imalat' ) || false !== strpos( $title, 'sanayi' ) || false !== strpos( $title, 'isletme' ) || false !== strpos( $title, 'industry' ) ) {
            $audience[] = 'company';
        }
        if ( false !== strpos( $title, 'sivil toplum' ) || false !== strpos( $title, 'civil society' ) ) {
            $audience[] = 'civil_society';
        }
        if ( false !== strpos( $title, 'kirsal' ) || false !== strpos( $title, 'tarim' ) || false !== strpos( $title, 'rural' ) || false !== strpos( $title, 'agri' ) ) {
            $audience[] = 'rural_or_agri_actor';
        }
        return $audience ? array_values( array_unique( $audience ) ) : array( 'development_agency_eligible_applicant' );
    }

    private static function agency_names() {
        return array(
            'Kalkınma Ajansları Genel Müdürlüğü',
            'Bursa Eskişehir Bilecik Kalkınma Ajansı',
            'Kuzey Doğu Anadolu Kalkınma Ajansı',
            'Kuzeydoğu Anadolu Kalkınma Ajansı',
            'Orta Karadeniz Kalkınma Ajansı',
            'Doğu Akdeniz Kalkınma Ajansı',
            'Batı Karadeniz Kalkınma Ajansı',
            'Güney Marmara Kalkınma Ajansı',
            'Kuzey Anadolu Kalkınma Ajansı',
            'Doğu Anadolu Kalkınma Ajansı',
            'Doğu Karadeniz Kalkınma Ajansı',
            'Doğu Marmara Kalkınma Ajansı',
            'Güney Ege Kalkınma Ajansı',
            'Batı Akdeniz Kalkınma Ajansı',
            'Orta Anadolu Kalkınma Ajansı',
            'İstanbul Kalkınma Ajansı',
            'Ankara Kalkınma Ajansı',
            'Karacadağ Kalkınma Ajansı',
            'Çukurova Kalkınma Ajansı',
            'Dicle Kalkınma Ajansı',
            'İpek Yolu Kalkınma Ajansı',
            'İpekyolu Kalkınma Ajansı',
            'İzmir Kalkınma Ajansı',
            'Mevlana Kalkınma Ajansı',
            'Fırat Kalkınma Ajansı',
            'Serhat Kalkınma Ajansı',
            'Trakya Kalkinma Ajansi',
            'Trakya Kalkınma Ajansı',
            'Zafer Kalkınma Ajansı',
            'Ahiler Kalkınma Ajansı'
        );
    }

    private static function fetch_html( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( ! in_array( $host, array( 'ka.gov.tr', 'www.ka.gov.tr' ), true ) ) {
            return new WP_Error( 'development_agency_host_blocked', 'Kalkınma Ajansları URL allowlist dışında.' );
        }

        $response = wp_safe_remote_get( $url, array(
            'timeout'     => 15,
            'redirection' => 3,
            'user-agent'  => 'SektorelAjanda/1.52.1 (+https://sektorelajanda.com)',
            'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'development_agency_http_error', 'HTTP ' . $code );
        }
        if ( strlen( $body ) < 200 ) {
            return new WP_Error( 'development_agency_empty_body', 'Kalkınma Ajansları sayfa gövdesi boş veya yetersiz.' );
        }
        return $body;
    }

    private static function document_text_from_html( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return '';
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return '';
        }
        $xpath = new DOMXPath( $dom );
        $body  = $xpath->query( '//body' )->item( 0 );
        return $body ? self::clean_text( $body->textContent ) : '';
    }

    private static function named_month_date( $day, $month, $year ) {
        $months = array(
            'january' => 1, 'jan' => 1, 'ocak' => 1,
            'february' => 2, 'feb' => 2, 'subat' => 2,
            'march' => 3, 'mar' => 3, 'mart' => 3,
            'april' => 4, 'apr' => 4, 'nisan' => 4,
            'may' => 5, 'mayis' => 5,
            'june' => 6, 'jun' => 6, 'haziran' => 6,
            'july' => 7, 'jul' => 7, 'temmuz' => 7,
            'august' => 8, 'aug' => 8, 'agustos' => 8,
            'september' => 9, 'sep' => 9, 'eylul' => 9,
            'october' => 10, 'oct' => 10, 'ekim' => 10,
            'november' => 11, 'nov' => 11, 'kasim' => 11,
            'december' => 12, 'dec' => 12, 'aralik' => 12,
        );
        $key = str_replace( ' ', '', self::normalized_text( $month ) );
        if ( ! isset( $months[ $key ] ) ) {
            return '';
        }
        $day  = absint( $day );
        $year = absint( $year );
        if ( ! checkdate( $months[ $key ], $day, $year ) ) {
            return '';
        }
        return sprintf( '%04d-%02d-%02d', $year, $months[ $key ], $day );
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
        $value = str_replace( array( "\xC2\xA0", "\r", "\n", "\t" ), ' ', $value );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }
}
