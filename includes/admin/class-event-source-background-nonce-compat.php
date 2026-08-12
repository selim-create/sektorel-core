<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rebuilds legacy AJAX nonces inside the background worker request.
 *
 * Source Center background runs reuse the existing nonce-protected AJAX batch
 * callbacks. A nonce created in the initiating browser request contains that
 * login session token, while loopback / WP-Cron requests intentionally do not
 * depend on the browser session. Before each worker tick this compatibility
 * layer temporarily restores the run owner's user context and regenerates each
 * stage nonce using the current worker request context.
 *
 * The public loopback remains protected by its per-run high-entropy secret.
 */
class Sektorel_Event_Source_Background_Nonce_Compat {

    const RUN_PREFIX = 'sektorel_source_run_';
    const CRON_HOOK  = 'sektorel_source_background_tick';

    public static function init() {
        // Must run before Sektorel_Event_Source_Background_Run (default priority 10).
        add_action( 'wp_ajax_sektorel_source_background_kick', array( __CLASS__, 'refresh_ajax_run' ), 1 );
        add_action( 'wp_ajax_nopriv_sektorel_source_background_kick', array( __CLASS__, 'refresh_ajax_run' ), 1 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'refresh_cron_run' ), 1, 1 );
    }

    public static function refresh_ajax_run() {
        $run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
        $secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';

        if ( ! $run_id || ! $secret ) {
            return;
        }

        $run = self::get_run( $run_id );
        if ( ! $run || empty( $run['secret'] ) || ! hash_equals( (string) $run['secret'], $secret ) ) {
            return;
        }

        self::refresh_run_nonces( $run_id, $run );
    }

    public static function refresh_cron_run( $run_id ) {
        $run_id = sanitize_key( (string) $run_id );
        if ( ! $run_id ) {
            return;
        }

        $run = self::get_run( $run_id );
        if ( ! $run ) {
            return;
        }

        self::refresh_run_nonces( $run_id, $run );
    }

    private static function refresh_run_nonces( $run_id, $run ) {
        $user_id = isset( $run['user_id'] ) ? absint( $run['user_id'] ) : 0;
        if ( ! $user_id || empty( $run['stages'] ) || ! is_array( $run['stages'] ) ) {
            return;
        }

        $old_user_id = get_current_user_id();
        wp_set_current_user( $user_id );

        foreach ( $run['stages'] as $index => $stage ) {
            if ( ! is_array( $stage ) ) {
                continue;
            }

            $nonce_action = self::nonce_action_for_stage( $stage );
            if ( ! $nonce_action ) {
                continue;
            }

            $run['stages'][ $index ]['nonce'] = wp_create_nonce( $nonce_action );
        }

        $run['updated_at'] = current_time( 'mysql' );
        update_option( self::RUN_PREFIX . sanitize_key( $run_id ), $run, false );

        wp_set_current_user( $old_user_id );
    }

    private static function nonce_action_for_stage( $stage ) {
        $map = apply_filters(
            'sektorel_source_background_nonce_actions',
            array(
                'sektorel_event_source_prepare_checks' => 'sektorel_event_source_check',
                'sektorel_event_source_check_batch'    => 'sektorel_event_source_check',
                'sektorel_tobb_prepare'                => 'sektorel_tobb_fair_calendar',
                'sektorel_tobb_import_batch'           => 'sektorel_tobb_fair_calendar',
                'sektorel_prepare_jsonld_scan'         => 'sektorel_event_candidate_jsonld',
                'sektorel_jsonld_scan_batch'           => 'sektorel_event_candidate_jsonld',
                'sektorel_prepare_html_event_scan'     => 'sektorel_event_candidate_html',
                'sektorel_html_event_scan_batch'       => 'sektorel_event_candidate_html',
            )
        );

        foreach ( array( 'prepare_action', 'batch_action' ) as $key ) {
            $action = isset( $stage[ $key ] ) ? sanitize_key( (string) $stage[ $key ] ) : '';
            if ( $action && isset( $map[ $action ] ) ) {
                return sanitize_text_field( (string) $map[ $action ] );
            }
        }

        return '';
    }

    private static function get_run( $run_id ) {
        $run = get_option( self::RUN_PREFIX . sanitize_key( $run_id ), array() );
        return is_array( $run ) ? $run : array();
    }
}
