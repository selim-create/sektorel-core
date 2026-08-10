<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ES-4B/ES-5 bridge: safe HTML scan queue + candidate confidence triage.
 *
 * Goals:
 * - Do not send generic official-institution homepages to the HTML parser.
 * - Preserve verified/list-like official sources.
 * - Score existing and future HTML candidates without touching JSON-LD.
 * - Auto-ignore only very low-confidence obvious false positives.
 * - Keep medium-confidence records visible for manual review.
 */
class Sektorel_Event_Candidate_Confidence {

    const ENGINE_VERSION = '1300';
    const BATCH_SIZE     = 200;
    const HIGH_THRESHOLD = 70;
    const LOW_THRESHOLD  = 30;

    private static $scoring = false;

    public static function init() {
        // Runs before the legacy HTML prepare callback (priority 10). We write
        // the same transient format so the existing batch worker remains the
        // single scanner implementation.
        add_action( 'wp_ajax_sektorel_prepare_html_event_scan', array( __CLASS__, 'prepare_safe_html_scan' ), 5 );

        add_action( 'load-edit.php', array( __CLASS__, 'classify_existing_batch' ), 26 );
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_score_on_meta' ), 30, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_score_on_meta' ), 30, 4 );

        add_filter( 'manage_event_candidate_posts_columns', array( __CLASS__, 'candidate_columns' ), 85 );
        add_action( 'manage_event_candidate_posts_custom_column', array( __CLASS__, 'render_candidate_column' ), 85, 2 );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'candidate_filter' ), 85 );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_candidate_filter' ), 85 );

        // Target-status UX: no more Ctrl+F counting on 250-row source lists.
        add_action( 'restrict_manage_posts', array( __CLASS__, 'source_target_filter' ), 90 );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_source_target_filter' ), 90 );
        add_filter( 'views_edit-event_source', array( __CLASS__, 'source_target_views' ), 90 );

        add_action( 'admin_notices', array( __CLASS__, 'html_scan_notice' ) );
    }

    /**
     * Replace the HTML scan queue with a safer subset while keeping the old
     * batch worker intact.
     */
    public static function prepare_safe_html_scan() {
        check_ajax_referer( 'sektorel_event_candidate_html', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $ids = self::safe_html_source_ids();
        if ( ! $ids ) {
            wp_send_json_error( array( 'message' => 'Güvenli HTML taramasına uygun kaynak bulunamadı.' ) );
        }

        $token = strtolower( wp_generate_password( 24, false, false ) );
        $key   = 'sektorel_html_' . absint( get_current_user_id() ) . '_' . sanitize_key( $token );
        set_transient( $key, $ids, 2 * HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $ids ),
            'safe_queue' => true,
        ) );
    }

    private static function safe_html_source_ids() {
        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'source_status', 'value' => 'active' ),
                array( 'key' => 'check_state', 'value' => 'ok' ),
                array( 'key' => 'detected_parser', 'value' => 'html' ),
            ),
        ) );

        $safe = array();
        foreach ( $ids as $source_id ) {
            $source_id = absint( $source_id );
            $type      = self::normalize( get_post_meta( $source_id, 'source_type', true ) );
            $target    = (string) get_post_meta( $source_id, 'target_discovery_status', true );
            $url       = trim( (string) get_post_meta( $source_id, 'source_url', true ) );

            // Normal event/fair/congress sources stay in the generic HTML
            // pipeline. Official institutions require a verified/list-like
            // endpoint; their homepages belong to adapters/official calendar.
            if ( false === strpos( $type, 'resmi kurum' ) ) {
                $safe[] = $source_id;
                continue;
            }

            if ( 'verified_listing' === $target || self::looks_like_listing_url( $url ) ) {
                $safe[] = $source_id;
            }
        }

        return array_values( array_unique( array_map( 'absint', $safe ) ) );
    }

    private static function looks_like_listing_url( $url ) {
        $path = self::normalize( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
        if ( ! $path || '/' === trim( $path ) ) {
            return false;
        }

        if ( preg_match( '/\b(about|hakkinda|kurallar|rules|support|haber|news|blog|press|basin bulten|iletisim|contact|site haritasi)\b/i', $path ) ) {
            return false;
        }

        return (bool) preg_match( '/\b(etkinlik|etkinlikler|event|events|takvim|calendar|agenda|fuarlar|webinar|seminar)\b/i', $path );
    }

    public static function classify_existing_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        if ( 'event_candidate' !== $post_type ) {
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        $processed = 0;
        foreach ( $ids as $candidate_id ) {
            if ( $processed >= self::BATCH_SIZE ) {
                break;
            }
            $candidate_id = absint( $candidate_id );
            if ( 'html' !== (string) get_post_meta( $candidate_id, 'parser_type', true ) ) {
                continue;
            }
            if ( self::ENGINE_VERSION === (string) get_post_meta( $candidate_id, 'candidate_confidence_version', true ) ) {
                continue;
            }
            self::score_candidate( $candidate_id );
            $processed++;
        }
    }

    public static function maybe_score_on_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$scoring || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        if ( 'parser_type' === $meta_key && 'html' === (string) $meta_value ) {
            $status = (string) get_post_meta( $object_id, 'candidate_status', true );
            if ( $status ) {
                self::score_candidate( absint( $object_id ) );
            }
            return;
        }

        if ( 'candidate_status' === $meta_key && 'new' === (string) $meta_value && 'html' === (string) get_post_meta( $object_id, 'parser_type', true ) ) {
            self::score_candidate( absint( $object_id ) );
        }
    }

    private static function score_candidate( $candidate_id ) {
        if ( ! $candidate_id || self::$scoring ) {
            return;
        }

        if ( 'html' !== (string) get_post_meta( $candidate_id, 'parser_type', true ) ) {
            return;
        }

        $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
        if ( in_array( $status, array( 'imported', 'ignored' ), true ) ) {
            update_post_meta( $candidate_id, 'candidate_confidence_version', self::ENGINE_VERSION );
            return;
        }

        self::$scoring = true;

        $title       = self::clean_text( get_the_title( $candidate_id ) );
        $title_norm  = self::normalize( $title );
        $content     = self::clean_text( get_post_field( 'post_content', $candidate_id ) );
        $source_id   = absint( get_post_meta( $candidate_id, 'source_id', true ) );
        $source_url  = trim( (string) get_post_meta( $candidate_id, 'source_url', true ) );
        $event_url   = trim( (string) get_post_meta( $candidate_id, 'event_url', true ) );
        $register    = trim( (string) get_post_meta( $candidate_id, 'registration_link', true ) );
        $end_date    = trim( (string) get_post_meta( $candidate_id, 'end_date', true ) );
        $venue       = trim( (string) get_post_meta( $candidate_id, 'venue', true ) );
        $organizer   = trim( (string) get_post_meta( $candidate_id, 'organizer', true ) );
        $source_type = $source_id ? self::normalize( get_post_meta( $source_id, 'source_type', true ) ) : '';
        $target      = $source_id ? (string) get_post_meta( $source_id, 'target_discovery_status', true ) : '';
        $source_title= $source_id ? self::clean_text( get_the_title( $source_id ) ) : '';

        $score   = 35;
        $reasons = array();

        if ( 'verified_listing' === $target ) {
            $score += 20;
            $reasons[] = 'verified_listing_source';
        }

        if ( false === strpos( $source_type, 'resmi kurum' ) ) {
            $score += 5;
            $reasons[] = 'event_focused_source_type';
        }

        if ( self::has_event_word( $title_norm ) ) {
            $score += 25;
            $reasons[] = 'event_word_in_title';
        }

        if ( preg_match( '/\b20\d{2}\b/', $title ) ) {
            $score += 10;
            $reasons[] = 'year_in_title';
        }

        $overlap = self::title_overlap( $title, $source_title );
        if ( $overlap >= 0.45 ) {
            $score += 18;
            $reasons[] = 'source_title_overlap';
        }

        if ( $event_url && ! self::same_url( $event_url, $source_url ) && self::eventish_url( $event_url ) ) {
            $score += 12;
            $reasons[] = 'event_detail_url';
        }

        if ( $register && ! self::same_url( $register, $source_url ) && ! self::same_url( $register, $event_url ) ) {
            $score += 10;
            $reasons[] = 'distinct_registration_url';
        }

        if ( $end_date ) {
            $score += 5;
            $reasons[] = 'end_date_present';
        }
        if ( $venue ) {
            $score += 4;
            $reasons[] = 'venue_present';
        }
        if ( $organizer ) {
            $score += 4;
            $reasons[] = 'organizer_present';
        }

        if ( self::is_generic_title( $title_norm ) ) {
            $score -= 80;
            $reasons[] = 'generic_navigation_title';
        }

        if ( preg_match( '/^(why|neden)\b|\b(media partners?|our media partners?)\b/i', $title_norm ) ) {
            $score -= 55;
            $reasons[] = 'marketing_section_title';
        }

        if ( preg_match( '/\b(raporu|rapor|kurul kararlari|kararlari|duyuru|duyurular|basin bulteni|haber|haberler|privacy|kvkk|gizlilik|cerez|cookie)\b/i', $title_norm ) ) {
            $score -= 45;
            $reasons[] = 'news_or_document_title';
        }

        if ( preg_match( '#/(news|haber|blog|press|basin-bulten|duyuru|market-insights|privacy|kvkk)(/|$)#i', (string) wp_parse_url( $event_url, PHP_URL_PATH ) ) ) {
            $score -= 35;
            $reasons[] = 'news_or_content_url';
        }

        if ( preg_match( '/\b(hedefleri|hizmetlerimiz|services|videolar|videos|tv|neler yaptik|isimiz ticari diplomasi|fuar alani|fair area)\b/i', $title_norm ) ) {
            $score -= 60;
            $reasons[] = 'non_event_section_title';
        }

        if ( ! self::has_event_word( $title_norm ) && ! preg_match( '/\b20\d{2}\b/', $title ) && $overlap < 0.30 ) {
            $score -= 20;
            $reasons[] = 'weak_event_identity';
        }

        $word_count = count( array_filter( preg_split( '/\s+/u', $title_norm ) ) );
        if ( $word_count <= 2 && ! self::has_event_word( $title_norm ) ) {
            $score -= 15;
            $reasons[] = 'very_short_weak_title';
        }

        // Content can rescue a short branded title, but never a known generic
        // navigation/document title.
        if ( $score >= 20 && ! self::is_generic_title( $title_norm ) && self::has_event_word( self::normalize( $content ) ) ) {
            $score += 5;
            $reasons[] = 'event_context_in_content';
        }

        $score = max( 0, min( 100, (int) $score ) );
        if ( $score >= self::HIGH_THRESHOLD ) {
            $level = 'high';
        } elseif ( $score < self::LOW_THRESHOLD ) {
            $level = 'low';
        } else {
            $level = 'medium';
        }

        update_post_meta( $candidate_id, 'candidate_confidence_score', $score );
        update_post_meta( $candidate_id, 'candidate_confidence_level', $level );
        update_post_meta( $candidate_id, 'candidate_confidence_reasons', array_values( array_unique( $reasons ) ) );
        update_post_meta( $candidate_id, 'candidate_confidence_review', 'medium' === $level ? 1 : 0 );
        update_post_meta( $candidate_id, 'candidate_confidence_version', self::ENGINE_VERSION );
        update_post_meta( $candidate_id, 'candidate_confidence_at', current_time( 'mysql' ) );

        // Only obvious low-confidence *new* HTML candidates are auto-ignored.
        // Existing/changed matches carry stronger evidence and are never
        // overridden by this heuristic layer.
        if ( 'low' === $level && 'new' === $status ) {
            update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
            update_post_meta( $candidate_id, 'candidate_resolution', 'confidence_false_positive' );
            update_post_meta( $candidate_id, 'candidate_confidence_auto_ignored', 1 );
        }

        self::$scoring = false;
    }

    private static function has_event_word( $value ) {
        return (bool) preg_match( '/\b(event|etkinlik|fuar|fair|expo|conference|konferans|summit|zirve|congress|kongre|webinar|seminar|seminer|sempozyum|symposium|workshop|calistay|festival|forum|demo day|hackathon|heyet|bulusma|toplanti|meeting|show)\b/i', $value );
    }

    private static function is_generic_title( $title ) {
        $title = trim( $title );
        $exact = array(
            'etkinlikler','events','event','takvim','calendar','agenda','gundem','duyurular','haberler','news',
            'videolar','videos','hizmetlerimiz','services','hakkimizda','about','iletisim','contact','home','ana sayfa',
            'neler yaptik','isimiz ticari diplomasi','fuar alani','fair area','borsa istanbul tv','medya','media',
        );
        return in_array( $title, $exact, true );
    }

    private static function eventish_url( $url ) {
        $path = self::normalize( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
        if ( ! $path || '/' === trim( $path ) ) {
            return false;
        }
        if ( preg_match( '/\b(news|haber|blog|press|privacy|kvkk|about|hakkinda|contact|iletisim)\b/i', $path ) ) {
            return false;
        }
        return (bool) preg_match( '/\b(event|events|etkinlik|etkinlikler|fuar|expo|summit|zirve|kongre|conference|webinar|seminar|agenda|program|programme|register|registration|ticket)\b/i', $path );
    }

    private static function title_overlap( $candidate, $source ) {
        $a = self::title_tokens( $candidate );
        $b = self::title_tokens( $source );
        if ( ! $a || ! $b ) {
            return 0.0;
        }
        $shared = array_intersect( $a, $b );
        return count( $shared ) / max( 1, min( count( $a ), count( $b ) ) );
    }

    private static function title_tokens( $value ) {
        $tokens = preg_split( '/\s+/u', self::normalize( $value ) );
        $stop = array( 've','ile','the','of','and','bir','icin','uluslararasi','turkiye','turkey','istanbul','fuar','fuari','zirve','zirvesi','webinar','kongre','kongresi','etkinlik','etkinlikleri' );
        $result = array();
        foreach ( (array) $tokens as $token ) {
            if ( strlen( $token ) < 3 || in_array( $token, $stop, true ) ) {
                continue;
            }
            $result[] = $token;
        }
        return array_values( array_unique( $result ) );
    }

    private static function same_url( $left, $right ) {
        $a = self::url_identity( $left );
        $b = self::url_identity( $right );
        return $a && $b && $a === $b;
    }

    private static function url_identity( $url ) {
        $parts = wp_parse_url( trim( (string) $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }
        $host = strtolower( preg_replace( '/^www\./', '', rtrim( $parts['host'], '.' ) ) );
        $path = isset( $parts['path'] ) ? '/' . trim( $parts['path'], '/' ) : '/';
        return $host . $path;
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return trim( (string) $value );
    }

    private static function normalize( $value ) {
        $value = strtolower( remove_accents( self::clean_text( $value ) ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    public static function candidate_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'parser_type' === $key || 'candidate_status' === $key ) {
                $new['candidate_confidence'] = 'Aday Güveni';
            }
        }
        if ( ! isset( $new['candidate_confidence'] ) ) {
            $new['candidate_confidence'] = 'Aday Güveni';
        }
        return $new;
    }

    public static function render_candidate_column( $column, $post_id ) {
        if ( 'candidate_confidence' !== $column ) {
            return;
        }
        if ( 'html' !== (string) get_post_meta( $post_id, 'parser_type', true ) ) {
            echo '—';
            return;
        }
        $score = get_post_meta( $post_id, 'candidate_confidence_score', true );
        $level = (string) get_post_meta( $post_id, 'candidate_confidence_level', true );
        if ( '' === (string) $score || ! $level ) {
            echo 'Bekliyor';
            return;
        }
        $labels = array( 'high' => 'Yüksek', 'medium' => 'Manuel kontrol', 'low' => 'Düşük' );
        $colors = array( 'high' => '#116329', 'medium' => '#996800', 'low' => '#b32d2e' );
        $label = isset( $labels[ $level ] ) ? $labels[ $level ] : $level;
        $color = isset( $colors[ $level ] ) ? $colors[ $level ] : '#646970';
        echo '<strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $label ) . '</strong>';
        echo '<br><span style="font-size:11px;color:#646970;">' . esc_html( (string) absint( $score ) ) . '/100</span>';
    }

    public static function candidate_filter() {
        global $typenow;
        if ( 'event_candidate' !== $typenow ) {
            return;
        }
        $selected = isset( $_GET['candidate_confidence'] ) ? sanitize_key( wp_unslash( $_GET['candidate_confidence'] ) ) : '';
        echo '<select name="candidate_confidence">';
        echo '<option value="">Tüm aday güvenleri</option>';
        foreach ( array( 'high' => 'Yüksek güven', 'medium' => 'Manuel kontrol', 'low' => 'Düşük güven' ) as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public static function apply_candidate_filter( $query ) {
        global $pagenow;
        if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || 'event_candidate' !== $query->get( 'post_type' ) ) {
            return;
        }
        $value = isset( $_GET['candidate_confidence'] ) ? sanitize_key( wp_unslash( $_GET['candidate_confidence'] ) ) : '';
        if ( ! in_array( $value, array( 'high', 'medium', 'low' ), true ) ) {
            return;
        }
        self::append_meta_query( $query, array( 'key' => 'candidate_confidence_level', 'value' => $value ) );
    }

    public static function source_target_filter() {
        global $typenow;
        if ( 'event_source' !== $typenow ) {
            return;
        }
        $selected = isset( $_GET['target_state'] ) ? sanitize_key( wp_unslash( $_GET['target_state'] ) ) : '';
        echo '<select name="target_state">';
        echo '<option value="">Tüm hedef durumları</option>';
        echo '<option value="verified_listing" ' . selected( $selected, 'verified_listing', false ) . '>Doğrulanmış liste</option>';
        echo '<option value="rolled_back_unsafe" ' . selected( $selected, 'rolled_back_unsafe', false ) . '>Geri alındı</option>';
        echo '</select>';
    }

    public static function apply_source_target_filter( $query ) {
        global $pagenow;
        if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || 'event_source' !== $query->get( 'post_type' ) ) {
            return;
        }
        $value = isset( $_GET['target_state'] ) ? sanitize_key( wp_unslash( $_GET['target_state'] ) ) : '';
        if ( ! in_array( $value, array( 'verified_listing', 'rolled_back_unsafe' ), true ) ) {
            return;
        }
        self::append_meta_query( $query, array( 'key' => 'target_discovery_status', 'value' => $value ) );
    }

    public static function source_target_views( $views ) {
        $verified = self::count_sources_by_target_state( 'verified_listing' );
        $rolled   = self::count_sources_by_target_state( 'rolled_back_unsafe' );
        $base     = admin_url( 'edit.php?post_type=event_source' );
        $views['target_verified'] = '<a href="' . esc_url( add_query_arg( 'target_state', 'verified_listing', $base ) ) . '">Doğrulanmış liste <span class="count">(' . absint( $verified ) . ')</span></a>';
        $views['target_rolled']   = '<a href="' . esc_url( add_query_arg( 'target_state', 'rolled_back_unsafe', $base ) ) . '">Geri alındı <span class="count">(' . absint( $rolled ) . ')</span></a>';
        return $views;
    }

    private static function count_sources_by_target_state( $state ) {
        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_key'       => 'target_discovery_status',
            'meta_value'     => sanitize_key( $state ),
        ) );
        return count( $ids );
    }

    private static function append_meta_query( $query, $clause ) {
        $meta_query = $query->get( 'meta_query' );
        if ( ! is_array( $meta_query ) ) {
            $meta_query = array();
        }
        $meta_query[] = $clause;
        $query->set( 'meta_query', $meta_query );
    }

    public static function html_scan_notice() {
        if ( ! isset( $_GET['page'] ) || 'sektorel-html-events' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $count = count( self::safe_html_source_ids() );
        echo '<div class="notice notice-info"><p><strong>Güvenli HTML kuyruğu aktif.</strong> Resmî kurum ana sayfaları tarama dışında bırakılır; yalnız doğrulanmış/list-like resmî hedefler ve etkinlik odaklı diğer HTML kaynakları kuyruğa alınır. Bu çalıştırmada yaklaşık <strong>' . esc_html( (string) $count ) . '</strong> kaynak işlenecek.</p></div>';
    }
}
