<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-event-source-psb-current-reconcile.php';

/**
 * Source-specific transport fallback for PSB Anatolia source #340.
 *
 * Production occasionally times out on the generic homepage. During both the
 * source verification batch and the generic HTML scan, fetch the smaller
 * official "Fuar Künyesi" page instead. The existing checker, conservative
 * HTML parser, matcher, repair stage and candidate lifecycle remain
 * authoritative; no Event is created here.
 */
class Sektorel_Event_Source_PSB_Anatolia {

    const SOURCE_URL = 'https://psbanatolia.com/';
    const VERIFY_URL = 'https://psbanatolia.com/hakkimizda-fuar-kunyesi-1.html';
    const TIMEOUT    = 15;
    const MAX_BODY   = 1048576;

    private static $proxying = false;

    public static function init() {
        add_filter( 'pre_http_request', array( __CLASS__, 'proxy_official_request' ), 10, 3 );
        Sektorel_Event_Source_PSB_Current_Reconcile::init();
    }

    public static function proxy_official_request( $preempt, $args, $url ) {
        if ( self::$proxying || ! wp_doing_ajax() ) {
            return $preempt;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $allowed_actions = array(
            'sektorel_event_source_check_batch',
            'sektorel_html_event_scan_batch',
        );
        if ( ! in_array( $action, $allowed_actions, true ) ) {
            return $preempt;
        }

        $requested = untrailingslashit( strtolower( trim( (string) $url ) ) );
        $source    = untrailingslashit( strtolower( self::SOURCE_URL ) );
        if ( $requested !== $source ) {
            return $preempt;
        }

        self::$proxying = true;
        $response = wp_safe_remote_get( self::VERIFY_URL, array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_BODY,
            'user-agent'          => isset( $args['user-agent'] ) ? $args['user-agent'] : 'SektorelAjandaBot/1.0; +' . home_url( '/' ),
            'headers'             => isset( $args['headers'] ) ? $args['headers'] : array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5' ),
        ) );
        self::$proxying = false;

        return $response;
    }
}
