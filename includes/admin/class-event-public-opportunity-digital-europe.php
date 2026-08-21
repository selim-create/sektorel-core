<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Digital Europe Programme 2026 open-call provider.
 *
 * The bounded 2026 registry is verified across three complementary official
 * Ministry surfaces instead of relying on one brittle announcement or card DOM:
 *
 * 1. 21 April 2026 announcement: verifies that the 2026 call package opened.
 * 2. 30 April 2026 Info Day: verifies the seven Türkiye-open call titles and
 *    the five attached call-fiche families.
 * 3. Open Calls index: verifies the exact seven DIGITAL-2026 topic codes and
 *    the common 1 October 2026 submission deadline signal.
 *
 * Unknown/future calls fail closed. The provider expires after the verified
 * deadline. All seven rows keep occurrence-specific source locators so the
 * shared opportunity engine does not collapse them by source URL.
 */
class Sektorel_Event_Public_Opportunity_Digital_Europe {

    const ANNOUNCEMENT_URL      = 'https://dijitalavrupa.sanayi.gov.tr/announcementdetail?id=e4d7d649-5766-4ecc-c328-08dea5b6f933';
    const INFO_DAY_URL          = 'https://dijitalavrupa.sanayi.gov.tr/announcementdetail?id=af0d2eb6-32d7-4cc2-46b9-08dea6b42f89';
    const OPEN_CALLS_URL        = 'https://dijitalavrupa.sanayi.gov.tr/acik-cagrilar';
    const APPLICATION_URL       = 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/home';
    const VERIFIED_DATE_BASIS   = 'verified_digital_europe_2026_three_surface';
    const VERIFIED_DEADLINE     = '2026-10-01';
    const VERIFIED_START        = '2026-04-21';

    public static function discover( $year ) {
        $result = array(
            'rows'   => array(),
            'errors' => array(),
            'stats'  => array( 'links' => 0, 'verified' => 0 ),
        );

        if ( 2026 !== (int) $year ) {
            return $result;
        }

        $today = current_time( 'Y-m-d' );
        if ( self::VERIFIED_DEADLINE < $today ) {
            return $result;
        }

        $calls = self::verified_calls();
        $result['stats']['links'] = count( $calls );

        $announcement = self::fetch_html( self::ANNOUNCEMENT_URL );
        if ( is_wp_error( $announcement ) ) {
            $result['errors'][] = 'Dijital Avrupa 21 Nisan 2026 çağrı duyurusu alınamadı: ' . $announcement->get_error_message();
            return $result;
        }

        $info_day = self::fetch_html( self::INFO_DAY_URL );
        if ( is_wp_error( $info_day ) ) {
            $result['errors'][] = 'Dijital Avrupa 30 Nisan 2026 Bilgi Günü duyurusu alınamadı: ' . $info_day->get_error_message();
            return $result;
        }

        $open_calls = self::fetch_html( self::OPEN_CALLS_URL );
        if ( is_wp_error( $open_calls ) ) {
            $result['errors'][] = 'Dijital Avrupa Açık Çağrılar sayfası alınamadı: ' . $open_calls->get_error_message();
            return $result;
        }

        $announcement_raw = self::document_text_from_html( $announcement );
        $info_day_raw     = self::document_text_from_html( $info_day );
        $open_calls_raw   = self::document_text_from_html( $open_calls );

        $announcement_text = self::normalized_text( $announcement_raw );
        $info_day_text     = self::normalized_text( $info_day_raw );
        $open_calls_text   = self::normalized_text( $open_calls_raw );

        if ( ! self::announcement_is_verified( $announcement_text ) ) {
            $result['errors'][] = 'Dijital Avrupa 21 Nisan 2026 duyurusunda 2026 çağrı paketinin açılışı doğrulanamadı.';
            return $result;
        }

        $missing_titles = self::missing_info_day_titles( $info_day_text );
        if ( $missing_titles ) {
            foreach ( $missing_titles as $missing_title ) {
                $result['errors'][] = 'Dijital Avrupa Bilgi Günü duyurusunda doğrulanamayan çağrı başlığı: ' . sanitize_text_field( $missing_title );
            }
            return $result;
        }

        if ( ! self::info_day_call_fiches_verified( $info_day_text ) ) {
            $result['errors'][] = 'Dijital Avrupa Bilgi Günü duyurusunda beş 2026 call-fiche ailesi birlikte doğrulanamadı.';
            return $result;
        }

        $open_calls_check = self::open_calls_are_verified( $open_calls_raw, $open_calls_text, $calls );
        if ( is_wp_error( $open_calls_check ) ) {
            $result['errors'][] = $open_calls_check->get_error_message();
            return $result;
        }

        foreach ( $calls as $call ) {
            $result['rows'][] = self::row_from_call( $call, $today );
        }

        $result['stats']['verified'] = count( $result['rows'] );
        return $result;
    }

