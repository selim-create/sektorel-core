<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Production compatibility layer for TOBB detail/media requests.
 *
 * TOBB currently serves its detail endpoint over HTTP, while the HTTPS
 * certificate chain cannot be validated by the production OpenSSL stack.
 * We do not disable TLS verification. Instead, only the exact allowlisted TOBB
 * detail route (and same-host image assets used by that route) are fetched over
 * HTTP while canonical/source URLs remain HTTPS in candidate/post metadata.
 */
class Sektorel_Content_TOBB_HTTP_Compat {

    private static $in_fallback = false;

    public static function init() {
        add_filter( 'pre_http_request', array( __CLASS__, 'maybe_preempt_request' ), 10, 3 );
    }

    public static function maybe_preempt_request( $preempt, $args, $url ) {
        if ( false !== $preempt || self::$in_fallback ) {
            return $preempt;
        }

        $url = trim( (string) $url );
        if ( ! self::is_https_tobb_url( $url ) ) {
            return $preempt;
        }

        $is_detail = self::is_detail_url( $url );
        $is_image  = self::is_image_url( $url );

        if ( ! $is_detail && ! $is_image ) {
            return $preempt;
        }

        if ( $is_detail && ! self::is_content_engine_request( $args ) ) {
            return $preempt;
        }

        $http_url = preg_replace( '#^https://#i', 'http://', $url, 1 );
        if ( ! $http_url || ! self::is_allowed_http_url( $http_url, $is_detail ) ) {
            return $preempt;
        }

        $fallback_args = is_array( $args ) ? $args : array();
        $fallback_args['redirection'] = 0;
        $fallback_args['sslverify']   = true;

        self::$in_fallback = true;
        $response = wp_safe_remote_get( $http_url, $fallback_args );
        self::$in_fallback = false;

        if ( is_wp_error( $response ) ) {
            return $preempt;
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        if ( $status < 200 || $status >= 300 ) {
            return $preempt;
        }

        if ( $is_detail ) {
            $body = (string) wp_remote_retrieve_body( $response );
            $body = self::inject_deterministic_detail_body( $body );
            if ( '' === trim( $body ) ) {
                return $preempt;
            }
            $response['body'] = $body;
        }

        return $response;
    }

    private static function is_content_engine_request( $args ) {
        $args = is_array( $args ) ? $args : array();
        $user_agent = isset( $args['user-agent'] ) ? (string) $args['user-agent'] : '';

        return false !== strpos( $user_agent, 'SektorelAjandaContentBot/' );
    }

    private static function is_https_tobb_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );

