<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Removes clearly stale leaf event cards from HTML candidate scan responses.
 *
 * This runs only during the HTML scan AJAX request and uses the same 45-day
 * stale threshold as the candidate content-quality layer. The goal is to avoid
 * repeatedly upserting historical event archives such as Webrazzi's past
 * events list while leaving current/upcoming cards untouched.
 */
class Sektorel_Event_Candidate_HTML_Stale_Filter {

    const ENGINE_VERSION = '1331';
    const STALE_DAYS     = 45;

    public static function init() {
        add_filter( 'http_response', array( __CLASS__, 'filter_scan_response' ), 25, 3 );
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

        $filtered = self::remove_stale_leaf_cards( $body );
        if ( '' !== $filtered ) {
            $response['body'] = $filtered;
        }

        return $response;
    }

    private static function remove_stale_leaf_cards( $html ) {
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
        foreach ( $candidates as $node ) {
            if ( ! $node->parentNode || ! self::is_reasonable_container( $node ) ) {
                continue;
            }

            // Only prune leaf cards. Broad wrappers are handled by the nested
            // container filter and must not be removed here.
            if ( self::has_independent_event_child( $xpath, $node ) ) {
                continue;
            }

            if ( ! self::has_title_signal( $xpath, $node ) ) {
                continue;
            }

            $date = self::first_event_date( $xpath, $node );
            if ( ! $date || ! self::is_stale_date( $date ) ) {
                continue;
            }

            $node->parentNode->removeChild( $node );
            $changed = true;
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

    private static function has_independent_event_child( $xpath, $node ) {
        $inner_nodes = $xpath->query( './/*[' . self::event_predicate() . ']', $node );
        if ( ! $inner_nodes || 0 === $inner_nodes->length ) {
            return false;
        }

        foreach ( $inner_nodes as $inner ) {
            if ( ! $inner instanceof DOMElement || ! self::is_reasonable_container( $inner ) ) {
                continue;
            }
            if ( self::has_title_signal( $xpath, $inner ) && self::first_event_date( $xpath, $inner ) ) {
                return true;
            }
        }

        return false;
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
                if ( $length < 4 || $length > 220 ) {
                    continue;
                }
                $normalized = strtolower( remove_accents( $title ) );
                if ( ! in_array( $normalized, array( 'event', 'events', 'etkinlikler', 'takvim', 'calendar', 'agenda' ), true ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function first_event_date( $xpath, $node ) {
        $queries = array(
            ".//*[@itemprop='startDate']/@content",
            ".//*[@itemprop='startDate']/@datetime",
            './/time/@datetime',
            ".//*[@data-start]/@data-start",
            ".//*[@data-date]/@data-date",
        );

        foreach ( $queries as $query ) {
            $matches = $xpath->query( $query, $node );
            if ( ! $matches ) {
                continue;
            }
            foreach ( $matches as $match ) {
                $parsed = self::parse_date( trim( (string) $match->nodeValue ) );
                if ( $parsed ) {
                    return $parsed;
                }
            }
        }

        $text = self::clean_text( $node->textContent );
        $months = self::month_pattern();

        if ( preg_match( '/\b(\d{1,2})\s+(' . $months . ')\s+(20\d{2})\b/iu', $text, $m ) ) {
            $month = self::month_number( $m[2] );
            if ( $month && checkdate( $month, (int) $m[1], (int) $m[3] ) ) {
                return sprintf( '%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1] );
            }
        }

        if ( preg_match( '/\b(\d{1,2})[\.\/]([01]?\d)[\.\/](20\d{2})\b/u', $text, $m ) ) {
            if ( checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
                return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
            }
        }

        if ( preg_match( '/\b(20\d{2})-([01]\d)-([0-3]\d)\b/u', $text, $m ) ) {
            if ( checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
                return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
            }
        }

        return '';
    }

    private static function parse_date( $value ) {
        if ( '' === $value ) {
            return '';
        }

        try {
            $date = new DateTime( $value );
            return $date->format( 'Y-m-d' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    private static function is_stale_date( $value ) {
        if ( ! preg_match( '/^(20\d{2})-(\d{2})-(\d{2})$/', (string) $value ) ) {
            return false;
        }

        try {
            $date = new DateTime( $value, wp_timezone() );
            $cutoff = new DateTime( 'now', wp_timezone() );
            $cutoff->modify( '-' . self::STALE_DAYS . ' days' );
            $cutoff->setTime( 0, 0, 0 );
            return $date < $cutoff;
        } catch ( Exception $e ) {
            return false;
        }
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

    private static function month_pattern() {
        return 'Ocak|Şubat|Subat|Mart|Nisan|Mayıs|Mayis|Haziran|Temmuz|Ağustos|Agustos|Eylül|Eylul|Ekim|Kasım|Kasim|Aralık|Aralik|January|February|March|April|May|June|July|August|September|October|November|December';
    }

    private static function month_number( $month ) {
        $key = strtolower( remove_accents( trim( (string) $month ) ) );
        $map = array(
            'ocak'=>1,'january'=>1,'subat'=>2,'february'=>2,'mart'=>3,'march'=>3,
            'nisan'=>4,'april'=>4,'mayis'=>5,'may'=>5,'haziran'=>6,'june'=>6,
            'temmuz'=>7,'july'=>7,'agustos'=>8,'august'=>8,'eylul'=>9,'september'=>9,
            'ekim'=>10,'october'=>10,'kasim'=>11,'november'=>11,'aralik'=>12,'december'=>12,
        );
        return isset( $map[ $key ] ) ? $map[ $key ] : 0;
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
