<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central contract and runtime source of truth for Source Center pipeline stages.
 *
 * Every internal stage is declared exactly once here. The three historical
 * filters remain only as public compatibility surfaces for external code;
 * Core no longer uses provider-specific stage/action/nonce registrations.
 */
class Sektorel_Event_Source_Stage_Registry {

    private static $initialized = false;
    private static $stages      = array();

    public static function init() {
        if ( self::$initialized ) {
            return;
        }

        self::$initialized = true;
        self::register_internal_stages();

        // Backward-compatible public surfaces. Internal values always come
        // from this registry; unknown third-party legacy stages are preserved.
        add_filter( 'sektorel_source_center_stages', array( __CLASS__, 'filter_runtime_stages' ), 9999 );
        add_filter( 'sektorel_source_background_action_map', array( __CLASS__, 'filter_action_map' ), 9999 );
        add_filter( 'sektorel_source_background_nonce_actions', array( __CLASS__, 'filter_nonce_action_map' ), 9999 );
    }

    public static function register( $stage ) {
        if ( ! is_array( $stage ) ) {
            return new WP_Error( 'invalid_stage', 'Pipeline stage tanımı dizi olmalıdır.' );
        }

        $normalized = self::normalize_definition( $stage );
        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }

        $key = $normalized['key'];
        if ( isset( self::$stages[ $key ] ) ) {
            return new WP_Error( 'duplicate_stage_key', 'Pipeline stage key zaten kayıtlı: ' . $key );
        }

