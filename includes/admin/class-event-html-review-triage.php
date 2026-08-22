<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Advisory-only triage for unresolved HTML candidates.
 *
 * This class never changes candidate_status, candidate_resolution or event
 * records. It only stores a review recommendation + reasons to help an admin
 * separate likely real events from obvious editorial/marketing noise.
 */
class Sektorel_Event_HTML_Review_Triage {

    const ENGINE_VERSION = '1352';
    const OPTION_KEY     = 'sektorel_html_review_triage_1352';

    public static function init() {
        add_action( 'load-edit.php', array( __CLASS__, 'refresh_unresolved' ), 39 );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ), 39 );
        add_filter( 'manage_event_candidate_posts_columns', array( __CLASS__, 'add_column' ), 70 );
        add_action( 'manage_event_candidate_posts_custom_column', array( __CLASS__, 'render_column' ), 70, 2 );
    }

    public static function refresh_unresolved() {
        global $typenow;

        if ( 'event_candidate' !== $typenow || ! current_user_can( 'manage_options' ) || self::is_filtered_request() ) {
            return;
        }

        $ids = self::unresolved_html_ids();
        $counts = array( 'safe' => 0, 'review' => 0, 'noise' => 0 );

        foreach ( $ids as $candidate_id ) {
            $triage = self::classify( $candidate_id );
            self::store( $candidate_id, $triage );
            if ( isset( $counts[ $triage['level'] ] ) ) {
                $counts[ $triage['level'] ]++;
            }
        }

        if ( ! get_option( self::OPTION_KEY ) ) {
            $report = array(
                'checked' => count( $ids ),
                'safe'    => $counts['safe'],
                'review'  => $counts['review'],
                'noise'   => $counts['noise'],
                'version' => self::ENGINE_VERSION,
            );
            update_option( self::OPTION_KEY, $report, false );
            set_transient( self::notice_key(), $report, 10 * MINUTE_IN_SECONDS );
        }
    }

    public static function classify( $candidate_id ) {
        $candidate_id = absint( $candidate_id );
        $title        = self::normalize_key( get_the_title( $candidate_id ) );
        $event_url    = trim( (string) get_post_meta( $candidate_id, 'event_url', true ) );
        $confidence   = absint( get_post_meta( $candidate_id, 'candidate_confidence_score', true ) );
        $start        = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
        $end          = self::date_part( get_post_meta( $candidate_id, 'end_date', true ) );

        $strong_event = self::strong_event_identity( $title );
        $editorial    = self::editorial_url( $event_url );
        $marketing    = self::marketing_copy( $title );
        $malformed    = self::malformed_event_url( $event_url );
        $invalid_date = $start && $end && $end < $start;

        $score = min( 100, max( 0, $confidence ) );
        $reasons = array();

        if ( $strong_event ) {
            $score += 15;
            $reasons[] = 'strong_event_identity';
        }
        if ( self::detail_url( $event_url ) ) {
            $score += 5;
            $reasons[] = 'detail_event_url';
        }
        if ( $end && ! $invalid_date ) {
            $score += 5;
            $reasons[] = 'valid_date_range';
        }
        if ( $editorial ) {
            $score -= 40;
            $reasons[] = 'editorial_url';
        }
        if ( $marketing ) {
            $score -= 25;
            $reasons[] = 'marketing_copy';
        }
        if ( $malformed ) {
            $score -= 30;
            $reasons[] = 'malformed_event_url';
        }
        if ( $invalid_date ) {
            $score -= 60;
            $reasons[] = 'invalid_date_range';
        }

        $score = max( 0, min( 100, $score ) );
        $level = 'review';

        if ( $invalid_date || $malformed ) {
            $level = 'review';
        } elseif ( $editorial && ! $strong_event ) {
            $level = 'noise';
        } elseif ( $marketing && ! $strong_event && $score <= 55 ) {
            $level = 'noise';
        } elseif ( $strong_event && $confidence >= 60 && ! $editorial && ! $marketing ) {
            $level = 'safe';
        } elseif ( $score <= 25 ) {
            $level = 'noise';
        }

        if ( ! $reasons ) {
            $reasons[] = 'manual_review_needed';
        }

        return array(
            'level'   => $level,
            'score'   => $score,
            'reasons' => array_values( array_unique( $reasons ) ),
        );
    }

    public static function label( $level ) {
        $labels = array(
            'safe'   => 'Güvenli Aday',
            'review' => 'Manuel İnceleme',
            'noise'  => 'Muhtemel Gürültü',
        );
        return isset( $labels[ $level ] ) ? $labels[ $level ] : '—';
    }

    public static function add_column( $columns ) {
        $columns['candidate_review_triage'] = 'İnceleme Önerisi';
        return $columns;
    }

    public static function render_column( $column, $post_id ) {
        if ( 'candidate_review_triage' !== $column ) {
            return;
        }

        if ( 'html' !== (string) get_post_meta( $post_id, 'parser_type', true ) ) {
            echo '—';
            return;
        }

        $level = (string) get_post_meta( $post_id, 'candidate_triage_level', true );
        $score = get_post_meta( $post_id, 'candidate_triage_score', true );
        $reasons = (string) get_post_meta( $post_id, 'candidate_triage_reasons', true );

        if ( ! $level ) {
            $triage = self::classify( $post_id );
            $level = $triage['level'];
            $score = $triage['score'];
            $reasons = implode( ', ', $triage['reasons'] );
        }

        echo '<strong>' . esc_html( self::label( $level ) ) . '</strong>';
        echo '<br><small>' . esc_html( absint( $score ) . '/100' ) . '</small>';
        if ( $reasons ) {
            echo '<br><small title="' . esc_attr( $reasons ) . '">' . esc_html( self::short_reason( $reasons ) ) . '</small>';
        }
    }

    private static function store( $candidate_id, $triage ) {
        update_post_meta( $candidate_id, 'candidate_triage_level', sanitize_key( $triage['level'] ) );
        update_post_meta( $candidate_id, 'candidate_triage_score', absint( $triage['score'] ) );
        update_post_meta( $candidate_id, 'candidate_triage_reasons', sanitize_text_field( implode( ', ', $triage['reasons'] ) ) );
        update_post_meta( $candidate_id, 'candidate_triage_version', self::ENGINE_VERSION );
    }

    private static function strong_event_identity( $title ) {
        return (bool) preg_match(
            '/\b(fuar(?:i)?|fair|expo|exhibition|summit|ticaret heyeti|trade mission|kongre(?:si)?|congress|conference|konferans(?:i)?|festival|teknofest|fashion connection|brand week|webrazzi|fintech|marmomac|middle east energy)\b/i',
            $title
        );
    }

    private static function editorial_url( $url ) {
        $path = strtolower( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
        if ( ! $path ) {
            return false;
        }
        return (bool) preg_match(
            '#/(haber|news|duyuru|duyurular|blog|postshowreports|tum-haberler|sayfalar/anasayfa|marka)(?:/|$)#i',
            $path
        );
    }

    private static function marketing_copy( $title ) {
        return (bool) preg_match(
            '/(bulusma noktasi|is platformu|hazirliklari.*devam|kapsaminda neler var|media partners|undisputed leader|kalbi .* atiyor|prestijli bulusma noktasi|tarihi zirve|destek programi|fikir hazinesi|hibe destegi)/i',
            $title
        );
    }

    private static function malformed_event_url( $url ) {
        $url = strtolower( (string) $url );
        return false !== strpos( $url, 'mailto' );
    }

    private static function detail_url( $url ) {
        $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
        return '' !== $path && false === self::editorial_url( $url );
    }

    private static function unresolved_html_ids() {
        return array_values( array_map( 'absint', get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'parser_type', 'value' => 'html' ),
                array( 'key' => 'candidate_status', 'value' => array( 'new', 'incomplete' ), 'compare' => 'IN' ),
            ),
        ) ) ) );
    }

    private static function date_part( $value ) {
        return preg_match( '/^(\d{4}-\d{2}-\d{2})/', (string) $value, $m ) ? $m[1] : '';
    }

    private static function normalize_key( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = strtolower( remove_accents( $value ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', (string) $value ) );
    }

    private static function short_reason( $reasons ) {
        $parts = array_filter( array_map( 'trim', explode( ',', (string) $reasons ) ) );
        return isset( $parts[0] ) ? str_replace( '_', ' ', $parts[0] ) : '—';
    }

    private static function is_filtered_request() {
        foreach ( array( 'candidate_confidence', 'candidate_match_status', 'candidate_parser', 'candidate_quality', 's', 'm' ) as $key ) {
            if ( isset( $_GET[ $key ] ) && '' !== trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) ) {
                return true;
            }
        }
        return false;
    }

    public static function render_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'event_candidate' !== $screen->post_type || 'edit' !== $screen->base ) {
            return;
        }

        $report = get_transient( self::notice_key() );
        if ( ! is_array( $report ) ) {
            return;
        }

        delete_transient( self::notice_key() );
        echo '<div class="notice notice-info is-dismissible"><p><strong>HTML Review Triage 1.35.2:</strong> ' . esc_html( sprintf(
            'Kontrol edilen: %1$d; Güvenli Aday: %2$d; Manuel İnceleme: %3$d; Muhtemel Gürültü: %4$d. Bu öneriler advisory amaçlıdır; hiçbir adayın durumu değiştirilmedi.',
            absint( $report['checked'] ),
            absint( $report['safe'] ),
            absint( $report['review'] ),
            absint( $report['noise'] )
        ) ) . '</p></div>';
    }

    private static function notice_key() {
        return 'sektorel_html_review_triage_notice_' . absint( get_current_user_id() );
    }
}
