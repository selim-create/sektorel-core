<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reconciles the current PSB Anatolia 2026 occurrence before the existing
 * verified-source repair stage builds its queue.
 *
 * The historical repair rule was intentionally keyed to the old malformed
 * 2026-09-12 parser result. Core 1.58.5 now scans the official Fair Identity
 * surface and correctly discovers 2026-09-09, so that current occurrence must
 * be canonicalized before the legacy rule's start-prefix guard runs.
 */
class Sektorel_Event_Source_PSB_Current_Reconcile {

    const SOURCE_ID     = 340;
    const VERIFY_URL_TR = 'https://psbanatolia.com/hakkimizda-fuar-kunyesi-1.html';
    const VERIFY_URL_EN = 'https://psbanatolia.com/en/about-fair-identity-1.html';

    public static function init() {
        // Runtime orchestration is owned by Stage Registry. This class is
        // invoked directly by the verified-source repair prepare callback.
    }

    public static function reconcile_current_occurrence() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
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
                array( 'key' => 'source_id', 'value' => self::SOURCE_ID, 'type' => 'NUMERIC' ),
                array( 'key' => 'parser_type', 'value' => 'html' ),
            ),
        ) );

        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            $status       = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
            $start        = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
            $end          = trim( (string) get_post_meta( $candidate_id, 'end_date', true ) );

            if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
                continue;
            }
            if ( 0 !== strpos( $start, '2026-09-09' ) || 0 !== strpos( $end, '2026-09-12' ) ) {
                continue;
            }
            if ( absint( get_post_meta( $candidate_id, 'imported_event_id', true ) ) ) {
                continue;
            }

            self::reconcile_candidate( $candidate_id );
        }
    }

    private static function reconcile_candidate( $candidate_id ) {
        $evidence_url = self::verified_evidence_url();
        if ( ! $evidence_url ) {
            return;
        }

        $title     = 'PSB Anatolia 2026 — Uluslararası Peyzaj, Süs Bitkileri, Bahçe Sanatları ve Ekipmanları Fuarı';
        $start     = '2026-09-09T00:00';
        $end       = '2026-09-12T00:00';
        $event_url = 'https://psbanatolia.com/';
        $target    = array(
            'title'      => $title,
            'start_date' => $start,
            'end_date'   => $end,
            'event_url'  => $event_url,
        );
        $signature   = sha1( self::SOURCE_ID . '|' . wp_json_encode( $target ) );
        $fingerprint = sha1( self::SOURCE_ID . '|' . self::normalize( $title ) . '|' . $start );

        $collision = get_posts( array(
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
            if ( self::SOURCE_ID === absint( get_post_meta( $duplicate_of, 'source_id', true ) ) ) {
                update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
                update_post_meta( $candidate_id, 'candidate_resolution', 'psb_current_occurrence_duplicate' );
                update_post_meta( $candidate_id, 'candidate_duplicate_of', $duplicate_of );
                update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
                update_post_meta( $candidate_id, 'candidate_verified_source_repair_signature', $signature );
                update_post_meta( $candidate_id, 'candidate_verified_source_evidence_url', esc_url_raw( $evidence_url ) );
                delete_post_meta( $candidate_id, 'candidate_match_signature' );
            }
            return;
        }

        $old_title = trim( (string) get_the_title( $candidate_id ) );
        if ( $old_title !== $title ) {
            add_post_meta( $candidate_id, 'candidate_title_history', $old_title, false );
            wp_update_post( array( 'ID' => $candidate_id, 'post_title' => $title ) );
        }

        $old_start = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
        if ( $old_start !== $start ) {
            add_post_meta( $candidate_id, 'candidate_start_date_history', $old_start, false );
            update_post_meta( $candidate_id, 'start_date', $start );
        }

        $old_end = trim( (string) get_post_meta( $candidate_id, 'end_date', true ) );
        if ( $old_end !== $end ) {
            add_post_meta( $candidate_id, 'candidate_end_date_history', $old_end, false );
            update_post_meta( $candidate_id, 'end_date', $end );
        }

        $old_url = esc_url_raw( (string) get_post_meta( $candidate_id, 'event_url', true ), array( 'http', 'https' ) );
        if ( $old_url !== $event_url ) {
            add_post_meta( $candidate_id, 'candidate_event_url_history', $old_url, false );
            update_post_meta( $candidate_id, 'event_url', $event_url );
        }

        update_post_meta( $candidate_id, 'candidate_fingerprint', $fingerprint );
        update_post_meta( $candidate_id, 'candidate_title_source', 'verified_official_source_identity' );
        update_post_meta( $candidate_id, 'candidate_verified_source_repair_signature', $signature );
        update_post_meta( $candidate_id, 'candidate_verified_source_evidence_url', esc_url_raw( $evidence_url ) );
        update_post_meta( $candidate_id, 'candidate_verified_source_repaired_at', current_time( 'mysql' ) );
        delete_post_meta( $candidate_id, 'candidate_match_signature' );
        delete_post_meta( $candidate_id, 'candidate_verified_quality_signature' );
    }

    private static function verified_evidence_url() {
        $surfaces = array(
            array(
                'url'     => self::VERIFY_URL_TR,
                'signals' => array(
                    'psb anatolia 2026',
                    'uluslararasi peyzaj sus bitkileri bahce sanatlari ve ekipmanlari fuari',
                    '09 12 eylul 2026',
                ),
            ),
            array(
                'url'     => self::VERIFY_URL_EN,
                'signals' => array(
                    'psb anatolia 2026',
                    'international landscaping ornamental plants garden arts and equipments fair',
                    '09 12 september 2026',
                ),
            ),
        );

        foreach ( $surfaces as $surface ) {
            $response = wp_safe_remote_get( $surface['url'], array(
                'timeout'             => 10,
                'redirection'         => 3,
                'limit_response_size' => 524288,
                'user-agent'          => 'SektorelAjandaBot/1.0; +' . home_url( '/' ),
                'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5' ),
            ) );
            if ( is_wp_error( $response ) ) {
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 400 ) {
                continue;
            }

            $text = self::normalize( (string) wp_remote_retrieve_body( $response ) );
            $valid = true;
            foreach ( $surface['signals'] as $signal ) {
                if ( false === strpos( $text, self::normalize( $signal ) ) ) {
                    $valid = false;
                    break;
                }
            }

            if ( $valid ) {
                return $surface['url'];
            }
        }

        return '';
    }

    private static function normalize( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = strtolower( remove_accents( $text ) );
        $text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
    }
}