    private static function announcement_is_verified( $text ) {
        if ( ! $text || false === strpos( $text, '21 04 2026' ) ) {
            return false;
        }

        $opening_markers = array(
            'dijital avrupa programi 2026 cagrilari yayinda',
            'digital europe programme 2026 calls for proposals are now open',
            '2026 calls have been published',
        );

        foreach ( $opening_markers as $marker ) {
            if ( false !== strpos( $text, $marker ) ) {
                return true;
            }
        }
        return false;
    }

    private static function missing_info_day_titles( $text ) {
        $titles = array(
            'Veri Yoluyla Mevzuat Uyumu için Dijital Çözümler',
            'DAP Yaygınlaştırma ve Faydalanma Desteği',
            'Sağlıkta Yapay Zekâ Kullanımı için İleri Dijital Beceriler',
            'Dijital Beceri ve İstihdam Platformu',
            'EdTech Hızlandırıcı',
            'Çok Ülkeli Projeler Uygulama Desteği',
            'Bilgi Bütünlüğü için Araştırma Destek Çerçevesi',
        );

        $missing = array();
        foreach ( $titles as $title ) {
            if ( false === strpos( $text, self::normalized_text( $title ) ) ) {
                $missing[] = $title;
            }
        }
        return $missing;
    }

    private static function info_day_call_fiches_verified( $text ) {
        $families = array(
            'call fiche digital 2026 ai data 10 en',
            'call fiche digital 2026 bestuse mcp 10 en',
            'call fiche digital 2026 bestuse rsf 10 en',
            'call fiche digital 2026 skills 10 en',
            'call fiche digital 2026 support 10 en',
        );

        foreach ( $families as $family ) {
            if ( false === strpos( $text, $family ) ) {
                return false;
            }
        }
        return true;
    }

    private static function open_calls_are_verified( $raw_text, $normalized_text, $calls ) {
        $known_codes = array();
        foreach ( (array) $calls as $call ) {
            $known_codes[] = strtoupper( $call['code'] );
        }
        sort( $known_codes );

        preg_match_all( '/DIGITAL-2026-[A-Z0-9-]+/i', (string) $raw_text, $matches );
        $live_codes = array_map( 'strtoupper', isset( $matches[0] ) ? $matches[0] : array() );
        $live_codes = array_values( array_unique( $live_codes ) );
        sort( $live_codes );

        if ( $live_codes !== $known_codes ) {
            $unknown = array_values( array_diff( $live_codes, $known_codes ) );
            $missing = array_values( array_diff( $known_codes, $live_codes ) );
            if ( $unknown ) {
                return new WP_Error( 'digital_europe_unknown_code', 'Dijital Avrupa Açık Çağrılar sayfasında henüz doğrulanmamış topic kodu görüldü: ' . implode( ', ', $unknown ) );
            }
            if ( $missing ) {
                return new WP_Error( 'digital_europe_code_missing', 'Dijital Avrupa Açık Çağrılar sayfasında doğrulanamayan topic kodu: ' . implode( ', ', $missing ) );
            }
            return new WP_Error( 'digital_europe_code_set_mismatch', 'Dijital Avrupa Açık Çağrılar topic seti doğrulanamadı.' );
        }

        $deadline_signal = 'submission deadline 1 october 2026';
        if ( substr_count( $normalized_text, $deadline_signal ) < count( $known_codes ) ) {
            return new WP_Error( 'digital_europe_deadline_missing', 'Dijital Avrupa Açık Çağrılar sayfasında 7 çağrı için 1 Ekim 2026 son başvuru sinyali doğrulanamadı.' );
        }

        return true;
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

    private static function row_from_call( $call, $today ) {
        $code = sanitize_text_field( $call['code'] );
        $source_locator = self::INFO_DAY_URL . '#call-' . strtolower( $code );

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
            'source_url'           => esc_url_raw( $source_locator, array( 'http', 'https' ) ),
            'application_url'      => self::APPLICATION_URL,
            'amount'               => sanitize_text_field( $call['amount'] ),
            'date_basis'           => self::VERIFIED_DATE_BASIS,
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
            'user-agent'  => 'SektorelAjanda/1.56.6 (+https://sektorelajanda.com)',
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
