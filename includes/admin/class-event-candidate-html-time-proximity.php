<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Prevents broad HTML event containers from leaking unrelated clock/timestamp
 * values into text-fallback event dates.
 *
 * Structured datetime attributes are authoritative and left untouched. For
 * plain-text fallback cards, only clock values close to an explicit date are
 * kept recognizable by the legacy parser; distant clocks are neutralized in
 * the in-memory HTTP response before parsing.
 */
class Sektorel_Event_Candidate_HTML_Time_Proximity {

    const ENGINE_VERSION = '1342';
    const MAX_DISTANCE   = 160;

    public static function init() {
        // Run after container (20) and stale-card (25) response filters.
        add_filter( 'http_response', array( __CLASS__, 'filter_scan_response' ), 28, 3 );
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

        $body = (string) $response['body'];
        if ( false === stripos( $body, '<html' ) && false === stripos( $body, '<body' ) ) {
            return $response;
        }

        $filtered = self::filter_html( $body );
        if ( '' !== $filtered ) {
            $response['body'] = $filtered;
        }

        return $response;
    }

    private static function filter_html( $html ) {
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

        $candidates = array();
        foreach ( $nodes as $node ) {
            if ( $node instanceof DOMElement ) {
                $candidates[] = $node;
            }
        }

        $changed = false;
        $processed_text_nodes = array();

        foreach ( $candidates as $node ) {
            if ( ! self::is_reasonable_container( $node ) ) {
                continue;
            }

            // Structured dates win in the legacy parser before any text
            // fallback, so never rewrite clocks inside those event cards.
            if ( self::has_structured_datetime( $xpath, $node ) ) {
                continue;
            }

            // Broad wrappers are handled by earlier filters. Restrict this
            // layer to leaf-like event cards to avoid touching shared page UI.
            if ( self::has_independent_event_child( $xpath, $node ) ) {
                continue;
            }

            if ( self::neutralize_unbound_times( $xpath, $node, $processed_text_nodes ) ) {
                $changed = true;
            }
        }

        if ( ! $changed ) {
            return $html;
        }

        $output = $dom->saveHTML();
        if ( ! is_string( $output ) || '' === $output ) {
            return $html;
        }

        $output = preg_replace( '/^<\?xml[^>]+>\s*/i', '', $output );
        return is_string( $output ) && '' !== $output ? $output : $html;
    }

    private static function neutralize_unbound_times( $xpath, $node, &$processed_text_nodes ) {
        $text_nodes = $xpath->query( './/text()', $node );
        if ( ! $text_nodes || 0 === $text_nodes->length ) {
            return false;
        }

        $segments = array();
        $buffer = '';
        $cursor = 0;

        foreach ( $text_nodes as $text_node ) {
            if ( ! $text_node instanceof DOMText || self::skip_text_node( $text_node ) ) {
                continue;
            }

            $hash = spl_object_hash( $text_node );
            if ( isset( $processed_text_nodes[ $hash ] ) ) {
                continue;
            }

            $value = (string) $text_node->nodeValue;
            if ( '' === trim( $value ) ) {
                continue;
            }

            if ( '' !== $buffer ) {
                $buffer .= ' ';
                $cursor++;
            }

            $segments[] = array(
                'node'  => $text_node,
                'hash'  => $hash,
                'start' => $cursor,
                'text'  => $value,
            );

            $buffer .= $value;
            $cursor += strlen( $value );
        }

        if ( '' === $buffer ) {
            return false;
        }

        $date_spans = self::date_spans( $buffer );
        if ( ! $date_spans ) {
            return false;
        }

        $changed = false;

        foreach ( $segments as $segment ) {
            $value = $segment['text'];
            if ( ! preg_match_all(
                '/\b(?:saat|time)?\s*([01]?\d|2[0-3])[:\.]([0-5]\d)\b/iu',
                $value,
                $matches,
                PREG_OFFSET_CAPTURE
            ) ) {
                continue;
            }

            $replacements = array();

            foreach ( $matches[0] as $match ) {
                $match_text = (string) $match[0];
                $local_start = (int) $match[1];
                $global_start = $segment['start'] + $local_start;
                $global_end = $global_start + strlen( $match_text );

                if ( self::is_near_date( $global_start, $global_end, $date_spans ) ) {
                    continue;
                }

                $neutral = preg_replace( '/(?<=\d)[:\.](?=\d{2}\b)/u', '∶', $match_text, 1 );
                if ( ! is_string( $neutral ) || $neutral === $match_text ) {
                    continue;
                }

                $replacements[] = array(
                    'offset' => $local_start,
                    'length' => strlen( $match_text ),
                    'value'  => $neutral,
                );
            }

            if ( ! $replacements ) {
                continue;
            }

            usort( $replacements, function( $a, $b ) {
                return $b['offset'] - $a['offset'];
            } );

            foreach ( $replacements as $replacement ) {
                $value = substr( $value, 0, $replacement['offset'] )
                    . $replacement['value']
                    . substr( $value, $replacement['offset'] + $replacement['length'] );
            }

            $segment['node']->nodeValue = $value;
            $processed_text_nodes[ $segment['hash'] ] = true;
            $changed = true;
        }

        return $changed;
    }

