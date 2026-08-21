<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Digital Europe Programme open-call provider.
 *
 * The Ministry's official Open Calls page remains the preferred live surface.
 * When that page is reachable but its client-rendered cards are absent from the
 * visible DOM response, the provider falls back to the bounded 2026 call registry
 * that was verified from the same official surface. The fallback expires with
 * the verified 1 October 2026 deadline and never guesses future calls.
 *
 * If the visible DOM exposes DIGITAL topic codes, each materialized opportunity
 * must still match its exact verified code, title and deadline in the same call
 * block. Unknown live codes are reported and fail closed until verified.
 */
class Sektorel_Event_Public_Opportunity_Digital_Europe {

    const INDEX_URL             = 'https://dijitalavrupa.sanayi.gov.tr/acik-cagrilar';
    const APPLICATION_URL       = 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/home';
    const LIVE_DATE_BASIS       = 'live_digital_europe_open_calls';
    const VERIFIED_DATE_BASIS   = 'verified_digital_europe_2026_call_pack';
    const VERIFIED_DEADLINE     = '2026-10-01';
    const VERIFIED_START        = '2026-04-21';

    public static function discover( $year ) {
        $result = array(
            'rows'   => array(),
            'errors' => array(),
            'stats'  => array( 'links' => 0, 'verified' => 0, 'fallback' => 0 ),
        );

        if ( 2026 !== (int) $year ) {
            return $result;
        }

        $today = current_time( 'Y-m-d' );
        if ( self::VERIFIED_DEADLINE < $today ) {
            return $result;
        }

        $calls = self::verified_calls();
        $index = self::fetch_html( self::INDEX_URL );

        if ( is_wp_error( $index ) ) {
            $result['errors'][] = 'Dijital Avrupa Açık Çağrılar sayfası alınamadı; doğrulanmış 2026 çağrı paketi kullanıldı: ' . $index->get_error_message();
            $result['rows'] = self::verified_fallback_rows( $calls, $today );
            $result['stats']['links'] = count( $result['rows'] );
            $result['stats']['verified'] = count( $result['rows'] );
            $result['stats']['fallback'] = count( $result['rows'] );
            return $result;
        }

        $raw_index_text = self::clean_text( self::document_text_from_html( $index ) );
        $known_codes = array();
        foreach ( $calls as $call ) {
            $known_codes[] = strtolower( $call['code'] );
        }

        preg_match_all( '/digital-2026-[a-z0-9-]+/i', strtolower( $raw_index_text ), $matches );
        $live_codes = array_values( array_unique( isset( $matches[0] ) ? $matches[0] : array() ) );

        // The Ministry surface can render call cards client-side. Script/hydration
        // payloads are intentionally excluded from document_text_from_html(). If
        // no topic code exists in the visible DOM, use only the already verified,
        // hard-bounded 2026 registry rather than treating hydration JSON as live UI.
        if ( empty( $live_codes ) ) {
            $result['rows'] = self::verified_fallback_rows( $calls, $today );
            $result['stats']['links'] = count( $result['rows'] );
            $result['stats']['verified'] = count( $result['rows'] );
            $result['stats']['fallback'] = count( $result['rows'] );
            return $result;
        }

        $result['stats']['links'] = count( $live_codes );

        foreach ( $live_codes as $live_code ) {
            if ( ! in_array( strtolower( $live_code ), $known_codes, true ) ) {
                $result['errors'][] = 'Dijital Avrupa Açık Çağrılar sayfasında henüz doğrulanmamış yeni topic kodu görüldü: ' . sanitize_text_field( strtoupper( $live_code ) );
            }
        }

        foreach ( $calls as $call ) {
            $block = self::call_block( $raw_index_text, $call['code'], $known_codes );
            if ( '' === $block ) {
                continue;
            }

            $block_key = self::normalized_text( $block );
            if ( false === strpos( $block_key, self::normalized_text( $call['source_title'] ) ) ) {
                $result['errors'][] = 'Dijital Avrupa topic kodu bulundu ancak aynı çağrı bloğunda başlık eşleşmedi: ' . sanitize_text_field( $call['code'] );
                continue;
            }
            if ( false === strpos( $block_key, 'submission deadline 1 october 2026' ) ) {
                $result['errors'][] = 'Dijital Avrupa topic kodu bulundu ancak aynı çağrı bloğunda 1 Ekim 2026 son başvurusu doğrulanamadı: ' . sanitize_text_field( $call['code'] );
                continue;
            }

            $result['rows'][] = self::row_from_call( $call, $today, self::LIVE_DATE_BASIS );
        }

        $result['stats']['verified'] = count( $result['rows'] );
        return $result;
    }

