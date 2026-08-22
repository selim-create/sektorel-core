<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic discovery adapter for the Istanbul Chamber of Commerce (ITO)
 * official fair surface.
 *
 * Initial scope is deliberately bounded to the two 2027 occurrences already
 * present in the review queue (PSI 2027 and JEC World 2027). The adapter reads
 * only the official ITO fair block, verifies exact date/title signals and
 * updates event_candidate records. It never creates or publishes Events.
 */
class Sektorel_Event_Source_ITO_Fairs {

    const NONCE_ACTION = 'sektorel_ito_fairs';
    const SOURCE_ID    = 257;
    const SOURCE_URL   = 'https://www.ito.org.tr/tr';
    const ADAPTER      = 'ito_fairs';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const TIMEOUT      = 15;
    const MAX_BODY     = 2097152;

    public static function init() {
        add_action( 'wp_ajax_sektorel_ito_fairs_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_ito_fairs_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $source = self::source_id();
        if ( is_wp_error( $source ) ) {
            wp_send_json_error( array( 'message' => $source->get_error_message() ) );
        }

        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array( self::SOURCE_ID ),
            self::QUEUE_TTL
        );

        wp_send_json_success( array(
            'token' => $token,
            'total' => 1,
        ) );
    }

    public static function ajax_batch() {
        self::require_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        $key    = self::queue_key( get_current_user_id(), $token );
        $jobs   = get_transient( $key );

        if ( ! $token || ! is_array( $jobs ) ) {
            wp_send_json_error( array( 'message' => 'İTO fuar kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $created = $updated = $unchanged = $skipped = $error = 0;
        $messages = array();

        if ( $offset < count( $jobs ) ) {
            $result = self::scan();
            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Hata: İTO Fuarlar — ' . $result->get_error_message();
            } else {
                foreach ( array( 'created', 'updated', 'unchanged', 'skipped', 'error' ) as $stat ) {
                    if ( isset( $result[ $stat ] ) ) {
                        ${$stat} += absint( $result[ $stat ] );
                    }
                }
                if ( ! empty( $result['messages'] ) ) {
                    $messages = array_merge( $messages, (array) $result['messages'] );
                }
            }
        }

        delete_transient( $key );

        wp_send_json_success( array(
            'next_offset' => count( $jobs ),
            'done'        => true,
            'created'     => $created,
            'updated'     => $updated,
            'unchanged'   => $unchanged,
            'skipped'     => $skipped,
            'error'       => $error,
            'messages'    => array_slice( $messages, 0, 20 ),
        ) );
    }

    private static function scan() {
        $source_id = self::source_id();
        if ( is_wp_error( $source_id ) ) {
            return $source_id;
        }

        $response = wp_safe_remote_get( self::SOURCE_URL, array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_BODY,
            'user-agent'          => 'SektorelAjandaBot/1.0; +' . home_url( '/' ),
            'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5' ),
        ) );
        if ( is_wp_error( $response ) ) {
            self::record_error( $source_id, $response->get_error_message() );
            return $response;
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        if ( $code < 200 || $code >= 400 ) {
            $message = 'İTO resmî sayfası HTTP ' . $code . ' döndürdü.';
            self::record_error( $source_id, $message );
            return new WP_Error( 'ito_http_error', $message );
        }

        $records = self::verified_records( (string) wp_remote_retrieve_body( $response ) );
        if ( is_wp_error( $records ) ) {
            self::record_error( $source_id, $records->get_error_message() );
            return $records;
        }

        $stats = self::empty_stats();
        foreach ( $records as $record ) {
            self::apply_result( $stats, self::upsert_candidate( $source_id, $record ) );
        }

        self::record_success( $source_id, count( $records ) . ' doğrulanmış İTO fuar occurrence işlendi.' );
        return $stats;
    }

    private static function verified_records( $html ) {
        $text = self::clean_text( wp_strip_all_tags( (string) $html ) );
        if ( ! $text || false === stripos( $text, 'Fuarlar' ) ) {
            return new WP_Error( 'ito_fairs_block_missing', 'İTO Fuarlar yüzeyi doğrulanamadı.' );
        }

        $rules = array(
            array(
                'signals' => array(
                    '12 Ocak 2027 - 14 Ocak 2027',
                    'PSI 2027 FUARI',
                    'PROMOSYON ÜRÜNLERİ',
                ),
                'record' => array(
                    'title'             => 'PSI 2027 — Promosyon Ürünleri Fuarı',
                    'start_date'        => '2027-01-12 00:00:00',
                    'end_date'          => '2027-01-14 00:00:00',
                    'location_type'     => 'physical',
                    'venue'             => '',
                    'address'           => 'Köln, Almanya',
                    'organizer'         => '',
                    'registration_link' => '',
                    'event_url'         => self::SOURCE_URL,
                    'source_url'        => self::SOURCE_URL,
                    'description'       => '',
                ),
            ),
            array(
                'signals' => array(
                    '02 Mart 2027 - 04 Mart 2027',
                    'JEC WORLD 2027',
                    'KOMPOZİT',
                ),
                'record' => array(
                    'title'             => 'JEC World 2027 — Kompozit Fuarı',
                    'start_date'        => '2027-03-02 00:00:00',
                    'end_date'          => '2027-03-04 00:00:00',
                    'location_type'     => 'physical',
                    'venue'             => '',
                    'address'           => 'Paris, Fransa',
                    'organizer'         => '',
                    'registration_link' => '',
                    'event_url'         => self::SOURCE_URL,
                    'source_url'        => self::SOURCE_URL,
                    'description'       => '',
                ),
            ),
        );

        $records = array();
        foreach ( $rules as $rule ) {
            $matched = true;
            foreach ( $rule['signals'] as $signal ) {
                if ( false === stripos( $text, $signal ) ) {
                    $matched = false;
                    break;
                }
            }
            if ( $matched ) {
                $records[] = $rule['record'];
            }
        }

        if ( 2 !== count( $records ) ) {
            return new WP_Error( 'ito_verified_occurrences_missing', 'İTO PSI/JEC 2027 resmî occurrence sinyalleri birlikte doğrulanamadı.' );
        }

        return $records;
    }

    private static function upsert_candidate( $source_id, $record ) {
        $title = self::clean_text( $record['title'] );
        $start = trim( (string) $record['start_date'] );
        if ( ! $title || ! preg_match( '/^20\d{2}-\d{2}-\d{2}/', $start ) ) {
            return new WP_Error( 'ito_record_invalid', 'İTO kaydı title/start_date içermiyor.' );
        }

        $candidate_id = self::existing_occurrence_candidate( $source_id, $start, $title );
        $fingerprint  = sha1( absint( $source_id ) . '|' . self::normalize_text( $title ) . '|' . substr( $start, 0, 10 ) );

        if ( ! $candidate_id ) {
            $by_fingerprint = get_posts( array(
                'post_type'      => 'event_candidate',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => 'candidate_fingerprint',
                'meta_value'     => $fingerprint,
                'no_found_rows'  => true,
            ) );
            $candidate_id = ! empty( $by_fingerprint[0] ) ? absint( $by_fingerprint[0] ) : 0;
        }

        $hash     = sha1( wp_json_encode( $record ) );
        $old_hash = $candidate_id ? (string) get_post_meta( $candidate_id, 'source_record_hash', true ) : '';
        if ( $candidate_id && $hash === $old_hash && self::ADAPTER === (string) get_post_meta( $candidate_id, 'source_adapter', true ) ) {
            update_post_meta( $candidate_id, 'source_last_seen_at', current_time( 'mysql' ) );
            return 'unchanged';
        }

        $postarr = array(
            'post_type'    => 'event_candidate',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => '',
        );
        if ( $candidate_id ) {
            $old_title = trim( (string) get_the_title( $candidate_id ) );
            if ( $old_title && $old_title !== $title ) {
                add_post_meta( $candidate_id, 'candidate_title_history', $old_title, false );
            }
            $postarr['ID'] = $candidate_id;
            $saved = wp_update_post( $postarr, true );
            $result = 'updated';
        } else {
            $saved = wp_insert_post( $postarr, true );
            $result = 'created';
        }
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        $candidate_id = absint( $saved );

        $meta = array(
            'candidate_fingerprint' => $fingerprint,
            'source_id'             => absint( $source_id ),
            'source_url'            => esc_url_raw( $record['source_url'] ),
            'event_url'             => esc_url_raw( $record['event_url'] ),
            'start_date'            => $start,
            'end_date'              => trim( (string) $record['end_date'] ),
            'location_type'         => sanitize_key( $record['location_type'] ),
            'venue'                 => self::clean_text( $record['venue'] ),
            'address'               => self::clean_text( $record['address'] ),
            'organizer'             => self::clean_text( $record['organizer'] ),
            'registration_link'     => esc_url_raw( $record['registration_link'] ),
            'parser_type'           => 'adapter',
            'source_adapter'        => self::ADAPTER,
            'source_record_hash'    => $hash,
            'source_last_seen_at'   => current_time( 'mysql' ),
            'candidate_title_source'=> 'ito_official_fairs_surface',
        );
        foreach ( $meta as $key => $value ) {
            update_post_meta( $candidate_id, $key, $value );
        }

        if ( ! absint( get_post_meta( $candidate_id, 'imported_event_id', true ) ) ) {
            update_post_meta( $candidate_id, 'candidate_status', 'new' );
            update_post_meta( $candidate_id, 'candidate_match_reason', 'pending_match' );
            delete_post_meta( $candidate_id, 'matched_event_id' );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );
        }

        return $result;
    }