    private static function date_spans( $text ) {
        $months = self::month_pattern();
        $patterns = array(
            '/\b\d{1,2}\s*[-–—]\s*\d{1,2}\s+(' . $months . ')\s+20\d{2}\b/iu',
            '/\b\d{1,2}\s+(' . $months . ')\s+20\d{2}\s*[-–—]\s*\d{1,2}\s+(' . $months . ')\s+20\d{2}\b/iu',
            '/\b\d{1,2}\s+(' . $months . ')\s+20\d{2}\b/iu',
            '/\b\d{1,2}[\.\/]\d{1,2}[\.\/]20\d{2}\s*[-–—]\s*\d{1,2}[\.\/]\d{1,2}[\.\/]20\d{2}\b/u',
            '/\b\d{1,2}[\.\/]\d{1,2}[\.\/]20\d{2}\b/u',
            '/\b20\d{2}-[01]\d-[0-3]\d\b/u',
        );

        $spans = array();
        foreach ( $patterns as $pattern ) {
            if ( ! preg_match_all( $pattern, $text, $matches, PREG_OFFSET_CAPTURE ) ) {
                continue;
            }
            foreach ( $matches[0] as $match ) {
                $start = (int) $match[1];
                $spans[] = array(
                    'start' => $start,
                    'end'   => $start + strlen( (string) $match[0] ),
                );
            }
        }

        return $spans;
    }

    private static function is_near_date( $time_start, $time_end, $date_spans ) {
        foreach ( $date_spans as $span ) {
            if ( $time_end < $span['start'] ) {
                $distance = $span['start'] - $time_end;
            } elseif ( $time_start > $span['end'] ) {
                $distance = $time_start - $span['end'];
            } else {
                $distance = 0;
            }

            if ( $distance <= self::MAX_DISTANCE ) {
                return true;
            }
        }

        return false;
    }

    private static function has_structured_datetime( $xpath, $node ) {
        $query = ".//*[@itemprop='startDate']/@content"
            . " | .//*[@itemprop='startDate']/@datetime"
            . " | .//*[@itemprop='endDate']/@content"
            . " | .//*[@itemprop='endDate']/@datetime"
            . ' | .//time/@datetime'
            . ' | .//*[@data-start]/@data-start'
            . ' | .//*[@data-date]/@data-date';

        $matches = $xpath->query( $query, $node );
        return $matches && $matches->length > 0;
    }

    private static function has_independent_event_child( $xpath, $node ) {
        $children = $xpath->query( './/*[' . self::event_predicate() . ']', $node );
        if ( ! $children || 0 === $children->length ) {
            return false;
        }

        foreach ( $children as $child ) {
            if ( ! $child instanceof DOMElement || ! self::is_reasonable_container( $child ) ) {
                continue;
            }

            $text = self::clean_text( $child->textContent );
            if ( self::date_spans( $text ) ) {
                return true;
            }
        }

        return false;
    }

    private static function event_node_query() {
        return '//article | //*[' . self::event_predicate() . ']';
    }

    private static function event_predicate() {
        return "(@itemscope and contains(translate(@itemtype,'EVENT','event'),'event'))"
            . " or (@class and (contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'event')"
            . " or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'etkinlik')"
            . " or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'calendar-item')"
            . " or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'agenda-item')"
            . " or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'conference')"
            . " or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'webinar')"
            . " or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'expo')))"
            . " or (@id and (contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'event')"
            . " or contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'etkinlik')))";
    }

    private static function is_reasonable_container( $node ) {
        $text = self::clean_text( $node->textContent );
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
        return $length >= 12 && $length <= 12000;
    }

    private static function skip_text_node( $text_node ) {
        $parent = $text_node->parentNode;
        while ( $parent instanceof DOMElement ) {
            $tag = strtolower( $parent->tagName );
            if ( in_array( $tag, array( 'script', 'style', 'noscript' ), true ) ) {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    private static function month_pattern() {
        return 'Ocak|Şubat|Subat|Mart|Nisan|Mayıs|Mayis|Haziran|Temmuz|Ağustos|Agustos|Eylül|Eylul|Ekim|Kasım|Kasim|Aralık|Aralik|January|February|March|April|May|June|July|August|September|October|November|December';
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return trim( (string) $value );
    }
}