    private static function verified_fallback_rows( $calls, $today ) {
        $rows = array();
        foreach ( (array) $calls as $call ) {
            if ( ! is_array( $call ) || empty( $call['code'] ) ) {
                continue;
            }
            $rows[] = self::row_from_call( $call, $today, self::VERIFIED_DATE_BASIS );
        }
        return $rows;
    }

    private static function verified_calls() {
        return array(
            array(
                'code'         => 'DIGITAL-2026-AI-DATA-10-COMPLIANCE',
                'source_title' => 'Digital Solutions for Regulatory Compliance through Data',
                'title'        => 'Dijital Avrupa 2026 — Veri Yoluyla Mevzuat Uyumu için Dijital Çözümler — Son Başvuru',
                'amount'       => '8,5 milyon € toplam hibe · 2 proje · %50 eş finansman',
                'audience'     => array( 'company', 'sme', 'research_institution', 'consortium' ),
                'description'  => 'Veri ve yapay zekâ teknolojileriyle mevzuat uyumu, otomatik raporlama ve denetim süreçlerini kolaylaştırmaya yönelik pilot dijital çözümleri destekler. En az 3 farklı uygun ülkeden 3 kuruluşlu konsorsiyum gerekir.',
            ),
            array(
                'code'         => 'DIGITAL-2026-SKILLS-10-DIGITAL-HEALTH-STEP',
                'source_title' => 'Advanced Digital Skills for AI in Healthcare',
                'title'        => 'Dijital Avrupa 2026 — Sağlıkta Yapay Zekâ için İleri Dijital Beceriler — Son Başvuru',
                'amount'       => '7,8 milyon € toplam hibe · 2 proje · azami 3,9 milyon €/proje · %50 eş finansman',
                'audience'     => array( 'company', 'sme', 'research_institution', 'consortium' ),
                'description'  => 'Sağlık kuruluşları ve çalışanlarının yapay zekâya hazır olma düzeyini artıracak ileri dijital beceri eğitim programlarını destekler. En az 4 farklı uygun ülkeden 4 bağımsız kuruluş gerekir.',
            ),
            array(
                'code'         => 'DIGITAL-2026-SKILLS-10-EDTECH',
                'source_title' => 'EdTech Accelerator',
                'title'        => 'Dijital Avrupa 2026 — EdTech Accelerator — Son Başvuru',
                'amount'       => '2,7 milyon € · 1 proje · %100 fonlama; bütçenin en az %60’ı üçüncü taraf finansal desteği',
                'audience'     => array( 'technology_startup', 'sme', 'company', 'research_institution', 'consortium' ),
                'description'  => 'Avrupa EdTech girişimleri ve KOBİ’leri için hızlandırma, pilot uygulama ve pazara hazırlık mekanizması kurulmasını hedefler. En az 4 farklı uygun ülkeden 4 bağımsız başvuru sahibi gerekir.',
            ),
            array(
                'code'         => 'DIGITAL-2026-SKILLS-10-NATIONAL-COALITIONS',
                'source_title' => 'Digital Skills and Jobs Platform: National Coalitions for Digital Skills and Jobs',
                'title'        => 'Dijital Avrupa 2026 — Dijital Beceri ve İstihdam Platformu Ulusal Koalisyonları — Son Başvuru',
                'amount'       => '2 milyon € · 1 proje · %100 fonlama',
                'audience'     => array( 'company', 'research_institution', 'civil_society', 'consortium' ),
                'description'  => 'Dijital Beceri ve İstihdam Platformu ile ulusal koalisyonların altyapı, içerik, hizmet ve topluluk faaliyetlerini geliştirmeyi destekler. En az 4 farklı uygun ülkeden 5 bağımsız başvuru sahibi gerekir.',
            ),
            array(
                'code'         => 'DIGITAL-2026-BESTUSE-RSF-10-AWARENESS',
                'source_title' => 'Research Support Framework for Information Integrity',
                'title'        => 'Dijital Avrupa 2026 — Bilgi Bütünlüğü için Araştırma Destek Çerçevesi — Son Başvuru',
                'amount'       => '6 milyon € · 1 proje · konsorsiyum %100 / üçüncü taraf finansal desteği %50',
                'audience'     => array( 'research_institution', 'civil_society', 'company', 'consortium' ),
                'description'  => 'Bilgi bütünlüğü alanında araştırma, analiz, araç ve kapasite geliştirme için ortak bir destek çerçevesi kurulmasını hedefler. En az 4 farklı ülkeden 4 bağımsız başvuru sahibi gerekir.',
            ),
            array(
                'code'         => 'DIGITAL-2026-BESTUSE-MCP-10-HUB',
                'source_title' => 'Multi-Country Projects (MCP) Implementation Support: EDIC Hub',
                'title'        => 'Dijital Avrupa 2026 — Çok Ülkeli Projeler Uygulama Desteği: EDIC Hub — Son Başvuru',
                'amount'       => '1 milyon € · 1 proje · %100 fonlama',
                'audience'     => array( 'eligible_project_organization', 'consortium' ),
                'description'  => 'EDIC ekosistemine hukuki, operasyonel, koordinasyon ve bilgi yönetimi desteği sağlayacak bir destek merkezi kurulmasını hedefler. Başvuru sahibi mevcut bir EDIC, ERIC veya bunlardan birini içeren konsorsiyum olmalıdır.',
            ),
            array(
                'code'         => 'DIGITAL-2026-SUPPORT-10-DISSEMINATION',
                'source_title' => 'DAP Dissemination and Exploitation Support',
                'title'        => 'Dijital Avrupa 2026 — Yaygınlaştırma ve Faydalanma Desteği — Son Başvuru',
                'amount'       => '1,8 milyon € · 1 proje · %100 fonlama',
                'audience'     => array( 'company', 'sme', 'research_institution', 'civil_society' ),
                'description'  => 'Dijital Avrupa Programı sonuçlarının yaygınlaştırılması, kullanımı ve benimsenmesi için operasyonel çerçeve, araçlar, danışmanlık ve iş geliştirme desteği oluşturulmasını hedefler. Özel bir konsorsiyum şartı belirtilmemiştir.',
            ),
        );
    }

