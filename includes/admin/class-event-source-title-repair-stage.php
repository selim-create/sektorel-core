<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Repairs only verified ICCI/IIFF HTML title failures before deterministic matching.
 * This stage never creates or updates Event posts.
 */
class Sektorel_Event_Source_Title_Repair_Stage {

    const NONCE_ACTION = 'sektorel_source_title_repair';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 10;

    public static function init() {
        add_action( 'wp_ajax_sektorel_source_title_repair_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_source_title_repair_batch', array( __CLASS__, 'ajax_batch' ) );
        add_filter( 'sektorel_source_center_stages', array( __CLASS__, 'inject_stage' ), 10000 );
        add_filter( 'sektorel_source_background_action_map', array( __CLASS__, 'filter_action_map' ), 10000 );
        add_filter( 'sektorel_source_background_nonce_actions', array( __CLASS__, 'filter_nonce_action_map' ), 10000 );
    }

    public static function inject_stage( $stages ) {
        $stage = array(
            'key'             => 'source_title_repair',
            'label'           => 'Kaynak Başlığını Doğrula',
            'description'     => 'Doğrulanmış ICCI/IIFF HTML başlık hatalarını matcher öncesinde güvenli biçimde düzeltir; aynı canonical candidate zaten varsa bozuk başlıklı kopyayı arşivler.',
            'prepare_action'  => 'sektorel_source_title_repair_prepare',
            'batch_action'    => 'sektorel_source_title_repair_batch',
            'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
            'prepare_payload' => array(),
        );

        $result   = array();
        $inserted = false;
        foreach ( (array) $stages as $item ) {
            if ( ! $inserted && isset( $item['key'] ) && 'candidate_matcher' === $item['key'] ) {
                $result[] = $stage;
                $inserted = true;
            }
            $result[] = $item;
        }
        if ( ! $inserted ) {
            $result[] = $stage;
        }
        return $result;
    }

    public static function filter_action_map( $map ) {
        $map = (array) $map;
        $map['sektorel_source_title_repair_prepare'] = array( __CLASS__, 'ajax_prepare' );
        $map['sektorel_source_title_repair_batch']   = array( __CLASS__, 'ajax_batch' );
        return $map;
    }

    public static function filter_nonce_action_map( $map ) {
        $map = (array) $map;
        $map['sektorel_source_title_repair_prepare'] = self::NONCE_ACTION;
        $map['sektorel_source_title_repair_batch']   = self::NONCE_ACTION;
        return $map;
    }

