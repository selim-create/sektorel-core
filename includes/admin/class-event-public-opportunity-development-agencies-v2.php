<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Kalkınma Ajansları public-opportunity provider (card-scoped parser).
 *
 * The official /en/supports page contains an agency filter before the actual
 * support cards. Parsing the whole body can therefore merge filter labels into
 * a programme title. This provider reads only api.ka.gov.tr/redirect support
 * anchors and parses each anchor inside its own DOM scope.
 */
class Sektorel_Event_Public_Opportunity_Development_Agencies_V2 {

    const INDEX_URL       = 'https://ka.gov.tr/en/supports';
    const APPLICATION_URL = 'https://kaysuygulama.sanayi.gov.tr/';
    const DATE_BASIS      = 'live_development_agencies_central_supports';

    public static function discover( $year ) {
        self::quarantine_legacy_bad_drafts();

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

        $cards = self::parse_cards( $html );
        if ( is_wp_error( $cards ) ) {
            $result['errors'][] = $cards->get_error_message();
            return $result;
        }

        $result['stats']['links'] = count( $cards );

        $today       = current_time( 'Y-m-d' );
        $horizon_end = sprintf( '%04d-12-31', absint( $year ) );
        $rows        = array();

        foreach ( $cards as $entry ) {
            if ( $entry['deadline'] < $today || $entry['start'] > $horizon_end ) {
                continue;
            }

            $fingerprint = self::normalized_text(
                $entry['agency'] . ' ' . $entry['title'] . ' ' . substr( $entry['start'], 0, 4 )
            );
            $key       = substr( md5( $fingerprint ), 0, 20 );
            $title_key = self::normalized_text( $entry['title'] );
            $kind      = self::infer_kind( $title_key );
            $audience  = self::infer_audience( $title_key );

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
                'source_url'           => esc_url_raw( $entry['source_url'], array( 'http', 'https' ) ),
                'application_url'      => self::APPLICATION_URL,
                'amount'               => '',
                'date_basis'           => self::DATE_BASIS,
            );
        }

        $result['rows'] = array_values( $rows );
        $result['stats']['verified'] = count( $rows );
        return $result;
    }

    private static function parse_cards( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'development_agency_dom_unavailable', 'Kalkınma Ajansları DOM ayrıştırıcısı kullanılamıyor.' );
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return new WP_Error( 'development_agency_dom_invalid', 'Kalkınma Ajansları destek listesi ayrıştırılamadı.' );
        }

        $xpath    = new DOMXPath( $dom );
        $anchors  = $xpath->query( '//a[@href]' );
        $entries  = array();
        $seen_url = array();

        if ( ! $anchors ) {
            return $entries;
        }

        foreach ( $anchors as $anchor ) {
            $href = html_entity_decode( trim( (string) $anchor->getAttribute( 'href' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( ! self::is_support_card_url( $href ) ) {
                continue;
            }

            $url_key = strtolower( $href );
            if ( isset( $seen_url[ $url_key ] ) ) {
                continue;
            }
            $seen_url[ $url_key ] = true;

            $card_text = self::clean_text( $anchor->textContent );
            $entry     = self::parse_card_text( $card_text, $href );
            if ( $entry ) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private static function parse_card_text( $text, $source_url ) {
        $text = self::clean_text( $text );
        if ( ! $text || false !== stripos( $text, 'Filter applied' ) ) {
            return null;
        }

        $agencies = self::agency_names();
        usort( $agencies, static function ( $a, $b ) {
            return strlen( $b ) <=> strlen( $a );
        } );

        foreach ( $agencies as $agency ) {
            if ( 0 !== strpos( $text, $agency . ' ' ) ) {
                continue;
            }

            $rest = trim( substr( $text, strlen( $agency ) ) );
            $pattern = '/^(.{5,500}?)\s+Starting\s+date\s+(\d{1,2})\s+([\p{L}]+)\s+(\d{4})\s+End\s+Date\s+(\d{1,2})\s+([\p{L}]+)\s+(\d{4})$/iu';
            if ( ! preg_match( $pattern, $rest, $match ) ) {
                return null;
            }

            $title = self::clean_text( $match[1] );
            if ( ! $title || self::title_contains_agency_name( $title ) ) {
                return null;
            }

            $normalized_title = self::normalized_text( $title );
            if ( self::is_non_application_result( $normalized_title ) ) {
                return null;
            }

            $start    = self::named_month_date( $match[2], $match[3], $match[4] );
            $deadline = self::named_month_date( $match[5], $match[6], $match[7] );
            if ( ! $start || ! $deadline || $deadline < $start ) {
                return null;
            }

            return array(
                'agency'     => $agency,
                'title'      => $title,
                'start'      => $start,
                'deadline'   => $deadline,
                'source_url' => $source_url,
            );
        }

        return null;
    }

    private static function is_support_card_url( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        return 'api.ka.gov.tr' === $host && 0 === strpos( $path, '/redirect/' );
    }

    private static function title_contains_agency_name( $title ) {
        $normalized = self::normalized_text( $title );
        foreach ( self::agency_names() as $agency ) {
            if ( false !== strpos( $normalized, self::normalized_text( $agency ) ) ) {
                return true;
            }
        }
        return false;
    }

    private static function quarantine_legacy_bad_drafts() {
        $ids = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => 'draft',
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'opportunity_managed', 'value' => '1' ),
                array( 'key' => 'opportunity_provider', 'value' => 'development_agencies' ),
                array( 'key' => 'opportunity_date_basis', 'value' => self::DATE_BASIS ),
            ),
        ) );

        foreach ( (array) $ids as $event_id ) {
            $title = (string) get_the_title( $event_id );
            if ( ! self::legacy_title_is_corrupt( $title ) ) {
                continue;
            }
            wp_trash_post( absint( $event_id ) );
        }
    }

    private static function legacy_title_is_corrupt( $title ) {
        $normalized = self::normalized_text( $title );
        if ( false !== strpos( $normalized, 'filter applied' ) ) {
            return true;
        }

        $hits = 0;
        foreach ( self::agency_names() as $agency ) {
            if ( false !== strpos( $normalized, self::normalized_text( $agency ) ) ) {
                $hits++;
                if ( $hits > 1 ) {
                    return true;
                }
            }
        }
        return false;
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
            'user-agent'  => 'SektorelAjanda/1.54.1 (+https://sektorelajanda.com)',
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
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }
}
