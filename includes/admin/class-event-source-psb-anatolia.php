<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic provider for PSB Anatolia source #340.
 * Reads the official fair-identity page, verifies occurrence identity and
 * reconciles the existing candidate. Never publishes Events.
 */
class Sektorel_Event_Source_PSB_Anatolia {

    const SOURCE_ID  = 340;
    const SOURCE_URL = 'https://psbanatolia.com/';
    const VERIFY_URL = 'https://psbanatolia.com/hakkimizda-fuar-kunyesi-1.html';
    const ADAPTER    = 'psb_anatolia';
    const TIMEOUT    = 15;
    const MAX_BODY   = 1048576;

    public static function scan() {
        $source = get_post( self::SOURCE_ID );
        if ( ! $source || 'event_source' !== $source->post_type ) {
            return new WP_Error( 'psb_source_missing', 'PSB Anatolia event_source #340 bulunamadı.' );
        }

        $response = wp_safe_remote_get( self::VERIFY_URL, array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_BODY,
            'user-agent'          => 'SektorelAjandaBot/1.0; +' . home_url( '/' ),
            'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5' ),
        ) );
        if ( is_wp_error( $response ) ) {
            self::record_error( $response->get_error_message() );
            return $response;
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        if ( $code < 200 || $code >= 400 ) {
            $message = 'PSB Anatolia resmî Fuar Künyesi HTTP ' . $code . ' döndürdü.';
            self::record_error( $message );
            return new WP_Error( 'psb_http_error', $message );
        }

        $record = self::verified_record( (string) wp_remote_retrieve_body( $response ) );
        if ( is_wp_error( $record ) ) {
            self::record_error( $record->get_error_message() );
            return $record;
        }

        // Source ownership moves away from generic HTML once official identity is verified.
        update_post_meta( self::SOURCE_ID, 'parser_type', 'adapter' );
        update_post_meta( self::SOURCE_ID, 'detected_parser', 'adapter' );
        update_post_meta( self::SOURCE_ID, 'source_adapter', self::ADAPTER );
        update_post_meta( self::SOURCE_ID, 'source_role', 'discovery' );

        $stats = self::empty_stats();
        self::apply_result( $stats, self::upsert_candidate( $record ) );
        self::record_success( 'PSB Anatolia resmî Fuar Künyesi occurrence doğrulandı.' );
        return $stats;
    }

    private static function verified_record( $html ) {
        $text = self::clean_text( wp_strip_all_tags( (string) $html ) );
        if ( ! $text ) {
            return new WP_Error( 'psb_identity_empty', 'PSB Anatolia resmî Fuar Künyesi içeriği boş.' );
        }

        $normalized = self::normalize_text( $text );
        foreach ( array( 'psb anatolia', 'uluslararasi peyzaj sus bitkileri bahce sanatlari ve ekipmanlari fuari', 'karma fuarcilik', 'kirkpinar' ) as $signal ) {
            if ( false === strpos( $normalized, $signal ) ) {
                return new WP_Error( 'psb_identity_signal_missing', 'PSB Anatolia resmî Fuar Künyesi kimlik sinyalleri doğrulanamadı.' );
            }
        }

        if ( ! preg_match( '/(\d{1,2})\s*[-–]\s*(\d{1,2})\s+(Ocak|Şubat|Mart|Nisan|Mayıs|Haziran|Temmuz|Ağustos|Eylül|Ekim|Kasım|Aralık)\s+(20\d{2})/iu', $text, $match ) ) {
            return new WP_Error( 'psb_date_missing', 'PSB Anatolia resmî tarih aralığı doğrulanamadı.' );
        }

        $year  = absint( $match[4] );
        $month = self::month_number( $match[3] );
        $start_day = absint( $match[1] );
        $end_day   = absint( $match[2] );
        $current_year = (int) current_time( 'Y' );

        if ( ! $month || $year < $current_year || $year > ( $current_year + 2 ) ) {
            return new WP_Error( 'psb_date_out_of_range', 'PSB Anatolia occurrence yılı güvenli aralık dışında.' );
        }

        $start = sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $start_day );
        $end   = sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $end_day );
        if ( $end < $start ) {
            return new WP_Error( 'psb_invalid_date_range', 'PSB Anatolia resmî tarih aralığı geçersiz.' );
        }

        return array(
            'title'             => 'PSB Anatolia ' . $year . ' — Uluslararası Peyzaj, Süs Bitkileri, Bahçe Sanatları ve Ekipmanları Fuarı',
            'start_date'        => $start,
            'end_date'          => $end,
            'location_type'     => 'physical',
            'venue'             => 'Kırkpınar Sahili Fuar Alanı',
            'address'           => 'Sapanca, Sakarya',
            'organizer'         => 'Karma Fuarcılık Ltd. Şti.',
            'registration_link' => '',
            'event_url'         => self::SOURCE_URL,
            'source_url'        => self::VERIFY_URL,
            'description'       => '',
        );
    }

    private static function upsert_candidate( $record ) {
        $title = self::clean_text( $record['title'] );
        $start = trim( (string) $record['start_date'] );
        $candidate_id = self::existing_occurrence_candidate( $record );
        $fingerprint = sha1( self::SOURCE_ID . '|' . self::normalize_text( $title ) . '|' . substr( $start, 0, 10 ) );

        if ( ! $candidate_id ) {
            $ids = get_posts( array(
                'post_type'      => 'event_candidate',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => 'candidate_fingerprint',
                'meta_value'     => $fingerprint,
                'no_found_rows'  => true,
            ) );
            $candidate_id = ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
        }

        $hash = sha1( wp_json_encode( $record ) );
        if ( $candidate_id && $hash === (string) get_post_meta( $candidate_id, 'source_record_hash', true ) && self::ADAPTER === (string) get_post_meta( $candidate_id, 'source_adapter', true ) ) {
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

        foreach ( array(
            'candidate_fingerprint' => $fingerprint,
            'source_id'             => self::SOURCE_ID,
            'source_url'            => esc_url_raw( $record['source_url'] ),
            'event_url'             => esc_url_raw( $record['event_url'] ),
            'start_date'            => $start,
            'end_date'              => trim( (string) $record['end_date'] ),
            'location_type'         => sanitize_key( $record['location_type'] ),
            'venue'                 => self::clean_text( $record['venue'] ),
            'address'               => self::clean_text( $record['address'] ),
            'organizer'             => self::clean_text( $record['organizer'] ),
            'registration_link'     => '',
            'parser_type'           => 'adapter',
            'source_adapter'        => self::ADAPTER,
            'source_record_hash'    => $hash,
            'source_last_seen_at'   => current_time( 'mysql' ),
            'candidate_title_source'=> 'psb_official_fair_identity',
        ) as $key => $value ) {
            update_post_meta( $candidate_id, $key, $value );
        }

        if ( ! absint( get_post_meta( $candidate_id, 'imported_event_id', true ) ) && ! absint( get_post_meta( $candidate_id, 'matched_event_id', true ) ) ) {
            update_post_meta( $candidate_id, 'candidate_status', 'new' );
            update_post_meta( $candidate_id, 'candidate_match_reason', 'pending_match' );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );
        }

        return $result;
    }

    private static function existing_occurrence_candidate( $record ) {
        $year = substr( (string) $record['start_date'], 0, 4 );
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'meta_key'       => 'source_id',
            'meta_value'     => self::SOURCE_ID,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        $best = 0;
        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            $candidate_title = self::normalize_text( get_the_title( $candidate_id ) );
            $candidate_start = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
            if ( false === strpos( $candidate_title, 'psb anatolia ' . $year ) ) {
                continue;
            }
            if ( 0 !== strpos( $candidate_start, substr( $record['start_date'], 0, 10 ) ) && 0 !== strpos( $candidate_start, substr( $record['end_date'], 0, 10 ) ) ) {
                continue;
            }
            if ( absint( get_post_meta( $candidate_id, 'imported_event_id', true ) ) || absint( get_post_meta( $candidate_id, 'matched_event_id', true ) ) ) {
                return $candidate_id;
            }
            if ( ! $best ) {
                $best = $candidate_id;
            }
        }
        return $best;
    }

    private static function month_number( $month ) {
        $months = array( 'ocak'=>1, 'şubat'=>2, 'mart'=>3, 'nisan'=>4, 'mayıs'=>5, 'haziran'=>6, 'temmuz'=>7, 'ağustos'=>8, 'eylül'=>9, 'ekim'=>10, 'kasım'=>11, 'aralık'=>12 );
        $key = mb_strtolower( trim( (string) $month ), 'UTF-8' );
        return isset( $months[ $key ] ) ? $months[ $key ] : 0;
    }

    private static function apply_result( &$stats, $result ) {
        if ( is_wp_error( $result ) ) {
            $stats['error']++;
            $stats['messages'][] = $result->get_error_message();
        } elseif ( isset( $stats[ $result ] ) ) {
            $stats[ $result ]++;
        } else {
            $stats['skipped']++;
        }
    }

    private static function empty_stats() {
        return array( 'created'=>0, 'updated'=>0, 'unchanged'=>0, 'skipped'=>0, 'error'=>0, 'messages'=>array() );
    }

    private static function record_success( $message ) {
        update_post_meta( self::SOURCE_ID, 'check_state', 'ok' );
        update_post_meta( self::SOURCE_ID, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( self::SOURCE_ID, 'last_result', sanitize_text_field( $message ) );
        update_post_meta( self::SOURCE_ID, 'last_error', '' );
    }

    private static function record_error( $message ) {
        update_post_meta( self::SOURCE_ID, 'check_state', 'error' );
        update_post_meta( self::SOURCE_ID, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( self::SOURCE_ID, 'last_error', sanitize_text_field( $message ) );
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
}