    private static function existing_occurrence_candidate( $source_id, $start, $target_title ) {
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => 10,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'source_id', 'value' => absint( $source_id ) ),
                array( 'key' => 'start_date', 'value' => substr( $start, 0, 10 ), 'compare' => 'LIKE' ),
            ),
            'no_found_rows'  => true,
        ) );

        if ( ! $ids ) {
            return 0;
        }

        $target = self::normalize_text( $target_title );
        foreach ( $ids as $candidate_id ) {
            $current = self::normalize_text( get_the_title( $candidate_id ) );
            if ( ( false !== strpos( $target, 'psi 2027' ) && false !== strpos( $current, 'psi 2027' ) ) ||
                 ( false !== strpos( $target, 'jec world 2027' ) && false !== strpos( $current, 'jec world 2027' ) ) ) {
                return absint( $candidate_id );
            }
        }

        return 0;
    }

    private static function source_id() {
        if ( 'event_source' !== get_post_type( self::SOURCE_ID ) ) {
            return new WP_Error( 'ito_source_missing', 'İTO source #257 bulunamadı; adapter fail-closed durduruldu.' );
        }

        update_post_meta( self::SOURCE_ID, 'source_url', self::SOURCE_URL );
        update_post_meta( self::SOURCE_ID, 'source_type', 'Etkinlik Takvimi' );
        update_post_meta( self::SOURCE_ID, 'parser_type', 'adapter' );
        update_post_meta( self::SOURCE_ID, 'detected_parser', 'adapter' );
        update_post_meta( self::SOURCE_ID, 'source_status', 'active' );
        update_post_meta( self::SOURCE_ID, 'source_role', 'discovery' );
        update_post_meta( self::SOURCE_ID, 'source_adapter', self::ADAPTER );
        return self::SOURCE_ID;
    }

    private static function record_success( $source_id, $message ) {
        update_post_meta( $source_id, 'check_state', 'ok' );
        update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_result', sanitize_text_field( $message ) );
        update_post_meta( $source_id, 'last_error', '' );
        update_post_meta( $source_id, 'detected_parser', 'adapter' );
    }

    private static function record_error( $source_id, $message ) {
        update_post_meta( $source_id, 'check_state', 'error' );
        update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_error', sanitize_text_field( $message ) );
        update_post_meta( $source_id, 'detected_parser', 'adapter' );
    }

    private static function apply_result( &$stats, $result ) {
        if ( is_wp_error( $result ) ) {
            $stats['error']++;
            $stats['messages'][] = $result->get_error_message();
            return;
        }
        if ( isset( $stats[ $result ] ) ) {
            $stats[ $result ]++;
        } else {
            $stats['skipped']++;
        }
    }

    private static function empty_stats() {
        return array( 'created'=>0, 'updated'=>0, 'unchanged'=>0, 'skipped'=>0, 'error'=>0, 'messages'=>array() );
    }

    private static function normalize_text( $text ) {
        $text = strtolower( remove_accents( self::clean_text( $text ) ) );
        $text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }

    private static function clean_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_ito_fairs_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
