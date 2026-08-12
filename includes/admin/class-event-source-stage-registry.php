<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central contract for Source Center pipeline stages.
 *
 * Phase 2A introduces the registry without changing the active orchestrator.
 * Phase 2B will migrate the current stage providers and consumers to this
 * contract. Until then, the legacy filters remain the production source of
 * truth and this registry is deliberately side-effect free.
 */
class Sektorel_Event_Source_Stage_Registry {

    private static $initialized = false;
    private static $stages      = array();

    public static function init() {
        if ( self::$initialized ) {
            return;
        }

        self::$initialized = true;
        self::register_core_stages();
    }

    /**
     * Register one stage exactly once by key.
     *
     * Required fields:
     * - key
     * - order
     * - label
     * - prepare_action / prepare_callback
     * - batch_action / batch_callback
     * - nonce_action
     *
     * Optional:
     * - description
     * - prepare_payload
     */
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

    /**
     * UI/background-safe stage payloads with fresh nonces.
     */
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

    /**
     * Callback map derived from the same stage definitions.
     */
    public static function action_map() {
        $map = array();
        foreach ( self::definitions() as $stage ) {
            $map[ $stage['prepare_action'] ] = $stage['prepare_callback'];
            $map[ $stage['batch_action'] ]   = $stage['batch_callback'];
        }
        return $map;
    }

    /**
     * Nonce-action map derived from the same stage definitions.
     */
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

    private static function register_core_stages() {
        self::register(
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
            )
        );

        self::register(
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
                'prepare_payload'  => array( __CLASS__, 'current_year_payload' ),
            )
        );

        self::register(
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
            )
        );

        self::register(
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
            )
        );
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
