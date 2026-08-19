<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * KOSGEB discovery/detail bridge for live public opportunities.
 *
 * Two narrowly scoped source-specific normalizations are applied:
 * 1) active verified KOSGEB catalogue URLs are added to the official index as
 *    probe candidates so a still-active detail cannot disappear merely because
 *    a landing-page card changed;
 * 2) KOSGEB detail responses are flattened into one article containing only
 *    text from the CURRENT live HTTP response. This avoids wrapper/navigation
 *    classes winning the generic document_text() heuristic.
 *
 * Neither path promotes fallback data to live data. The live adapter still has
 * to fetch the official detail URL and independently verify a non-expired
 * application deadline before it may write public_opportunity_live evidence.
 */
class Sektorel_Event_Public_Opportunity_Live_Probe {

    public static function init() {
        add_filter( 'http_response', array( __CLASS__, 'inject_verified_kosgeb_probe_links' ), 20, 3 );
        add_filter( 'http_response', array( __CLASS__, 'normalize_kosgeb_detail_response' ), 30, 3 );
    }

    public static function inject_verified_kosgeb_probe_links( $response, $args, $url ) {
        if ( ! class_exists( 'Sektorel_Event_Public_Opportunity_Stage' ) ) {
            return $response;
        }

        $target = 'https://www.kosgeb.gov.tr/site/tr/genel/';
        if ( self::url_key( $url ) !== self::url_key( $target ) ) {
            return $response;
        }

        if ( ! self::valid_response( $response ) ) {
            return $response;
        }

        $body  = (string) wp_remote_retrieve_body( $response );
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

        // Put probe candidates at the beginning of body so the live adapter's
        // bounded detail-link scan cannot drop them after later page links.
        if ( preg_match( '/<body\b[^>]*>/i', $body ) ) {
            $body = preg_replace( '/(<body\b[^>]*>)/i', '$1' . $probe, $body, 1 );
        } else {
            $body = $probe . $body;
        }

        $response['body'] = $body;
        return $response;
    }

    /**
     * Normalize only official KOSGEB detail responses.
     *
     * The source HTML has broad wrapper classes such as "content" and
     * "detail". The live adapter intentionally uses a conservative generic
     * document-text chooser; on KOSGEB that chooser can select navigation or a
     * surrounding wrapper instead of the actual announcement body. Flattening
     * the current HTTP response to a single article preserves all visible text
     * while removing layout ambiguity. Existing deadline/keyword validators in
     * the live stage remain authoritative.
     */
    public static function normalize_kosgeb_detail_response( $response, $args, $url ) {
        if ( ! self::allowed_kosgeb_detail_url( $url ) || ! self::valid_response( $response ) ) {
            return $response;
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === $body || false !== strpos( $body, 'data-sektorel-kosgeb-detail-normalized="1"' ) ) {
            return $response;
        }

        $title = '';
        if ( preg_match( '/<title\b[^>]*>(.*?)<\/title>/is', $body, $match ) ) {
            $title = self::clean_response_text( $match[1] );
        }

        $text = self::clean_response_text( $body );
        if ( strlen( $text ) < 120 ) {
            return $response;
        }

        // Keep this transformation purely structural. We do not extract or
        // inject dates here; the live adapter must still parse them from this
        // freshly fetched official-page text.
        $normalized = '<!doctype html><html><head><meta charset="utf-8">';
        if ( $title ) {
            $normalized .= '<title>' . esc_html( $title ) . '</title>';
        }
        $normalized .= '</head><body>';
        $normalized .= '<article data-sektorel-kosgeb-detail-normalized="1">' . esc_html( $text ) . '</article>';
        $normalized .= '</body></html>';

        $response['body'] = $normalized;
        return $response;
    }

    private static function valid_response( $response ) {
        if ( ! is_array( $response ) || is_wp_error( $response ) ) {
            return false;
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $body = (string) wp_remote_retrieve_body( $response );
        return $code >= 200 && $code < 300 && '' !== $body;
    }

    private static function clean_response_text( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = str_replace( array( "\xC2\xA0", "\r", "\n", "\t" ), ' ', $value );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
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