        self::$stages[ $key ] = $normalized;
        return true;
    }

    public static function definitions() {
        $stages = array_values( self::$stages );
        usort(
            $stages,
            static function ( $a, $b ) {
                if ( $a['order'] === $b['order'] ) {
                    return strcmp( $a['key'], $b['key'] );
                }
                return $a['order'] <=> $b['order'];
            }
        );
        return $stages;
    }

    public static function runtime_stages() {
        $runtime = array();
        foreach ( self::definitions() as $stage ) {
            $runtime[] = array(
                'key'             => $stage['key'],
                'label'           => $stage['label'],
                'description'     => $stage['description'],
                'prepare_action'  => $stage['prepare_action'],
                'batch_action'    => $stage['batch_action'],
                'nonce'           => wp_create_nonce( $stage['nonce_action'] ),
                'prepare_payload' => self::resolve_payload( $stage['prepare_payload'] ),
            );
        }
        return $runtime;
    }

    public static function action_map() {
        $map = array();
        foreach ( self::definitions() as $stage ) {
            $map[ $stage['prepare_action'] ] = $stage['prepare_callback'];
            $map[ $stage['batch_action'] ]   = $stage['batch_callback'];
        }
        return $map;
    }

    public static function nonce_action_map() {
        $map = array();
        foreach ( self::definitions() as $stage ) {
            $map[ $stage['prepare_action'] ] = $stage['nonce_action'];
            $map[ $stage['batch_action'] ]   = $stage['nonce_action'];
        }
        return $map;
    }

    public static function get( $key ) {
        $key = sanitize_key( (string) $key );
        return isset( self::$stages[ $key ] ) ? self::$stages[ $key ] : null;
    }

    public static function filter_runtime_stages( $legacy_stages ) {
        $runtime       = self::runtime_stages();
        $internal_keys = array();

        foreach ( $runtime as $stage ) {
            $internal_keys[ $stage['key'] ] = true;
        }

        foreach ( (array) $legacy_stages as $stage ) {
            if ( ! is_array( $stage ) ) {
                continue;
            }

            $key = isset( $stage['key'] ) ? sanitize_key( (string) $stage['key'] ) : '';
            if ( ! $key || isset( $internal_keys[ $key ] ) ) {
                continue;
            }

            $runtime[] = $stage;
        }

        return $runtime;
    }

    public static function filter_action_map( $legacy_map ) {
        return array_merge( (array) $legacy_map, self::action_map() );
    }

    public static function filter_nonce_action_map( $legacy_map ) {
        return array_merge( (array) $legacy_map, self::nonce_action_map() );
    }

    private static function register_internal_stages() {
        $year_payload = array( __CLASS__, 'current_year_payload' );

        $stages = array(
            array(
                'key'              => 'source_check',
                'order'            => 10,
                'label'            => 'Kaynakları Doğrula',
                'description'      => 'Aktif kaynakların erişilebilirlik ve parser sinyalini kontrol eder.',
                'prepare_action'   => 'sektorel_event_source_prepare_checks',
                'prepare_callback' => array( 'Sektorel_Event_Source_Checker', 'ajax_prepare_checks' ),
                'batch_action'     => 'sektorel_event_source_check_batch',
                'batch_callback'   => array( 'Sektorel_Event_Source_Checker', 'ajax_check_batch' ),
                'nonce_action'     => 'sektorel_event_source_check',
                'prepare_payload'  => array(),
            ),
            array(
                'key'              => 'tobb',
                'order'            => 20,
                'label'            => 'TOBB Fuar Takvimi',
                'description'      => 'Canonical fuar occurrence kayıtlarını aday havuzuna günceller.',
                'prepare_action'   => 'sektorel_tobb_prepare',
                'prepare_callback' => array( 'Sektorel_Event_Source_TOBB', 'ajax_prepare' ),
                'batch_action'     => 'sektorel_tobb_import_batch',
                'batch_callback'   => array( 'Sektorel_Event_Source_TOBB', 'ajax_import_batch' ),
                'nonce_action'     => 'sektorel_tobb_fair_calendar',
                'prepare_payload'  => $year_payload,
            ),
            array(
                'key'              => 'canonical_drafts',
                'order'            => 30,
                'label'            => 'Güvenli Canonical Draft Üret',
                'description'      => 'TOBB gibi canonical kaynaklardaki uygun adayları mevcut dedupe ve source-role kurallarıyla taslak etkinliğe dönüştürür.',
                'prepare_action'   => 'sektorel_canonical_drafts_prepare',
                'prepare_callback' => array( 'Sektorel_Event_Canonical_Draft_Stage', 'ajax_prepare' ),
                'batch_action'     => 'sektorel_canonical_drafts_batch',
                'batch_callback'   => array( 'Sektorel_Event_Canonical_Draft_Stage', 'ajax_batch' ),
                'nonce_action'     => 'sektorel_canonical_candidate_drafts',
                'prepare_payload'  => array(),
            ),
            array(
                'key'              => 'ifm',
                'order'            => 40,
                'label'            => 'İFM Mekan Zenginleştirme',
                'description'      => 'İstanbul Fuar Merkezi takvimini mevcut etkinliklerle eşleştirir ve eksik mekan/salon, organizatör ve resmî site alanlarını tamamlar.',
                'prepare_action'   => 'sektorel_ifm_prepare',
                'prepare_callback' => array( 'Sektorel_Event_Source_IFM', 'ajax_prepare' ),
                'batch_action'     => 'sektorel_ifm_import_batch',
                'batch_callback'   => array( 'Sektorel_Event_Source_IFM', 'ajax_import_batch' ),
                'nonce_action'     => 'sektorel_ifm_fair_calendar',
                'prepare_payload'  => $year_payload,
            ),
            array(
                'key'              => 'tuyap',
                'order'            => 50,
                'label'            => 'Tüyap Mekan / Organizatör Zenginleştirme',
                'description'      => 'Tüyap İstanbul fuar takvimini mevcut etkinliklerle eşleştirir; eksik mekan, organizatör, bitiş tarihi, resmî site ve açıklamayı tamamlar.',
                'prepare_action'   => 'sektorel_tuyap_prepare',
                'prepare_callback' => array( 'Sektorel_Event_Source_Tuyap', 'ajax_prepare' ),
                'batch_action'     => 'sektorel_tuyap_import_batch',
                'batch_callback'   => array( 'Sektorel_Event_Source_Tuyap', 'ajax_import_batch' ),
                'nonce_action'     => 'sektorel_tuyap_fair_calendar',
                'prepare_payload'  => $year_payload,
            ),
            array(
                'key'              => 'jsonld',
                'order'            => 60,
                'label'            => 'JSON-LD Kaynakları',
                'description'      => 'Adapter olmayan yapılandırılmış Event verilerini adaylara işler.',
                'prepare_action'   => 'sektorel_prepare_jsonld_scan',
                'prepare_callback' => array( 'Sektorel_Event_Candidate_JSONLD', 'ajax_prepare_scan' ),
                'batch_action'     => 'sektorel_jsonld_scan_batch',
                'batch_callback'   => array( 'Sektorel_Event_Candidate_JSONLD', 'ajax_scan_batch' ),
                'nonce_action'     => 'sektorel_event_candidate_jsonld',
                'prepare_payload'  => array(),
            ),
            array(
                'key'              => 'html',
                'order'            => 70,
                'label'            => 'HTML Kaynakları',
                'description'      => 'Güvenli generic HTML kaynaklarında aday etkinlik keşfi yapar.',
                'prepare_action'   => 'sektorel_prepare_html_event_scan',
                'prepare_callback' => array( 'Sektorel_Event_Candidate_HTML', 'ajax_prepare_scan' ),
                'batch_action'     => 'sektorel_html_event_scan_batch',
                'batch_callback'   => array( 'Sektorel_Event_Candidate_HTML', 'ajax_scan_batch' ),
                'nonce_action'     => 'sektorel_event_candidate_html',
                'prepare_payload'  => array(),
            ),
            array(
                'key'              => 'candidate_matcher',
                'order'            => 80,
                'label'            => 'Adayları Otomatik Eşleştir',
                'description'      => 'Discovery/canonical adaylarını mevcut deterministic matcher ile Event havuzuna karşı sınıflandırır; güçlü mevcut eşleşmeleri kaynak kanıtına bağlar.',
                'prepare_action'   => 'sektorel_candidate_match_prepare',
                'prepare_callback' => array( 'Sektorel_Event_Candidate_Background_Matcher', 'ajax_prepare' ),
                'batch_action'     => 'sektorel_candidate_match_batch',
                'batch_callback'   => array( 'Sektorel_Event_Candidate_Background_Matcher', 'ajax_batch' ),
                'nonce_action'     => 'sektorel_candidate_background_matcher',
                'prepare_payload'  => array(),
            ),
            array(
                'key'              => 'safe_discovery_drafts',
                'order'            => 90,
                'label'            => 'Güvenli Discovery Draft Üret',
                'description'      => 'Matcher tarafından yeni doğrulanan, güvenli HTML discovery adaylarını mevcut guardlarla yalnız taslak Event’e dönüştürür.',
                'prepare_action'   => 'sektorel_safe_discovery_drafts_prepare',
                'prepare_callback' => array( 'Sektorel_Event_Safe_Discovery_Draft_Stage', 'ajax_prepare' ),
                'batch_action'     => 'sektorel_safe_discovery_drafts_batch',
                'batch_callback'   => array( 'Sektorel_Event_Safe_Discovery_Draft_Stage', 'ajax_batch' ),
                'nonce_action'     => 'sektorel_safe_discovery_drafts',
                'prepare_payload'  => array(),
            ),
        );

        foreach ( $stages as $stage ) {
            self::register( $stage );
        }
    }

    public static function current_year_payload() {
        return array(
            'year'          => (int) current_time( 'Y' ),
            'upcoming_only' => 1,
        );
    }

    private static function resolve_payload( $payload ) {
        if ( is_callable( $payload ) ) {
            $payload = call_user_func( $payload );
        }
        return is_array( $payload ) ? $payload : array();
    }

    private static function normalize_definition( $stage ) {
        $required = array(
            'key', 'order', 'label', 'prepare_action', 'prepare_callback',
            'batch_action', 'batch_callback', 'nonce_action',
        );

        foreach ( $required as $field ) {
            if ( ! array_key_exists( $field, $stage ) || '' === $stage[ $field ] || null === $stage[ $field ] ) {
                return new WP_Error( 'stage_field_missing', 'Pipeline stage alanı eksik: ' . $field );
            }
        }

        $key = sanitize_key( (string) $stage['key'] );
        if ( '' === $key ) {
            return new WP_Error( 'stage_key_invalid', 'Pipeline stage key geçersiz.' );
        }

        if ( ! is_callable( $stage['prepare_callback'] ) ) {
            return new WP_Error( 'stage_prepare_callback_invalid', 'Prepare callback çağrılabilir değil: ' . $key );
        }
        if ( ! is_callable( $stage['batch_callback'] ) ) {
            return new WP_Error( 'stage_batch_callback_invalid', 'Batch callback çağrılabilir değil: ' . $key );
        }

        $payload = isset( $stage['prepare_payload'] ) ? $stage['prepare_payload'] : array();
        if ( ! is_array( $payload ) && ! is_callable( $payload ) ) {
            return new WP_Error( 'stage_payload_invalid', 'Prepare payload dizi veya callback olmalıdır: ' . $key );
        }

        return array(
            'key'              => $key,
            'order'            => (int) $stage['order'],
            'label'            => sanitize_text_field( (string) $stage['label'] ),
            'description'      => isset( $stage['description'] ) ? sanitize_text_field( (string) $stage['description'] ) : '',
            'prepare_action'   => sanitize_key( (string) $stage['prepare_action'] ),
            'prepare_callback' => $stage['prepare_callback'],
            'batch_action'     => sanitize_key( (string) $stage['batch_action'] ),
            'batch_callback'   => $stage['batch_callback'],
            'nonce_action'     => sanitize_text_field( (string) $stage['nonce_action'] ),
            'prepare_payload'  => $payload,
        );
    }
}
