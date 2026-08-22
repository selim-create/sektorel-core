<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Repairs a small allowlist of discovery candidates from verified official pages
 * before deterministic matching. Never creates or publishes Event posts.
 */
class Sektorel_Event_Verified_Source_Repair_Stage {

    const NONCE_ACTION = 'sektorel_verified_source_repair';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 10;

    public static function init() {
        add_action( 'wp_ajax_sektorel_verified_source_repair_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_verified_source_repair_batch', array( __CLASS__, 'ajax_batch' ) );

        if ( class_exists( 'Sektorel_Event_Source_Stage_Registry' ) ) {
            Sektorel_Event_Source_Stage_Registry::register( array(
                'key'              => 'verified_source_repair',
                'order'            => 79,
                'label'            => 'Doğrulanmış Kaynak Alanlarını Düzelt',
                'description'      => 'ZUCHEX, Avrasya Ambalaj, IIFF, IBIA, IFAT Eurasia, PSB Anatolia ve ALUEXPO adaylarında resmî sayfayla doğrulanan başlık, tarih ve canonical URL alanlarını matcher öncesinde idempotent olarak düzeltir.',
                'prepare_action'   => 'sektorel_verified_source_repair_prepare',
                'prepare_callback' => array( __CLASS__, 'ajax_prepare' ),
                'batch_action'     => 'sektorel_verified_source_repair_batch',
                'batch_callback'   => array( __CLASS__, 'ajax_batch' ),
                'nonce_action'     => self::NONCE_ACTION,
                'prepare_payload'  => array(),
            ) );
        }
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
            wp_send_json_error( array( 'message' => 'Doğrulanmış kaynak onarım kuyruğu bulunamadı veya süresi doldu.' ) );
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
                $messages[] = 'Doğrulanmış alanlar güncellendi: ' . get_the_title( $candidate_id );
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
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'parser_type', 'value' => 'html' ),
                array( 'key' => 'source_id', 'value' => array_keys( self::rules() ), 'compare' => 'IN', 'type' => 'NUMERIC' ),
            ),
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
            $eligible[] = $candidate_id;
        }
        return $eligible;
    }

    private static function repair_candidate( $candidate_id ) {
        $source_id = absint( get_post_meta( $candidate_id, 'source_id', true ) );
        $rules     = self::rules();
        if ( ! isset( $rules[ $source_id ] ) ) {
            return false;
        }

        $rule  = $rules[ $source_id ];
        $start = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
        if ( empty( $rule['start_prefix'] ) || 0 !== strpos( $start, $rule['start_prefix'] ) ) {
            return false;
        }

        $response = wp_safe_remote_get( $rule['verify_url'], array(
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
            return new WP_Error( 'verified_source_http_error', 'Resmî kaynak HTTP ' . $code . ' döndürdü.' );
        }

        $text = self::normalize_signal_text( (string) wp_remote_retrieve_body( $response ) );
        if ( ! self::signals_match( $text, $rule['signals'] ) ) {
            return false;
        }

        $target    = $rule['target'];
        $signature = sha1( $source_id . '|' . wp_json_encode( $target ) );
        if ( $signature === (string) get_post_meta( $candidate_id, 'candidate_verified_source_repair_signature', true ) ) {
            return false;
        }

        $old_title = trim( (string) get_the_title( $candidate_id ) );
        $new_title = (string) $target['title'];
        $new_start = isset( $target['start_date'] ) ? (string) $target['start_date'] : $start;
        $new_end   = isset( $target['end_date'] ) ? (string) $target['end_date'] : trim( (string) get_post_meta( $candidate_id, 'end_date', true ) );
        $new_url   = isset( $target['event_url'] ) ? esc_url_raw( (string) $target['event_url'], array( 'http', 'https' ) ) : esc_url_raw( (string) get_post_meta( $candidate_id, 'event_url', true ), array( 'http', 'https' ) );

        $fingerprint = sha1( $source_id . '|' . self::normalize_title( $new_title ) . '|' . $new_start );
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
            if ( $duplicate_of && $source_id === absint( get_post_meta( $duplicate_of, 'source_id', true ) ) ) {
                update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
                update_post_meta( $candidate_id, 'candidate_resolution', 'verified_source_field_duplicate' );
                update_post_meta( $candidate_id, 'candidate_duplicate_of', $duplicate_of );
                update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
                update_post_meta( $candidate_id, 'candidate_verified_source_repair_signature', $signature );
                delete_post_meta( $candidate_id, 'candidate_match_signature' );
                return true;
            }
            return false;
        }

        if ( $old_title !== $new_title ) {
            add_post_meta( $candidate_id, 'candidate_title_history', $old_title, false );
            $updated = wp_update_post( array( 'ID' => $candidate_id, 'post_title' => $new_title ), true );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
        }

        if ( isset( $target['start_date'] ) && $start !== $new_start ) {
            add_post_meta( $candidate_id, 'candidate_start_date_history', $start, false );
            update_post_meta( $candidate_id, 'start_date', $new_start );
        }
        $old_end = trim( (string) get_post_meta( $candidate_id, 'end_date', true ) );
        if ( isset( $target['end_date'] ) && $old_end !== $new_end ) {
            add_post_meta( $candidate_id, 'candidate_end_date_history', $old_end, false );
            update_post_meta( $candidate_id, 'end_date', $new_end );
        }
        $old_url = esc_url_raw( (string) get_post_meta( $candidate_id, 'event_url', true ), array( 'http', 'https' ) );
        if ( isset( $target['event_url'] ) && $old_url !== $new_url ) {
            add_post_meta( $candidate_id, 'candidate_event_url_history', $old_url, false );
            if ( 340 === $source_id && false !== strpos( $old_url, 'ftsonlineregistry.com' ) && ! get_post_meta( $candidate_id, 'registration_link', true ) ) {
                update_post_meta( $candidate_id, 'registration_link', $old_url );
            }
            update_post_meta( $candidate_id, 'event_url', $new_url );
        }

        update_post_meta( $candidate_id, 'candidate_fingerprint', $fingerprint );
        update_post_meta( $candidate_id, 'candidate_title_source', 'verified_official_source_identity' );
        update_post_meta( $candidate_id, 'candidate_verified_source_repair_signature', $signature );
        update_post_meta( $candidate_id, 'candidate_verified_source_repaired_at', current_time( 'mysql' ) );
        delete_post_meta( $candidate_id, 'candidate_match_signature' );
        delete_post_meta( $candidate_id, 'candidate_verified_quality_signature' );
        self::refresh_quality( $candidate_id );
        return true;
    }

    private static function refresh_quality( $candidate_id ) {
        if ( ! class_exists( 'Sektorel_Event_Source_Title_Repair_Stage' ) ) {
            return false;
        }
        $gateway = Closure::bind(
            static function ( $id ) {
                return Sektorel_Event_Source_Title_Repair_Stage::refresh_verified_quality_if_stale( absint( $id ) );
            },
            null,
            'Sektorel_Event_Source_Title_Repair_Stage'
        );
        if ( ! $gateway ) {
            return false;
        }
        try {
            return (bool) $gateway( $candidate_id );
        } catch ( Throwable $e ) {
            return false;
        }
    }

    private static function signals_match( $text, $groups ) {
        if ( ! $text ) {
            return false;
        }
        foreach ( (array) $groups as $alternatives ) {
            $matched = false;
            foreach ( (array) $alternatives as $signal ) {
                if ( false !== strpos( $text, self::normalize_signal_text( $signal ) ) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                return false;
            }
        }
        return true;
    }

    private static function rules() {
        return array(
            181 => array(
                'verify_url'   => 'https://www.zuchex.com/tr/anasayfa.html/',
                'start_prefix' => '2026-09-09',
                'signals'      => array( array( 'zuchex' ), array( '9 12 eylul 2026', '9 12 september 2026' ), array( 'tuyap' ) ),
                'target'       => array(
                    'title'     => 'ZUCHEX 2026 — 36. Uluslararası Ev ve Mutfak Eşyaları Fuarı',
                    'event_url' => 'https://www.zuchex.com/tr/anasayfa.html/',
                ),
            ),
            190 => array(
                'verify_url'   => 'https://packagingfair.com/?lang=tr',
                'start_prefix' => '2026-10-13',
                'signals'      => array( array( 'avrasya ambalaj istanbul fuari', 'avrasya ambalaj fuari' ), array( '13 16 ekim 2026' ), array( 'tuyap' ) ),
                'target'       => array(
                    'title'     => 'Avrasya Ambalaj İstanbul Fuarı 2026',
                    'event_url' => 'https://packagingfair.com/?lang=tr',
                ),
            ),
            197 => array(
                'verify_url'   => 'https://istanbulfurniturefair.com/en',
                'start_prefix' => '2027-01-19',
                'signals'      => array( array( 'international istanbul furniture fair 2027', 'istanbul furniture fair 2027' ), array( '19 23', '19 23 january 2027' ) ),
                'target'       => array(
                    'title'     => 'International Istanbul Furniture Fair 2027 | Europe’s Largest Furniture Fair',
                    'event_url' => 'https://istanbulfurniturefair.com/en',
                ),
            ),
            198 => array(
                'verify_url'   => 'https://ibiaexpo.com/',
                'start_prefix' => '2026-09-16',
                'signals'      => array( array( 'ibia expo 2026' ), array( '5 uluslararasi yatak yan sanayi ve teknolojileri fuari', '5th international mattress supply industry and technologies fair' ), array( '16 19 eylul 2026', 'september 16 19 2026' ) ),
                'target'       => array(
                    'title'     => 'IBIA EXPO 2026 — 5. Uluslararası Yatak Yan Sanayi ve Teknolojileri Fuarı',
                    'event_url' => 'https://ibiaexpo.com/',
                ),
            ),
            210 => array(
                'verify_url'   => 'https://www.ifat-eurasia.com/en/facts-figures/',
                'start_prefix' => '2027-05-05',
                'signals'      => array( array( 'ifat eurasia 2027' ), array( 'international trade fair for environmental technologies' ), array( 'may 05 07 2027' ) ),
                'target'       => array(
                    'title'     => 'IFAT Eurasia 2027 — Uluslararası Çevre Teknolojileri Fuarı',
                    'event_url' => 'https://www.ifat-eurasia.com/',
                ),
            ),
            303 => array(
                'verify_url'   => 'https://aluexpo.com/',
                'start_prefix' => '2027-09-23',
                'signals'      => array( array( 'aluexpo' ), array( '10 uluslararasi aluminyum teknolojileri', '10th international aluminium technologies' ), array( '2027' ) ),
                'target'       => array(
                    'title'     => 'ALUEXPO 2027 — 10. Uluslararası Alüminyum Teknolojileri, Makina ve Ürünleri İhtisas Fuarı',
                    'event_url' => 'https://aluexpo.com/',
                ),
            ),
            340 => array(
                'verify_url'   => 'https://psbanatolia.com/en/about-fair-identity-1.html',
                'start_prefix' => '2026-09-12',
                'signals'      => array( array( 'psb anatolia 2026' ), array( 'international landscaping ornamental plants garden arts and equipments fair' ), array( '09 12 september 2026' ) ),
                'target'       => array(
                    'title'      => 'PSB Anatolia 2026 — Uluslararası Peyzaj, Süs Bitkileri, Bahçe Sanatları ve Ekipmanları Fuarı',
                    'start_date' => '2026-09-09T00:00',
                    'end_date'   => '2026-09-12T00:00',
                    'event_url'  => 'https://psbanatolia.com/',
                ),
            ),
        );
    }

    private static function normalize_signal_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = strtolower( remove_accents( $text ) );
        $text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
    }

    private static function normalize_title( $title ) {
        return self::normalize_signal_text( $title );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_verified_source_repair_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
