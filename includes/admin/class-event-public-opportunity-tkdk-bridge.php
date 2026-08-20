<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds TKDK to the existing public-opportunity stage without changing batch,
 * dedupe, evidence or upsert ownership. The bridge replaces only prepare-time
 * discovery and reuses the same queue/batch semantics as Extended Stage.
 */
class Sektorel_Event_Public_Opportunity_TKDK_Bridge {

    const NONCE_ACTION         = 'sektorel_public_opportunities';
    const QUEUE_TTL            = 2 * HOUR_IN_SECONDS;
    const OBSERVABILITY_OPTION = 'sektorel_public_opportunity_observability';

    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }
        self::$initialized = true;

        add_action( 'admin_init', array( __CLASS__, 'activate_bridge' ), 120 );
        add_filter( 'sektorel_source_background_action_map', array( __CLASS__, 'override_background_action_map' ), 200 );
        add_filter( 'sektorel_source_center_stages', array( __CLASS__, 'extend_stage_description' ), 200 );
    }

    public static function activate_bridge() {
        if ( class_exists( 'Sektorel_Event_Public_Opportunity_Extended_Stage' ) ) {
            remove_action( 'wp_ajax_sektorel_public_opportunities_prepare', array( 'Sektorel_Event_Public_Opportunity_Extended_Stage', 'ajax_prepare' ) );
        }
        remove_action( 'wp_ajax_sektorel_public_opportunities_prepare', array( 'Sektorel_Event_Public_Opportunity_Live_Stage', 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_public_opportunities_prepare', array( __CLASS__, 'ajax_prepare' ) );
    }

    public static function override_background_action_map( $map ) {
        $map = is_array( $map ) ? $map : array();
        $map['sektorel_public_opportunities_prepare'] = array( __CLASS__, 'ajax_prepare' );
        return $map;
    }

    public static function extend_stage_description( $stages ) {
        foreach ( (array) $stages as $index => $stage ) {
            if ( ! is_array( $stage ) || empty( $stage['key'] ) || 'public_opportunities' !== $stage['key'] ) {
                continue;
            }
            $stages[ $index ]['description'] = 'KOSGEB, İŞKUR, TÜBİTAK, Kalkınma Ajansları, Ticaret Bakanlığı, Türk Eximbank ve TKDK/IPARD resmî kaynaklarını kaynağa özel deterministic/canlı adapterlarla tarar; doğrulanmış açık/yaklaşan fırsatları taslak Event olarak günceller.';
        }
        return $stages;
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $year = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) current_time( 'Y' );
        if ( $year < 2026 || $year > ( (int) current_time( 'Y' ) + 1 ) ) {
            wp_send_json_error( array( 'message' => 'Kamu fırsatları yılı geçersiz.' ) );
        }

        $base = self::invoke_live_private( 'discover', array( $year ) );
        if ( is_wp_error( $base ) || ! is_array( $base ) ) {
            wp_send_json_error( array( 'message' => 'Mevcut kamu fırsatı canlı keşif motoru çalıştırılamadı.' ) );
        }

        $rows   = isset( $base['rows'] ) && is_array( $base['rows'] ) ? $base['rows'] : array();
        $errors = isset( $base['errors'] ) && is_array( $base['errors'] ) ? $base['errors'] : array();
        $stats  = isset( $base['stats'] ) && is_array( $base['stats'] ) ? $base['stats'] : array();

        $providers = array(
            'tubitak'              => array( 'Sektorel_Event_Public_Opportunity_Tubitak', 'discover' ),
            'development_agencies' => array( 'Sektorel_Event_Public_Opportunity_Development_Agencies', 'discover' ),
            'trade_ministry'       => array( 'Sektorel_Event_Public_Opportunity_Trade_Ministry', 'discover' ),
            'eximbank'             => array( 'Sektorel_Event_Public_Opportunity_Eximbank', 'discover' ),
            'tkdk'                 => array( 'Sektorel_Event_Public_Opportunity_TKDK', 'discover' ),
        );

        foreach ( $providers as $provider_key => $callback ) {
            if ( ! is_callable( $callback ) ) {
                $errors[] = strtoupper( $provider_key ) . ' provider sınıfı yüklenemedi.';
                continue;
            }

            try {
                $provider = call_user_func( $callback, $year );
            } catch ( Throwable $exception ) {
                $errors[] = strtoupper( $provider_key ) . ' canlı keşif hatası: ' . $exception->getMessage();
                continue;
            }

            if ( ! is_array( $provider ) ) {
                continue;
            }
            if ( ! empty( $provider['rows'] ) && is_array( $provider['rows'] ) ) {
                $rows = array_merge( $rows, $provider['rows'] );
            }
            if ( ! empty( $provider['errors'] ) && is_array( $provider['errors'] ) ) {
                $errors = array_merge( $errors, $provider['errors'] );
            }
            $stats[ $provider_key ] = isset( $provider['stats'] ) && is_array( $provider['stats'] )
                ? $provider['stats']
                : array( 'links' => 0, 'verified' => 0 );
        }

        $deduped = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['occurrence_key'] ) ) {
                continue;
            }
            $deduped[ sanitize_key( $row['occurrence_key'] ) ] = $row;
        }

        $merged = self::invoke_live_private( 'merge_with_verified_catalogue', array( $year, array_values( $deduped ) ) );
        if ( is_wp_error( $merged ) || ! is_array( $merged ) ) {
            wp_send_json_error( array( 'message' => 'Kamu fırsatları doğrulanmış fallback ile birleştirilemedi.' ) );
        }

        self::save_observability_snapshot( $year, $stats, $errors );

        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array(
                'year'            => $year,
                'rows'            => array_values( $merged ),
                'provider_errors' => array_values( array_unique( array_filter( $errors ) ) ),
                'provider_stats'  => $stats,
            ),
            self::QUEUE_TTL
        );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $merged ),
        ) );
    }

    private static function save_observability_snapshot( $year, $stats, $errors ) {
        $labels = array(
            'kosgeb'               => 'KOSGEB',
            'iskur'                => 'İŞKUR',
            'tubitak'              => 'TÜBİTAK',
            'development_agencies' => 'Kalkınma Ajansları',
            'trade_ministry'       => 'Ticaret Bakanlığı',
            'eximbank'             => 'Türk Eximbank',
            'tkdk'                 => 'TKDK / IPARD',
        );

        $providers = array();
        foreach ( $labels as $key => $label ) {
            $stat = isset( $stats[ $key ] ) && is_array( $stats[ $key ] ) ? $stats[ $key ] : array();
            $providers[ $key ] = array(
                'label'    => $label,
                'links'    => absint( isset( $stat['links'] ) ? $stat['links'] : 0 ),
                'verified' => absint( isset( $stat['verified'] ) ? $stat['verified'] : 0 ),
            );
        }

        $warnings = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $errors ) ) ) );
        update_option(
            self::OBSERVABILITY_OPTION,
            array(
                'version'          => 2,
                'year'             => absint( $year ),
                'checked_at'       => current_time( 'mysql' ),
                'providers'        => $providers,
                'warnings_total'   => count( $warnings ),
                'warning_messages' => array_slice( $warnings, 0, 12 ),
            ),
            false
        );
    }

    private static function invoke_live_private( $method_name, $arguments ) {
        try {
            $method = new ReflectionMethod( 'Sektorel_Event_Public_Opportunity_Live_Stage', $method_name );
            if ( method_exists( $method, 'setAccessible' ) ) {
                $method->setAccessible( true );
            }
            return $method->invokeArgs( null, (array) $arguments );
        } catch ( Throwable $exception ) {
            return new WP_Error( 'public_opportunity_tkdk_bridge_reflection_error', $exception->getMessage() );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_public_opportunity_live_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }
}
