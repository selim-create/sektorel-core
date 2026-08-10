<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Pre-processes HTML scan responses so broad event selectors do not treat
 * outer listing wrappers and their real inner event cards as separate events.
 *
 * The legacy generic parser intentionally matches broad class/id signals such
 * as "event" and "etkinlik". Some sites use the same words on a page wrapper,
 * while the actual event cards live below it. That lets the wrapper leak the
 * first descendant title/date/link into a synthetic candidate.
 *
 * This filter is intentionally narrow:
 * - runs only during the HTML candidate AJAX batch;
 * - only neutralizes an outer event-like node when it contains a descendant
 *   event-like node that can independently provide BOTH a plausible title and
 *   a date signal;
 * - never removes the descendant card or page content.
 */
class Sektorel_Event_Candidate_HTML_Container_Filter {

    const ENGINE_VERSION = '1330';

    public static function init() {
        add_filter( 'http_response', array( __CLASS__, 'filter_scan_response' ), 20, 3 );
    }

    public static function filter_scan_response( $response, $args, $url ) {
        if ( ! is_admin() || ! wp_doing_ajax() ) {
            return $response;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( 'sektorel_html_event_scan_batch' !== $action ) {
            return $response;
        }

        if ( is_wp_error( $response ) || ! is_array( $response ) || empty( $response['body'] ) ) {
            return $response;
        }

        $content_type = '';
        if ( isset( $response['headers'] ) && is_object( $response['headers'] ) && method_exists( $response['headers'], 'offsetGet' ) ) {
            $content_type = strtolower( (string) $response['headers']->offsetGet( 'content-type' ) );
        } elseif ( isset( $response['headers']['content-type'] ) ) {
            $content_type = strtolower( (string) $response['headers']['content-type'] );
        }

        $body = (string) $response['body'];
        if ( false === strpos( $content_type, 'html' ) && false === stripos( $body, '<html' ) ) {
            return $response;
        }

        $filtered = self::filter_nested_containers( $body );
        if ( '' !== $filtered ) {
            $response['body'] = $filtered;
        }

        return $response;
    }

    private static function filter_nested_containers( $html ) {
        if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
            return $html;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return $html;
        }

        $xpath = new DOMXPath( $dom );
        $nodes = $xpath->query( self::event_node_query() );
        if ( ! $nodes || 0 === $nodes->length ) {
            return $html;
        }

        // Copy first: the DOM will be mutated while evaluating wrappers.
        $candidates = array();
        foreach ( $nodes as $node ) {
            if ( $node instanceof DOMElement ) {
                $candidates[] = $node;
            }
        }

        $changed = false;
        foreach ( $candidates as $outer ) {
            if ( ! $outer->parentNode || ! self::is_reasonable_container( $outer ) ) {
                continue;
            }

            $inner_nodes = $xpath->query( './/*[' . self::event_predicate() . ']', $outer );
            if ( ! $inner_nodes || 0 === $inner_nodes->length ) {
                continue;
            }

            $has_independent_inner_card = false;
            foreach ( $inner_nodes as $inner ) {
                if ( ! $inner instanceof DOMElement || ! self::is_reasonable_container( $inner ) ) {
                    continue;
                }
                if ( self::has_title_signal( $xpath, $inner ) && self::has_date_signal( $xpath, $inner ) ) {
                    $has_independent_inner_card = true;
                    break;
                }
            }

            if ( ! $has_independent_inner_card ) {
                continue;
            }

            self::neutralize_event_marker( $dom, $outer );
            $changed = true;
        }

        if ( ! $changed ) {
            return $html;
        }

        $output = $dom->saveHTML();
        if ( ! is_string( $output ) || '' === $output ) {
            return $html;
        }

        // DOMDocument adds the temporary XML encoding declaration as a PI.
        $output = preg_replace( '/^<\?xml[^>]+>\s*/i', '', $output );
        return is_string( $output ) && '' !== $output ? $output : $html;
    }

    private static function event_node_query() {
        return "//article"
            . " | //*[@itemscope and contains(translate(@itemtype,'EVENT','event'),'event')]"
            . " | //*[@class and (contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'event') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'etkinlik') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'calendar-item') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'agenda-item') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'conference') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'webinar') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'expo'))]"
            . " | //*[@id and (contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'event') or contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'etkinlik'))]";
    }

    private static function event_predicate() {
        return "self::article"
            . " or (@itemscope and contains(translate(@itemtype,'EVENT','event'),'event'))"
            . " or (@class and (contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'event') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'etkinlik') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'calendar-item') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'agenda-item') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'conference') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'webinar') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'expo')))"
            . " or (@id and (contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'event') or contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'etkinlik')))";
    }