        return 'https' === $scheme && in_array( $host, array( 'tobb.org.tr', 'www.tobb.org.tr' ), true );
    }

    private static function is_detail_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || '/Sayfalar/Detay.php' !== (string) ( $parts['path'] ?? '' ) ) {
            return false;
        }

        $query = array();
        parse_str( (string) ( $parts['query'] ?? '' ), $query );

        $list = isset( $query['lst'] ) ? sanitize_text_field( $query['lst'] ) : '';
        $rid  = isset( $query['rid'] ) ? absint( $query['rid'] ) : 0;

        return $rid > 0 && in_array( $list, array( 'Haberler', 'DuyurularListesi' ), true );
    }

    private static function is_image_url( $url ) {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( '' === $path ) {
            return false;
        }

        return (bool) preg_match( '/\.(?:jpe?g|png|webp|gif)$/i', $path );
    }

    private static function is_allowed_http_url( $url, $detail ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) ) {
            return false;
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );

        if ( 'http' !== $scheme || ! in_array( $host, array( 'tobb.org.tr', 'www.tobb.org.tr' ), true ) ) {
            return false;
        }

        return $detail ? self::is_detail_url( preg_replace( '#^http://#i', 'https://', $url, 1 ) ) : self::is_image_url( $url );
    }

    /**
     * Inject a deterministic synthetic article container for the generic detail
     * parser. TOBB's source HTML is malformed around the article body: the first
     * paragraph closes the visible content container and later paragraphs continue
     * in sibling divs. The stable boundary is the opening
     * `.box-content-container.justify` block through (but not including)
     * `#right-column`.
     */
    private static function inject_deterministic_detail_body( $html ) {
        $html = (string) $html;
        if ( '' === trim( $html ) ) {
            return $html;
        }

        $paragraphs = self::extract_full_article_paragraphs( $html );

        // Older/minimal TOBB pages can expose only the hidden #detay payload.
        // Keep that as a conservative fallback instead of losing enrichment.
        if ( ! $paragraphs ) {
            $fallback = self::extract_hidden_detail_value( $html );
            if ( $fallback ) {
                $paragraphs = array( $fallback );
            }
        }

        if ( ! $paragraphs ) {
            return $html;
        }

        $fragment = '<div class="content-detail sektorel-tobb-detail">';
        foreach ( $paragraphs as $paragraph ) {
            $fragment .= '<p>' . esc_html( $paragraph ) . '</p>';
        }
        $fragment .= '</div>';

        if ( false !== stripos( $html, '</body>' ) ) {
            return preg_replace( '/<\/body>/i', $fragment . '</body>', $html, 1 );
        }

        return $html . $fragment;
    }

    /**
     * Extract the complete TOBB article body from a deterministic raw-HTML slice.
     * Do not use generic DOM ancestry here: malformed legacy markup closes the
     * first content div before the remaining article paragraphs.
     */
    private static function extract_full_article_paragraphs( $html ) {
        $start_pattern = '/<div\b[^>]*class\s*=\s*(["\'])[^"\']*\bbox-content-container\b[^"\']*\bjustify\b[^"\']*\1[^>]*>/iu';
        if ( ! preg_match( $start_pattern, $html, $start_match, PREG_OFFSET_CAPTURE ) ) {
            return array();
        }

        $start_tag = (string) $start_match[0][0];
        $start     = (int) $start_match[0][1] + strlen( $start_tag );

        $tail = substr( $html, $start );
        if ( false === $tail ) {
            return array();
        }

        $end_pattern = '/<div\b[^>]*id\s*=\s*(["\'])right-column\1[^>]*>/iu';
        if ( ! preg_match( $end_pattern, $tail, $end_match, PREG_OFFSET_CAPTURE ) ) {
            return array();
        }

        $end = (int) $end_match[0][1];
        if ( $end <= 0 ) {
            return array();
        }

        $slice = substr( $tail, 0, $end );
        if ( false === $slice || '' === trim( $slice ) ) {
            return array();
        }

        // Remove media and convert known paragraph/block boundaries to newlines.
        $slice = preg_replace( '/<img\b[^>]*>/iu', '', $slice );
        $slice = preg_replace( '/<br\s*\/?\s*>/iu', "\n", $slice );
        $slice = preg_replace( '/<\/(?:p|div|li|h[1-6])\s*>/iu', "\n", $slice );
        $slice = html_entity_decode( (string) $slice, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $slice = wp_strip_all_tags( $slice );
        $slice = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string) $slice );
        $slice = str_replace( array( "\r\n", "\r" ), "\n", (string) $slice );

        $lines = preg_split( '/\n+/u', (string) $slice );
        $paragraphs = array();
        foreach ( (array) $lines as $line ) {
            $line = preg_replace( '/\s+/u', ' ', trim( (string) $line ) );
            $line = trim( (string) $line );
            if ( '' === $line ) {
                continue;
            }

            // Publication date is already stored in candidate metadata and should
            // not become prose sent to the AI editor.
            if ( preg_match( '/^\d{2}\.\d{2}\.\d{4}\s*\/?$/u', $line ) ) {
                continue;
            }

            // Strip legacy print-script debris if it leaks into the content slice.
            if (
                false !== stripos( $line, "pathname" ) ||
                preg_match( '/^URL\s*:/iu', $line )
            ) {
                continue;
            }

            if ( mb_strlen( $line, 'UTF-8' ) < 30 ) {
                continue;
            }

            $paragraphs[] = $line;
        }

        $paragraphs = array_values( array_unique( $paragraphs ) );
        if ( ! $paragraphs ) {
            return array();
        }

        $combined = implode( "\n\n", $paragraphs );
        if ( mb_strlen( $combined, 'UTF-8' ) < 300 ) {
            return array();
        }

        return $paragraphs;
    }

    private static function extract_hidden_detail_value( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return '';
        }

        $previous = libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return '';
        }

        $xpath = new DOMXPath( $dom );
        $nodes = $xpath->query( '//*[@id="detay"]' );
        if ( ! $nodes || ! $nodes->length ) {
            return '';
        }

        $node = $nodes->item( 0 );
        if ( ! ( $node instanceof DOMElement ) ) {
            return '';
        }

        $detail = $node->hasAttribute( 'value' ) ? $node->getAttribute( 'value' ) : $node->textContent;
        $detail = html_entity_decode( (string) $detail, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $detail = wp_strip_all_tags( $detail );
        $detail = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string) $detail );
        $detail = preg_replace( '/\s+/u', ' ', (string) $detail );
        $detail = trim( (string) $detail );

        return mb_strlen( $detail, 'UTF-8' ) >= 160 ? $detail : '';
    }
}