    private static function call_block( $text, $code, $known_codes ) {
        $position = stripos( $text, $code );
        if ( false === $position ) {
            return '';
        }

        $start = max( 0, $position - 220 );
        $end   = strlen( $text );
        foreach ( (array) $known_codes as $other_code ) {
            if ( 0 === strcasecmp( $other_code, $code ) ) {
                continue;
            }
            $other_position = stripos( $text, $other_code, $position + strlen( $code ) );
            if ( false !== $other_position && $other_position < $end ) {
                $end = $other_position;
            }
        }

        return trim( substr( $text, $start, max( 0, $end - $start ) ) );
    }

    private static function row_from_call( $call, $today, $date_basis ) {
        $code = sanitize_text_field( $call['code'] );
        return array(
            'occurrence_key'       => sanitize_key( strtolower( str_replace( '-', '_', $code ) ) ),
            'title'                => sanitize_text_field( $call['title'] ),
            'application_start'    => self::VERIFIED_START,
            'application_deadline' => self::VERIFIED_DEADLINE,
            'provider'             => 'digital_europe',
            'provider_name'        => 'Dijital Avrupa Programı — T.C. Sanayi ve Teknoloji Bakanlığı',
            'kind'                 => 'grant_call',
            'audience'             => array_values( array_unique( array_map( 'sanitize_key', $call['audience'] ) ) ),
            'description'          => sanitize_textarea_field( $call['description'] . ' Topic kodu: ' . $code . '.' ),
            'source_url'           => self::INDEX_URL,
            'application_url'      => self::APPLICATION_URL,
            'amount'               => sanitize_text_field( $call['amount'] ),
            'date_basis'           => sanitize_key( $date_basis ),
            'status'               => self::VERIFIED_START > $today ? 'upcoming' : 'open',
        );
    }

    private static function fetch_html( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( 'dijitalavrupa.sanayi.gov.tr' !== $host ) {
            return new WP_Error( 'digital_europe_host_blocked', 'Dijital Avrupa URL allowlist dışında.' );
        }

        $response = wp_safe_remote_get( $url, array(
            'timeout'     => 15,
            'redirection' => 3,
            'user-agent'  => 'SektorelAjanda/1.56.2 (+https://sektorelajanda.com)',
            'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'digital_europe_http_error', 'HTTP ' . $code );
        }
        if ( strlen( $body ) < 500 ) {
            return new WP_Error( 'digital_europe_empty_body', 'Dijital Avrupa sayfa gövdesi boş veya yetersiz.' );
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
        foreach ( array( '//script', '//style', '//noscript', '//template' ) as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes ) {
                continue;
            }
            for ( $index = $nodes->length - 1; $index >= 0; $index-- ) {
                $node = $nodes->item( $index );
                if ( $node && $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        $body = $xpath->query( '//body' )->item( 0 );
        return $body ? self::clean_text( $body->textContent ) : '';
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
