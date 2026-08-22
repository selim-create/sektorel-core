<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Source-specific transport fallback for PSB Anatolia source #340.
 *
 * Production occasionally times out on the generic homepage during Stage 12.
 * During the HTML scan only, fetch the smaller official "Fuar Künyesi" page
 * instead. The existing conservative HTML parser, matcher, repair stage and
 * candidate lifecycle remain authoritative; no Event is created here.
 */
class Sektorel_Event_Source_PSB_Anatolia {

    const SOURCE_URL = 'https://psbanatolia.com/';
    const VERIFY_URL = 'https://psbanatolia.com/hakkimizda-fuar-kunyesi-1.html';
    const TIMEOUT    = 15;
    const MAX_BODY   = 1048576;

    private static $proxying = false;

    public static function init() {
        add_filter( 'pre_http_request', array( __CLASS__, 'proxy_html_scan_request' ), 10, 3 );
    }

    public static function proxy_html_scan_request( $preempt, $args, $url ) {
        if ( self::$proxying || ! wp_doing_ajax() ) {
            return $preempt;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( 'sektorel_html_event_scan_batch' !== $action ) {
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
