<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Discovery-only bridge for live public opportunities.
 *
 * KOSGEB's landing page does not always surface every still-active programme
 * in the cards scanned by the live adapter. Verified catalogue URLs are added
 * to the fetched KOSGEB index HTML as probe candidates. This does NOT promote
 * catalogue data to live data: the live adapter must still fetch each official
 * detail URL and re-parse a non-expired application deadline before it can
 * write live_kosgeb_official_detail evidence.
 */
class Sektorel_Event_Public_Opportunity_Live_Probe {

    public static function init() {
        add_filter( 'http_response', array( __CLASS__, 'inject_verified_kosgeb_probe_links' ), 20, 3 );
    }

    public static function inject_verified_kosgeb_probe_links( $response, $args, $url ) {
        if ( ! class_exists( 'Sektorel_Event_Public_Opportunity_Stage' ) ) {
            return $response;
        }

        $target = 'https://www.kosgeb.gov.tr/site/tr/genel/';
        if ( self::url_key( $url ) !== self::url_key( $target ) ) {
            return $response;
        }

        if ( ! is_array( $response ) || is_wp_error( $response ) ) {
            return $response;
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 || '' === $body ) {
            return $response;
        }

        $today = current_time( 'Y-m-d' );
        $links = array();

        foreach ( (array) Sektorel_Event_Public_Opportunity_Stage::catalogue() as $row ) {
            if ( 'kosgeb' !== ( isset( $row['provider'] ) ? sanitize_key( $row['provider'] ) : '' ) ) {
                continue;
            }

            $deadline   = isset( $row['application_deadline'] ) ? sanitize_text_field( $row['application_deadline'] ) : '';
            $source_url = isset( $row['source_url'] ) ? esc_url_raw( $row['source_url'], array( 'http', 'https' ) ) : '';
            if ( ! $deadline || $deadline < $today || ! self::allowed_kosgeb_detail_url( $source_url ) ) {
                continue;
            }

            $label = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : 'KOSGEB destek programı başvuru';
            $links[ self::url_key( $source_url ) ] = array(
                'url'   => $source_url,
                'label' => $label . ' Başvuru Destek Programı',
            );
        }

        if ( ! $links ) {
            return $response;
        }

        $probe = '<div data-sektorel-public-opportunity-live-probe="1" style="display:none">';
        foreach ( $links as $link ) {
            $probe .= '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '</a>';
        }
        $probe .= '</div>';

        if ( false !== stripos( $body, '</body>' ) ) {
            $body = preg_replace( '/<\/body>/i', $probe . '</body>', $body, 1 );
        } else {
            $body .= $probe;
        }

        $response['body'] = $body;
        return $response;
    }

    private static function allowed_kosgeb_detail_url( $url ) {
        if ( ! $url ) {
            return false;
        }

        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        return in_array( $host, array( 'www.kosgeb.gov.tr', 'kosgeb.gov.tr' ), true )
            && 0 === strpos( $path, '/site/tr/genel/detay/' );
    }

    private static function url_key( $url ) {
        return untrailingslashit( strtolower( esc_url_raw( (string) $url, array( 'http', 'https' ) ) ) );
    }
}
