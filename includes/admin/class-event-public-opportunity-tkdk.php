<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * TKDK / IPARD bounded public-opportunity provider.
 *
 * TKDK publishes the call identity/measure on an HTML announcement, while the
 * exact application dates live in the official call PDF. Production does not
 * attempt brittle PDF text extraction. Instead, a small verified call registry
 * supplies exact dates only after the matching live TKDK announcement is
 * fetched and its call/measure markers are confirmed. Unknown calls fail closed.
 */
class Sektorel_Event_Public_Opportunity_TKDK {

    const APPLICATION_URL = 'https://www.tkdk.gov.tr/ProjeIslemleri/';
    const DATE_BASIS      = 'live_tkdk_verified_call_announcement';

    public static function discover( $year ) {
        $result = array(
            'rows'   => array(),
            'errors' => array(),
            'stats'  => array( 'links' => 0, 'verified' => 0 ),
        );

        $today = current_time( 'Y-m-d' );

        foreach ( self::verified_calls() as $call ) {
            if ( (int) $call['year'] !== (int) $year ) {
                continue;
            }

            $html = self::fetch_html( $call['announcement_url'] );
            if ( is_wp_error( $html ) ) {
                $result['errors'][] = 'TKDK ' . absint( $call['call_number'] ) . '. çağrı duyurusu alınamadı: ' . $html->get_error_message();
                continue;
            }

            $result['stats']['links']++;
            $text = self::normalized_text( self::document_text_from_html( $html ) );
            if ( ! self::announcement_matches_call( $text, $call ) ) {
                $result['errors'][] = 'TKDK ' . absint( $call['call_number'] ) . '. çağrı duyurusu doğrulama işaretleriyle eşleşmedi; kayıt güvenli biçimde atlandı.';
                continue;
            }

            if ( $call['deadline'] < $today ) {
                continue;
            }

            $result['rows'][] = self::row_from_call( $call, $today );
        }

        $result['stats']['verified'] = count( $result['rows'] );
        return $result;
    }

    private static function verified_calls() {
        return array(
            array(
                'year'             => 2026,
                'call_number'      => 11,
                'occurrence_key'   => 'tkdk_ipard3_call_11_2026',
                'title'            => 'TKDK IPARD III 11. Başvuru Çağrısı — Tarım ve Balıkçılık Ürünlerinin İşlenmesi ve Pazarlanmasına Yönelik Yatırımlar — Son Başvuru',
                'measure'          => 'Tarım ve Balıkçılık Ürünlerinin İşlenmesi ve Pazarlanması ile ilgili Fiziki Varlıklara Yönelik Yatırımlar',
                'announcement_url' => 'https://tkdk.gov.tr/Duyuru/ipard-iii-programi-on-birinci-basvuru-cagri-ilani-yayimlandi-13094?lang=tr',
                'pdf_url'          => 'https://tkdk.gov.tr/Content/File/BasvuruFiles/BasvuruCagriIlani/IPARDIII/IPARDIII_OnbirinciCagriIlani.pdf',
                'start'            => '2026-06-12',
                'online_close'     => '2026-07-13',
                'deadline'         => '2026-07-20',
                'call_marker'      => 'on birinci basvuru cagri',
                'measure_marker'   => 'tarim ve balikcilik urunlerinin islenmesi ve pazarlanmasi',
                'audience'         => array( 'company', 'cooperative', 'rural_or_agri_actor' ),
            ),
            array(
                'year'             => 2026,
                'call_number'      => 12,
                'occurrence_key'   => 'tkdk_ipard3_call_12_2026',
                'title'            => 'TKDK IPARD III 12. Başvuru Çağrısı — Tarımsal İşletmelerin Fiziki Varlıklarına Yönelik Yatırımlar — Son Başvuru',
                'measure'          => 'Tarımsal İşletmelerin Fiziki Varlıklarına Yönelik Yatırımlar',
                'announcement_url' => 'https://www.tkdk.gov.tr/Duyuru/ipard-iii-program-12th-application-call-announcement-has-been-published-13111',
                'pdf_url'          => 'https://tkdk.gov.tr/Content/File/BasvuruFiles/BasvuruCagriIlani/IPARDIII/IPARDIII_OnikinciCagriIlani.pdf',
                'start'            => '2026-07-28',
                'online_close'     => '2026-08-31',
                'deadline'         => '2026-09-07',
                'call_marker'      => 'on ikinci basvuru cagri',
                'measure_marker'   => 'tarimsal isletmelerin fiziki varliklarina yonelik yatirimlar',
                'audience'         => array( 'company', 'cooperative', 'rural_or_agri_actor' ),
            ),
        );
    }

