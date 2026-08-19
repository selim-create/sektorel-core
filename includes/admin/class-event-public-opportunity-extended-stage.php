<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-event-public-opportunity-trade-ministry.php';
require_once __DIR__ . '/class-event-public-opportunity-eximbank.php';

/**
 * Aggregates the proven 1.51 KOSGEB/İŞKUR live engine with additional
 * deterministic public-opportunity providers.
 *
 * The existing live stage remains the single owner of merge/upsert/batch
 * semantics. This class only extends prepare-time discovery so duplicate,
 * evidence and fallback behavior stay identical across all providers.
 */
class Sektorel_Event_Public_Opportunity_Extended_Stage {

    const NONCE_ACTION = 'sektorel_public_opportunities';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;

    public static function init() {
        remove_action( 'wp_ajax_sektorel_public_opportunities_prepare', array( 'Sektorel_Event_Public_Opportunity_Live_Stage', 'ajax_prepare' ) );
        remove_action( 'wp_ajax_sektorel_public_opportunities_batch', array( 'Sektorel_Event_Public_Opportunity_Live_Stage', 'ajax_batch' ) );

        add_action( 'wp_ajax_sektorel_public_opportunities_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_public_opportunities_batch', array( __CLASS__, 'ajax_batch' ) );
        add_filter( 'sektorel_source_background_action_map', array( __CLASS__, 'override_background_action_map' ), 100 );
    }

    public static function override_background_action_map( $map ) {
        $map = is_array( $map ) ? $map : array();
        $map['sektorel_public_opportunities_prepare'] = array( __CLASS__, 'ajax_prepare' );
        $map['sektorel_public_opportunities_batch']   = array( __CLASS__, 'ajax_batch' );
        return $map;
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

        // Deduplicate provider results by stable occurrence identity before the
        // existing 1.51 merge/fallback logic runs.
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

    public static function ajax_batch() {
        // Queue schema/key deliberately matches the existing live stage, so all
        // proven upsert, evidence and idempotency behavior is reused unchanged.
        Sektorel_Event_Public_Opportunity_Live_Stage::ajax_batch();
    }

    private static function invoke_live_private( $method_name, $arguments ) {
        try {
            $method = new ReflectionMethod( 'Sektorel_Event_Public_Opportunity_Live_Stage', $method_name );
            if ( method_exists( $method, 'setAccessible' ) ) {
                $method->setAccessible( true );
            }
            return $method->invokeArgs( null, (array) $arguments );
        } catch ( Throwable $exception ) {
            return new WP_Error( 'public_opportunity_extension_reflection_error', $exception->getMessage() );
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