    private static function has_title_signal( $xpath, $node ) {
        $queries = array(
            ".//*[@itemprop='name'][self::h1 or self::h2 or self::h3 or self::h4]",
            './/h1',
            './/h2',
            './/h3',
            './/h4',
            ".//*[@class and contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'title')]",
        );

        foreach ( $queries as $query ) {
            $matches = $xpath->query( $query, $node );
            if ( ! $matches ) {
                continue;
            }
            foreach ( $matches as $match ) {
                $title = self::clean_text( $match->textContent );
                $length = function_exists( 'mb_strlen' ) ? mb_strlen( $title, 'UTF-8' ) : strlen( $title );
                if ( $length >= 4 && $length <= 220 ) {
                    $normalized = strtolower( remove_accents( $title ) );
                    if ( ! in_array( $normalized, array( 'event', 'events', 'etkinlikler', 'takvim', 'calendar', 'agenda' ), true ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function has_date_signal( $xpath, $node ) {
        $queries = array(
            ".//*[@itemprop='startDate']/@content",
            ".//*[@itemprop='startDate']/@datetime",
            './/time/@datetime',
            ".//*[@data-start]/@data-start",
            ".//*[@data-date]/@data-date",
        );

        foreach ( $queries as $query ) {
            $matches = $xpath->query( $query, $node );
            if ( $matches && $matches->length > 0 ) {
                foreach ( $matches as $match ) {
                    if ( self::looks_like_date( trim( (string) $match->nodeValue ) ) ) {
                        return true;
                    }
                }
            }
        }

        $text = self::clean_text( $node->textContent );
        return (bool) preg_match(
            '/(?:\b\d{1,2}\s+(?:Ocak|Şubat|Subat|Mart|Nisan|Mayıs|Mayis|Haziran|Temmuz|Ağustos|Agustos|Eylül|Eylul|Ekim|Kasım|Kasim|Aralık|Aralik|January|February|March|April|May|June|July|August|September|October|November|December)\s+20\d{2}\b|\b\d{1,2}[\.\/]\d{1,2}[\.\/]20\d{2}\b|\b20\d{2}-\d{2}-\d{2}\b)/iu',
            $text
        );
    }

    private static function looks_like_date( $value ) {
        if ( '' === $value ) {
            return false;
        }
        if ( preg_match( '/20\d{2}[-\/]\d{1,2}[-\/]\d{1,2}/', $value ) ) {
            return true;
        }
        try {
            new DateTime( $value );
            return true;
        } catch ( Exception $e ) {
            return false;
        }
    }

    private static function neutralize_event_marker( $dom, $node ) {
        if ( 'article' === strtolower( $node->tagName ) ) {
            $replacement = $dom->createElement( 'div' );
            foreach ( iterator_to_array( $node->attributes ) as $attribute ) {
                $replacement->setAttribute( $attribute->name, $attribute->value );
            }
            while ( $node->firstChild ) {
                $replacement->appendChild( $node->firstChild );
            }
            if ( $node->parentNode ) {
                $node->parentNode->replaceChild( $replacement, $node );
                $node = $replacement;
            }
        }

        if ( $node->hasAttribute( 'itemscope' ) && false !== stripos( $node->getAttribute( 'itemtype' ), 'event' ) ) {
            $node->removeAttribute( 'itemscope' );
            $node->removeAttribute( 'itemtype' );
        }

        if ( $node->hasAttribute( 'class' ) ) {
            $class = self::neutralize_tokens( $node->getAttribute( 'class' ) );
            if ( '' === trim( $class ) ) {
                $node->removeAttribute( 'class' );
            } else {
                $node->setAttribute( 'class', $class );
            }
        }

        if ( $node->hasAttribute( 'id' ) ) {
            $id = self::neutralize_tokens( $node->getAttribute( 'id' ) );
            if ( '' === trim( $id ) ) {
                $node->removeAttribute( 'id' );
            } else {
                $node->setAttribute( 'id', $id );
            }
        }

        $node->setAttribute( 'data-sektorel-event-wrapper-filtered', self::ENGINE_VERSION );
    }

    private static function neutralize_tokens( $value ) {
        $tokens = preg_split( '/\s+/', trim( (string) $value ) );
        $kept = array();
        foreach ( $tokens as $token ) {
            if ( '' === $token ) {
                continue;
            }
            $normalized = strtolower( remove_accents( $token ) );
            if ( preg_match( '/(event|etkinlik|calendar-item|agenda-item|conference|webinar|expo)/i', $normalized ) ) {
                continue;
            }
            $kept[] = $token;
        }
        return implode( ' ', $kept );
    }

    private static function is_reasonable_container( $node ) {
        $text = self::clean_text( $node->textContent );
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
        return $length >= 12 && $length <= 12000;
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return sanitize_text_field( trim( (string) $value ) );
    }
}