    private static function announcement_matches_call( $text, $call ) {
        if ( ! $text ) {
            return false;
        }
        if ( false === strpos( $text, 'ipard iii' ) ) {
            return false;
        }
        if ( false === strpos( $text, self::normalized_text( $call['call_marker'] ) ) ) {
            return false;
        }
        if ( false === strpos( $text, self::normalized_text( $call['measure_marker'] ) ) ) {
            return false;
        }
        if ( false === strpos( $text, 'basvurular kabul edilecektir' ) && false === strpos( $text, 'basvuru cagri ilani' ) ) {
            return false;
        }
        if ( self::contains_any( $text, array( 'desteklenmek uzere secilen', 'siralamasi aciklanmistir', 'on incelemesi tamamlanmis', 'reddine iliskin' ) ) ) {
            return false;
        }
        return true;
    }

    private static function row_from_call( $call, $today ) {
        $status = $call['start'] > $today ? 'upcoming' : 'open';
        return array(
            'occurrence_key'       => sanitize_key( $call['occurrence_key'] ),
            'title'                => sanitize_text_field( $call['title'] ),
            'application_start'    => $call['start'],
            'application_deadline' => $call['deadline'],
            'provider'             => 'tkdk',
            'provider_name'        => 'Tarım ve Kırsal Kalkınmayı Destekleme Kurumu (TKDK)',
            'kind'                 => 'grant_call',
            'audience'             => array_values( array_unique( array_map( 'sanitize_key', $call['audience'] ) ) ),
            'description'          => sanitize_textarea_field(
                'TKDK IPARD III ' . absint( $call['call_number'] ) . '. Başvuru Çağrısı kapsamında “' . $call['measure']'] . '” tedbirinden başvurular kabul edilmektedir. '
                . 'Doğrulanmış başvuru dönemi ' . self::display_date( $call['start'] ) . ' – ' . self::display_date( $call['deadline'] ) . ' arasındadır. '
                . 'Online Proje Başvuru Sistemi ' . self::display_date( $call['online_close'] ) . ' tarihinde kapanır; nihai başvuru paketi son teslim tarihi Event son tarihi olarak esas alınır.'
            ),
            'source_url'           => esc_url_raw( $call['pdf_url'], array( 'http', 'https' ) ),
            'application_url'      => self::APPLICATION_URL,
            'amount'               => '',
            'date_basis'           => self::DATE_BASIS,
            'status'               => $status,
        );
    }

    private static function fetch_html( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( ! in_array( $host, array( 'tkdk.gov.tr', 'www.tkdk.gov.tr' ), true ) ) {
            return new WP_Error( 'tkdk_host_blocked', 'TKDK URL allowlist dışında.' );
        }

        $response = wp_safe_remote_get( $url, array(
            'timeout'     => 15,
            'redirection' => 3,
            'user-agent'  => 'SektorelAjanda/1.55.0 (+https://sektorelajanda.com)',
            'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'tkdk_http_error', 'HTTP ' . $code );
        }
        if ( strlen( $body ) < 200 ) {
            return new WP_Error( 'tkdk_empty_body', 'TKDK duyuru gövdesi boş veya yetersiz.' );
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

    private static function contains_any( $text, $needles ) {
        foreach ( (array) $needles as $needle ) {
            if ( false !== strpos( $text, self::normalized_text( $needle ) ) ) {
                return true;
            }
        }
        return false;
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
