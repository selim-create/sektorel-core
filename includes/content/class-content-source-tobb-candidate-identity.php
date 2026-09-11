<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Repairs TOBB content-candidate detail URLs from deterministic RSS identity.
 *
 * Historical TOBB candidate rows may carry a non-canonical path even though
 * the stable `rid` identity is still present in source_url, canonical_url,
 * source_item_key or raw/normalized payload. Rebuild the canonical detail URL
 * before the source-specific publication-date resolver runs.
 */
class Sektorel_Content_Source_TOBB_Candidate_Identity {

    const VERSION = '1';

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        // Run before the TOBB detail-date resolver (priority 1).
        add_action( 'wp_ajax_sektorel_content_triage_batch', array( __CLASS__, 'repair_next_batch' ), 0 );
    }

    public static function repair_next_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, Sektorel_Content_Candidate_Triage::NONCE_ACTION ) ) {
            return;
        }

        global $wpdb;
        $table = Sektorel_Content_Candidates::table_name();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, source_key, source_item_key, source_url, canonical_url, normalized_url,
                        canonical_hash, published_at, raw_payload, normalized_payload, evidence_json
                 FROM {$table}
                 WHERE status IN ( %s, %s )
                   AND ( published_at IS NULL OR published_at = '' )
                   AND source_key IN ( 'tobb_news', 'tobb_announcements' )
                 ORDER BY id ASC
                 LIMIT %d",
                Sektorel_Content_Candidates::STATUS_NEW,
                Sektorel_Content_Candidates::STATUS_REVIEW,
                Sektorel_Content_Candidate_Triage::BATCH_SIZE
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            self::repair_candidate( $row );
        }
    }

    private static function repair_candidate( $row ) {
        global $wpdb;

        $candidate_id = absint( $row['id'] ?? 0 );
        $source_key   = sanitize_key( $row['source_key'] ?? '' );
        if ( ! $candidate_id || ! in_array( $source_key, array( 'tobb_news', 'tobb_announcements' ), true ) ) {
            return false;
        }

        $identity = self::find_identity( $row );
        if ( ! $identity['rid'] ) {
            self::record_evidence( $row, array(
                'status'     => 'failed',
                'error_code' => 'rid_not_found',
                'checked_at' => gmdate( 'c' ),
                'version'    => self::VERSION,
            ) );
            return false;
        }

        $list = 'tobb_news' === $source_key ? 'Haberler' : 'DuyurularListesi';
        $canonical = add_query_arg(
            array(
                'lst' => $list,
                'rid' => $identity['rid'],
            ),
            'https://www.tobb.org.tr/Sayfalar/Detay.php'
        );
        $canonical = esc_url_raw( $canonical, array( 'https' ) );

        if ( ! $canonical ) {
            return false;
        }

        $normalized = self::decode_json_array( $row['normalized_payload'] ?? '' );
        $normalized['url'] = $canonical;

        $evidence = self::decode_json_array( $row['evidence_json'] ?? '' );
        $evidence['tobb_candidate_identity'] = array(
            'status'          => 'resolved',
            'rid'             => (int) $identity['rid'],
            'identity_source' => $identity['source'],
            'canonical_url'   => $canonical,
            'resolved_at'     => gmdate( 'c' ),
            'version'         => self::VERSION,
        );

        $updated = $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'source_url'         => $canonical,
                'canonical_url'      => $canonical,
                'normalized_url'     => $canonical,
                'canonical_hash'     => hash( 'sha256', $canonical ),
                'normalized_payload' => wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'evidence_json'      => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'         => current_time( 'mysql', true ),
            ),
            array( 'id' => $candidate_id ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        return false !== $updated;
    }

    private static function find_identity( $row ) {
        $candidates = array(
            'canonical_url'   => (string) ( $row['canonical_url'] ?? '' ),
            'source_url'      => (string) ( $row['source_url'] ?? '' ),
            'normalized_url'  => (string) ( $row['normalized_url'] ?? '' ),
            'source_item_key' => (string) ( $row['source_item_key'] ?? '' ),
        );

        $raw = self::decode_json_array( $row['raw_payload'] ?? '' );
        foreach ( array( 'link', 'guid', 'id', 'url' ) as $key ) {
            if ( ! empty( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
                $candidates[ 'raw_payload.' . $key ] = (string) $raw[ $key ];
            }
        }

        $normalized = self::decode_json_array( $row['normalized_payload'] ?? '' );
        foreach ( array( 'url', 'source_url', 'canonical_url', 'guid' ) as $key ) {
            if ( ! empty( $normalized[ $key ] ) && is_scalar( $normalized[ $key ] ) ) {
                $candidates[ 'normalized_payload.' . $key ] = (string) $normalized[ $key ];
            }
        }

        foreach ( $candidates as $source => $value ) {
            $rid = self::extract_rid( $value );
            if ( $rid ) {
                return array( 'rid' => $rid, 'source' => $source );
            }
        }

        return array( 'rid' => 0, 'source' => '' );
    }

    private static function extract_rid( $value ) {
        $value = html_entity_decode( trim( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( '' === $value ) {
            return 0;
        }

        if ( preg_match( '/(?:[?&]|\b)rid\s*=\s*(\d+)/i', $value, $match ) ) {
            return absint( $match[1] );
        }

        // Some feeds may expose only a compact numeric GUID. Accept it only
        // when it is sufficiently long to look like TOBB's numeric record id.
        if ( preg_match( '/^\d{3,12}$/', $value ) ) {
            return absint( $value );
        }

        return 0;
    }

    private static function record_evidence( $row, $payload ) {
        global $wpdb;

        $candidate_id = absint( $row['id'] ?? 0 );
        if ( ! $candidate_id ) {
            return;
        }

        $evidence = self::decode_json_array( $row['evidence_json'] ?? '' );
        $evidence['tobb_candidate_identity'] = $payload;

        $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'evidence_json' => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'    => current_time( 'mysql', true ),
            ),
            array( 'id' => $candidate_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    private static function decode_json_array( $value ) {
        if ( ! $value ) {
            return array();
        }

        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
