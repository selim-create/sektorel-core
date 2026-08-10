<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Event source / candidate text quality hotfix.
 *
 * Normalizes HTML entities, common Turkish UTF-8 mojibake and shouty title
 * casing without changing mixed-case brand names. It also suppresses obvious
 * generic HTML false positives already present in the candidate pool.
 */
class Sektorel_Event_Content_Quality {

    const CANDIDATE_CLEANUP_OPTION = 'sektorel_candidate_text_quality_1281';
    const SOURCE_CLEANUP_OPTION    = 'sektorel_source_text_quality_1281';
    const BATCH_SIZE               = 200;

    private static $candidate_guard_lock = false;

    public static function init() {
        add_filter( 'wp_insert_post_data', array( __CLASS__, 'normalize_post_data' ), 45, 2 );
        add_action( 'load-edit.php', array( __CLASS__, 'cleanup_existing_records' ), 50 );
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_validate_candidate' ), 70, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_validate_candidate' ), 70, 4 );
    }

    public static function normalize_post_data( $data, $postarr ) {
        $post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';
        if ( ! in_array( $post_type, array( 'event_source', 'event_candidate', 'event' ), true ) ) {
            return $data;
        }

        if ( isset( $data['post_title'] ) ) {
            $data['post_title'] = self::normalize_title( $data['post_title'] );
        }

        if ( 'event_candidate' === $post_type && isset( $data['post_content'] ) ) {
            $data['post_content'] = self::normalize_html_content( $data['post_content'] );
        }

        return $data;
    }

    public static function cleanup_existing_records() {
        global $typenow;

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( 'event_candidate' === $typenow ) {
            self::cleanup_candidates();
        } elseif ( 'event_source' === $typenow ) {
            self::cleanup_sources();
        }
    }

    private static function cleanup_candidates() {
        if ( get_option( self::CANDIDATE_CLEANUP_OPTION ) ) {
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => self::BATCH_SIZE,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );

        foreach ( $ids as $candidate_id ) {
            self::normalize_existing_post( absint( $candidate_id ), true );
            self::validate_candidate( absint( $candidate_id ) );
        }

        if ( count( $ids ) < self::BATCH_SIZE ) {
            update_option( self::CANDIDATE_CLEANUP_OPTION, current_time( 'mysql' ), false );
        }
    }

    private static function cleanup_sources() {
        if ( get_option( self::SOURCE_CLEANUP_OPTION ) ) {
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'any',
            'posts_per_page' => self::BATCH_SIZE,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );

        foreach ( $ids as $source_id ) {
            self::normalize_existing_post( absint( $source_id ), false );
        }

        if ( count( $ids ) < self::BATCH_SIZE ) {
            update_option( self::SOURCE_CLEANUP_OPTION, current_time( 'mysql' ), false );
        }
    }

    private static function normalize_existing_post( $post_id, $with_content ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return;
        }

        $postarr = array( 'ID' => $post_id );
        $changed = false;

        $title = self::normalize_title( $post->post_title );
        if ( $title && $title !== $post->post_title ) {
            $postarr['post_title'] = $title;
            $changed = true;
        }

        if ( $with_content ) {
            $content = self::normalize_html_content( $post->post_content );
            if ( $content !== $post->post_content ) {
                $postarr['post_content'] = $content;
                $changed = true;
            }
        }

        if ( $changed ) {
            wp_update_post( $postarr );
            if ( 'event_candidate' === $post->post_type ) {
                delete_post_meta( $post_id, 'candidate_match_signature' );
            }
        }
    }

    public static function maybe_validate_candidate( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$candidate_guard_lock || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        if ( ! in_array( $meta_key, array( 'parser_type', 'start_date', 'event_url', 'source_url', 'registration_link' ), true ) ) {
            return;
        }

        self::validate_candidate( absint( $object_id ) );
    }

    private static function validate_candidate( $candidate_id ) {
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return;
        }

        if ( 'html' !== (string) get_post_meta( $candidate_id, 'parser_type', true ) ) {
            return;
        }

        $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
        if ( in_array( $status, array( 'imported', 'ignored' ), true ) ) {
            return;
        }

        $reason = self::false_positive_reason( $candidate_id );
        if ( ! $reason ) {
            return;
        }

        self::$candidate_guard_lock = true;
        update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
        update_post_meta( $candidate_id, 'candidate_resolution', 'parser_false_positive' );
        update_post_meta( $candidate_id, 'candidate_quality_reason', sanitize_key( $reason ) );
        update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
        delete_post_meta( $candidate_id, 'candidate_match_signature' );
        self::$candidate_guard_lock = false;
    }

    private static function false_positive_reason( $candidate_id ) {
        $title = self::normalize_key( get_the_title( $candidate_id ) );
        if ( ! $title ) {
            return 'empty_title';
        }

        $generic_titles = array(
            'neler yaptik',
            'duyurular',
            'tum duyurular',
            'isimiz ticari diplomasi',
            'haberler',
            'tum haberler',
            'son haberler',
            'hakkimizda',
            'biz kimiz',
            'faaliyetler',
            'projeler',
            'hizmetler',
            'kurumsal',
            'iletisim',
            'ana sayfa',
            'anasayfa',
            'home',
            'about',
            'about us',
            'contact',
            'media',
            'arsiv',
            'archive',
            'site haritasi',
            'sitemap',
        );

        if ( in_array( $title, $generic_titles, true ) ) {
            return 'generic_section_title';
        }

        $start = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
        if ( self::is_stale_start_date( $start ) ) {
            return 'stale_start_date';
        }

        $event_url = trim( (string) get_post_meta( $candidate_id, 'event_url', true ) );
        if ( self::is_generic_navigation_url( $event_url ) && ! self::title_has_event_signal( get_the_title( $candidate_id ) ) ) {
            return 'generic_navigation_url';
        }

        return '';
    }

    private static function is_stale_start_date( $value ) {
        if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})/', (string) $value, $matches ) ) {
            return false;
        }

        try {
            $start = new DateTime( $matches[1], wp_timezone() );
            $cutoff = new DateTime( 'now', wp_timezone() );
            $cutoff->modify( '-45 days' );
            $cutoff->setTime( 0, 0, 0 );
            return $start < $cutoff;
        } catch ( Exception $e ) {
            return false;
        }
    }

    private static function is_generic_navigation_url( $url ) {
        $path = strtolower( (string) wp_parse_url( trim( (string) $url ), PHP_URL_PATH ) );
        if ( ! $path || '/' === $path ) {
            return false;
        }

        $path = '/' . trim( $path, '/' );
        return (bool) preg_match(
            '#^/(?:tr|en|de|fr)?/?(?:default|index|home)?(?:\.(?:html?|php))?/?$#i',
            $path
        );
    }

    private static function title_has_event_signal( $title ) {
        $title = self::normalize_key( $title );
        return (bool) preg_match(
            '/\b(etkinlik|fuar|expo|zirve|summit|kongre|congress|konferans|conference|webinar|seminer|seminar|sempozyum|symposium|calistay|workshop|festival|forum|panel|toplanti|meeting|demo day|bulusma|egitim|fair|exhibition|show|week)\b/i',
            $title
        );
    }

    public static function normalize_title( $value ) {
        $title = self::clean_text( $value );
        if ( ! $title ) {
            return '';
        }

        if ( self::looks_shouty( $title ) || self::looks_flat_lowercase( $title ) ) {
            $title = self::smart_title_case( $title );
        }

        $title = self::normalize_protected_terms( $title );
        return sanitize_text_field( trim( $title ) );
    }

    private static function normalize_html_content( $value ) {
        $value = (string) $value;
        if ( '' === $value ) {
            return '';
        }

        $value = preg_replace( '/&nbsp;?/i', ' ', $value );
        $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = self::repair_mojibake( $value );
        $value = str_replace( array( "\xC2\xA0", "\u{00A0}" ), ' ', $value );
        return wp_kses_post( $value );
    }

    private static function clean_text( $value ) {
        $value = (string) $value;
        $value = preg_replace( '/&nbsp;?/i', ' ', $value );
        $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = self::repair_mojibake( $value );
        $value = str_replace( array( "\xC2\xA0", "\u{00A0}" ), ' ', $value );
        $value = wp_strip_all_tags( $value );
        $value = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return trim( $value );
    }

    private static function repair_mojibake( $value ) {
        $map = array(
            'Ã¼' => 'ü', 'Ãœ' => 'Ü',
            'Ã¶' => 'ö', 'Ã–' => 'Ö',
            'Ã§' => 'ç', 'Ã‡' => 'Ç',
            'Ä±' => 'ı', 'Ä°' => 'İ',
            'ÅŸ' => 'ş', 'Å' => 'ş', 'Åž' => 'Ş',
            'ÄŸ' => 'ğ', 'Äž' => 'Ğ',
            'â€™' => '’', 'â€˜' => '‘',
            'â€œ' => '“', 'â€' => '”',
            'â€“' => '–', 'â€”' => '—',
            'â€¦' => '…', 'Â' => '',
        );

        for ( $i = 0; $i < 2; $i++ ) {
            $fixed = strtr( $value, $map );
            if ( $fixed === $value ) {
                break;
            }
            $value = $fixed;
        }

        return $value;
    }

    private static function looks_shouty( $title ) {
        $upper = preg_match_all( '/\p{Lu}/u', $title, $m1 );
        $lower = preg_match_all( '/\p{Ll}/u', $title, $m2 );
        $letters = $upper + $lower;
        return $letters >= 8 && $upper / max( 1, $letters ) >= 0.72;
    }

    private static function looks_flat_lowercase( $title ) {
        $upper = preg_match_all( '/\p{Lu}/u', $title, $m1 );
        $lower = preg_match_all( '/\p{Ll}/u', $title, $m2 );
        $letters = $upper + $lower;
        return $letters >= 8 && false !== strpos( $title, ' ' ) && $lower / max( 1, $letters ) >= 0.95;
    }

    private static function smart_title_case( $title ) {
        $parts = preg_split( '/(\s+)/u', $title, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( ! is_array( $parts ) ) {
            return $title;
        }

        $word_index = 0;
        $word_count = 0;
        foreach ( $parts as $part ) {
            if ( preg_match( '/\p{L}/u', $part ) ) {
                $word_count++;
            }
        }

        foreach ( $parts as &$part ) {
            if ( ! preg_match( '/\p{L}/u', $part ) ) {
                continue;
            }

            $word_index++;
            $part = self::title_case_token( $part, 1 === $word_index, $word_index === $word_count );
        }
        unset( $part );

        return implode( '', $parts );
    }

    private static function title_case_token( $token, $is_first, $is_last ) {
        if ( preg_match( '/^([^\p{L}\p{N}]*)([\p{L}\p{N}.\-]+)([’\'])([\p{L}]+)([^\p{L}\p{N}]*)$/u', $token, $m ) ) {
            $base = self::protected_term( $m[2] );
            if ( ! $base ) {
                $base = self::ucfirst_tr( self::lower_tr( $m[2] ) );
            }
            return $m[1] . $base . $m[3] . self::lower_tr( $m[4] ) . $m[5];
        }

        if ( ! preg_match( '/^([^\p{L}\p{N}]*)([\p{L}\p{N}.\-]+)([^\p{L}\p{N}]*)$/u', $token, $m ) ) {
            return $token;
        }

        $protected = self::protected_term( $m[2] );
        if ( $protected ) {
            return $m[1] . $protected . $m[3];
        }

        $lower = self::lower_tr( $m[2] );
        $small = array( 've', 'ile', 'and', 'of', 'the', 'by' );
        $word = ( ! $is_first && ! $is_last && in_array( $lower, $small, true ) )
            ? $lower
            : self::ucfirst_tr( $lower );

        return $m[1] . $word . $m[3];
    }

    private static function normalize_protected_terms( $title ) {
        $terms = self::protected_terms();
        foreach ( $terms as $key => $canonical ) {
            $title = preg_replace_callback(
                '/(?<![\p{L}\p{N}])' . preg_quote( $key, '/' ) . '(?![\p{L}\p{N}])/iu',
                static function() use ( $canonical ) {
                    return $canonical;
                },
                $title
            );
        }
        return $title;
    }

    private static function protected_term( $value ) {
        $key = self::lower_tr( trim( (string) $value ) );
        $terms = self::protected_terms();
        return isset( $terms[ $key ] ) ? $terms[ $key ] : '';
    }

    private static function protected_terms() {
        $terms = array(
            'expo' => 'EXPO',
            'ai' => 'AI',
            'ar-ge' => 'Ar-Ge',
            'arge' => 'Ar-Ge',
            'tto' => 'TTO',
            'gaün' => 'GAÜN',
            'tübitak' => 'TÜBİTAK',
            'kosgeb' => 'KOSGEB',
            'kvkk' => 'KVKK',
            'btk' => 'BTK',
            'tse' => 'TSE',
            'tobb' => 'TOBB',
            'tim' => 'TİM',
            'tİm' => 'TİM',
            'deik' => 'DEİK',
            'sasAd' => 'SASAD',
            'sasad' => 'SASAD',
            'gitex' => 'GITEX',
            'aws' => 'AWS',
            'peryön' => 'PERYÖN',
            'tüsiad' => 'TÜSİAD',
            'iso' => 'İSO',
            'İso' => 'İSO',
            'aso' => 'ASO',
            'spk' => 'SPK',
            'sgk' => 'SGK',
            'işkur' => 'İŞKUR',
            'tüik' => 'TÜİK',
            'denib' => 'DENİB',
            'iib' => 'İİB',
            'iİb' => 'İİB',
            'tmmob' => 'TMMOB',
            'gensed' => 'GENSED',
            'btm' => 'BTM',
            'odtü' => 'ODTÜ',
            'itü' => 'İTÜ',
            'müsiad' => 'MÜSİAD',
            'cbme' => 'CBME',
            'aymod' => 'AYMOD',
            'ifco' => 'IFCO',
            'idef' => 'IDEF',
            'saha' => 'SAHA',
            'icci' => 'ICCI',
            'ifat' => 'IFAT',
            'ibia' => 'IBIA',
            'isaf' => 'ISAF',
            'sedec' => 'SEDEC',
            'idma' => 'IDMA',
            'fespa' => 'FESPA',
            'viv' => 'VIV',
            'iiff' => 'IIFF',
            't.c.' => 'T.C.',
            'turkiye' => 'Türkiye',
        );

        /**
         * Filter protected event/source title terms.
         * Keys must be lower-case according to Turkish casing rules.
         */
        return (array) apply_filters( 'sektorel_event_title_protected_terms', $terms );
    }

    private static function lower_tr( $value ) {
        $value = strtr( (string) $value, array( 'I' => 'ı', 'İ' => 'i' ) );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }

    private static function ucfirst_tr( $value ) {
        if ( '' === $value ) {
            return '';
        }

        if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
            $first = mb_substr( $value, 0, 1, 'UTF-8' );
            $rest  = mb_substr( $value, 1, null, 'UTF-8' );
            if ( 'i' === $first ) {
                $first = 'İ';
            } elseif ( 'ı' === $first ) {
                $first = 'I';
            } else {
                $first = mb_strtoupper( $first, 'UTF-8' );
            }
            return $first . $rest;
        }

        return ucfirst( $value );
    }

    private static function normalize_key( $value ) {
        $value = self::lower_tr( remove_accents( self::clean_text( $value ) ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }
}