    public static function ajax_prepare() {
        self::require_ajax();
        $ids   = self::eligible_candidate_ids();
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), array_values( $ids ), self::QUEUE_TTL );
        wp_send_json_success( array( 'token' => $token, 'total' => count( $ids ) ) );
    }

    public static function ajax_batch() {
        self::require_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        $key    = self::queue_key( get_current_user_id(), $token );
        $ids    = get_transient( $key );

        if ( ! $token || ! is_array( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Başlık onarım kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $ids      = array_values( array_map( 'absint', $ids ) );
        $batch    = array_slice( $ids, $offset, self::BATCH_SIZE );
        $updated  = 0;
        $skipped  = 0;
        $error    = 0;
        $messages = array();

        foreach ( $batch as $candidate_id ) {
            $result = self::repair_candidate( $candidate_id );
            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Hata: ' . get_the_title( $candidate_id ) . ' — ' . $result->get_error_message();
            } elseif ( true === $result ) {
                $updated++;
                $messages[] = 'Başlık veya kalite kimliği güncellendi: ' . get_the_title( $candidate_id );
            } else {
                $skipped++;
            }
        }

        $next = min( count( $ids ), $offset + count( $batch ) );
        $done = $next >= count( $ids );
        if ( $done ) {
            delete_transient( $key );
        }

        wp_send_json_success( array(
            'next_offset' => $next,
            'done'        => $done,
            'created'     => 0,
            'updated'     => $updated,
            'skipped'     => $skipped,
            'error'       => $error,
            'messages'    => $messages,
        ) );
    }

    private static function eligible_candidate_ids() {
        if ( ! class_exists( 'Sektorel_Event_Source_Role' ) ) {
            return array();
        }

        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_key'       => 'parser_type',
            'meta_value'     => 'html',
        ) );

        $eligible = array();
        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            $status       = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
            $role         = Sektorel_Event_Source_Role::role_for_candidate( $candidate_id );

            if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
                continue;
            }
            if ( ! in_array( $role, array( 'discovery', 'canonical_registry' ), true ) ) {
                continue;
            }
            if ( absint( get_post_meta( $candidate_id, 'matched_event_id', true ) ) || absint( get_post_meta( $candidate_id, 'imported_event_id', true ) ) ) {
                continue;
            }
            if ( self::candidate_page_url( $candidate_id ) ) {
                $eligible[] = $candidate_id;
            }
        }
        return $eligible;
    }

    private static function repair_candidate( $candidate_id ) {
        $page_url = self::candidate_page_url( $candidate_id );
        $host     = self::normalized_host( $page_url );
        $start    = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
        $year     = preg_match( '/^(20\d{2})-/', $start, $match ) ? $match[1] : '';

        if ( ! $page_url || ! $host || ! $year ) {
            return false;
        }

        $response = wp_safe_remote_get( $page_url, array(
            'timeout'             => 12,
            'redirection'         => 3,
            'limit_response_size' => 524288,
            'user-agent'          => 'SektorelAjandaBot/1.0; +' . home_url( '/' ),
            'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error( 'source_http_error', 'Kaynak sayfası HTTP ' . $code . ' döndürdü.' );
        }

        $body  = (string) wp_remote_retrieve_body( $response );
        $title = self::extract_page_identity( $body );
        if ( ! self::trusted_identity( $host, $title, $year ) ) {
            $title = self::verified_body_identity( $host, $body, $year, $start );
            if ( ! $title ) {
                return false;
            }
        }

        $old_title      = trim( (string) get_the_title( $candidate_id ) );
        $same_identity  = '' !== $old_title && self::normalize_title( $old_title ) === self::normalize_title( $title );
        $verified_title = 'verified_source_page_identity' === (string) get_post_meta( $candidate_id, 'candidate_title_source', true );

        if ( $same_identity ) {
            if ( ! $verified_title ) {
                return false;
            }
            return self::refresh_verified_quality_if_stale( $candidate_id );
        }

        if ( '' === $old_title || ! self::current_title_is_repairable( $host, $old_title ) ) {
            return false;
        }

        $source_id   = absint( get_post_meta( $candidate_id, 'source_id', true ) );
        $fingerprint = sha1( $source_id . '|' . self::normalize_title( $title ) . '|' . $start );
        $collision   = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'candidate_fingerprint',
            'meta_value'     => $fingerprint,
            'post__not_in'   => array( $candidate_id ),
            'no_found_rows'  => true,
        ) );
        if ( $collision ) {
            $duplicate_of = absint( $collision[0] );
            if ( ! $duplicate_of || $source_id !== absint( get_post_meta( $duplicate_of, 'source_id', true ) ) ) {
                return false;
            }

            add_post_meta( $candidate_id, 'candidate_title_history', $old_title, false );
            update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
            update_post_meta( $candidate_id, 'candidate_resolution', 'verified_source_title_duplicate' );
            update_post_meta( $candidate_id, 'candidate_duplicate_of', $duplicate_of );
            update_post_meta( $candidate_id, 'candidate_title_source', 'verified_source_page_identity' );
            update_post_meta( $candidate_id, 'candidate_title_repaired_at', current_time( 'mysql' ) );
            update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );
            return true;
        }

        $updated = wp_update_post( array( 'ID' => $candidate_id, 'post_title' => $title ), true );
        if ( is_wp_error( $updated ) ) {
            return $updated;
        }

        add_post_meta( $candidate_id, 'candidate_title_history', $old_title, false );
        update_post_meta( $candidate_id, 'candidate_fingerprint', $fingerprint );
        update_post_meta( $candidate_id, 'candidate_title_source', 'verified_source_page_identity' );
        update_post_meta( $candidate_id, 'candidate_title_repaired_at', current_time( 'mysql' ) );
        delete_post_meta( $candidate_id, 'candidate_match_signature' );
        delete_post_meta( $candidate_id, 'candidate_verified_quality_signature' );
        self::refresh_verified_quality_if_stale( $candidate_id );
        return true;
    }

    private static function refresh_verified_quality_if_stale( $candidate_id ) {
        if ( ! class_exists( 'Sektorel_Event_Candidate_Confidence' ) || ! class_exists( 'Sektorel_Event_HTML_Review_Triage' ) ) {
            return false;
        }

        $signature = sha1(
            self::normalize_title( get_the_title( $candidate_id ) ) . '|' .
            Sektorel_Event_Candidate_Confidence::ENGINE_VERSION . '|' .
            Sektorel_Event_HTML_Review_Triage::ENGINE_VERSION
        );
        if ( $signature === (string) get_post_meta( $candidate_id, 'candidate_verified_quality_signature', true ) ) {
            return false;
        }

        delete_post_meta( $candidate_id, 'candidate_confidence_version' );
        Sektorel_Event_Candidate_Confidence::classify_existing_batch();

        $triage = Sektorel_Event_HTML_Review_Triage::classify( absint( $candidate_id ) );
        if ( ! is_array( $triage ) || empty( $triage['level'] ) ) {
            return false;
        }

        update_post_meta( $candidate_id, 'candidate_triage_level', sanitize_key( (string) $triage['level'] ) );
        update_post_meta( $candidate_id, 'candidate_triage_score', absint( isset( $triage['score'] ) ? $triage['score'] : 0 ) );
        update_post_meta( $candidate_id, 'candidate_triage_reasons', sanitize_text_field( implode( ', ', isset( $triage['reasons'] ) ? (array) $triage['reasons'] : array() ) ) );
        update_post_meta( $candidate_id, 'candidate_triage_version', Sektorel_Event_HTML_Review_Triage::ENGINE_VERSION );
        update_post_meta( $candidate_id, 'candidate_verified_quality_signature', $signature );
        update_post_meta( $candidate_id, 'candidate_verified_quality_refreshed_at', current_time( 'mysql' ) );
        return true;
    }

    private static function candidate_page_url( $candidate_id ) {
        foreach ( array( 'source_url', 'event_url' ) as $meta_key ) {
            $url  = trim( (string) get_post_meta( $candidate_id, $meta_key, true ) );
            $host = self::normalized_host( $url );
            if ( self::supported_host( $host ) ) {
                return esc_url_raw( $url, array( 'http', 'https' ) );
            }
        }
        return '';
    }

    private static function supported_host( $host ) {
        return in_array( $host, array( 'icci.com.tr', 'www.icci.com.tr', 'istanbulfurniturefair.com', 'www.istanbulfurniturefair.com' ), true );
    }

    private static function normalized_host( $url ) {
        return strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
    }

    private static function extract_page_identity( $html ) {
        if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) || '' === trim( $html ) ) {
            return '';
        }

        $dom  = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        $xpath = new DOMXPath( $dom );

        foreach ( array( "//meta[@property='og:title']/@content", "//meta[@name='twitter:title']/@content", '//title' ) as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes ) {
                continue;
            }
            foreach ( $nodes as $node ) {
                $title = self::clean_text( $node->nodeValue );
                if ( strlen( $title ) >= 4 && strlen( $title ) <= 220 ) {
                    return $title;
                }
            }
        }
        return '';
    }

    private static function verified_body_identity( $host, $html, $year, $start ) {
        if ( false === strpos( $host, 'icci.com.tr' ) || '2027' !== (string) $year || 0 !== strpos( (string) $start, '2027-01-20' ) ) {
            return '';
        }

        $text = self::normalize_signal_text( $html );
        if ( ! $text ) {
            return '';
        }

        $has_brand   = false !== strpos( $text, 'icci' );
        $has_edition = false !== strpos( $text, 'icci nin 30 edisyonu' ) || false !== strpos( $text, '30 icci' );
        $has_dates   = false !== strpos( $text, '20 22 ocak 2027' ) || false !== strpos( $text, '20 22 january 2027' );

        if ( ! $has_brand || ! $has_edition || ! $has_dates ) {
            return '';
        }

        return 'ICCI 2027 — 30. Uluslararası Enerji ve Çevre Fuarı ve Konferansı';
    }

    private static function trusted_identity( $host, $title, $year ) {
        if ( '' === $title || false === strpos( $title, $year ) ) {
            return false;
        }
        $normalized = self::normalize_title( $title );
        if ( false !== strpos( $host, 'icci.com.tr' ) ) {
            return (bool) preg_match( '/\bicci\b/i', $normalized );
        }
        if ( false !== strpos( $host, 'istanbulfurniturefair.com' ) ) {
            return (bool) preg_match( '/\b(iiff|international istanbul furniture fair|istanbul furniture fair)\b/i', $normalized );
        }
        return false;
    }

    private static function current_title_is_repairable( $host, $title ) {
        $normalized = self::normalize_title( $title );
        if ( false !== strpos( $host, 'icci.com.tr' ) ) {
            return (bool) preg_match( '/bulusma noktasi|meeting point/i', $normalized );
        }
        if ( false !== strpos( $host, 'istanbulfurniturefair.com' ) ) {
            return (bool) preg_match( '/media partners?|basin sponsor|medya partner/i', $normalized );
        }
        return false;
    }

    private static function clean_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    private static function normalize_title( $title ) {
        return strtolower( remove_accents( self::clean_text( $title ) ) );
    }

    private static function normalize_signal_text( $text ) {
        $text = self::normalize_title( $text );
        $text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_source_title_repair_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
