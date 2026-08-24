<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persistent candidate store for automatic company discovery.
 * Candidates never publish companies by themselves.
 */
class Sektorel_Company_Candidates {

    const DB_VERSION = '1';
    const DB_OPTION  = 'sektorel_company_candidates_db_version';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'sektorel_company_candidates';
    }

    public static function maybe_install() {
        if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_key varchar(120) NOT NULL,
            source_record_key varchar(191) NOT NULL DEFAULT '',
            source_url text NULL,
            fingerprint char(64) NOT NULL,
            normalized_name varchar(255) NOT NULL DEFAULT '',
            domain varchar(191) NOT NULL DEFAULT '',
            email varchar(191) NOT NULL DEFAULT '',
            mersis_number varchar(32) NOT NULL DEFAULT '',
            tax_number varchar(32) NOT NULL DEFAULT '',
            trade_registry_number varchar(120) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'new',
            matched_company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            match_method varchar(64) NOT NULL DEFAULT '',
            payload_json longtext NULL,
            evidence_json longtext NULL,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY fingerprint (fingerprint),
            KEY source_key (source_key),
            KEY status (status),
            KEY matched_company_id (matched_company_id),
            KEY domain (domain),
            KEY mersis_number (mersis_number),
            KEY tax_number (tax_number)
        ) {$charset};";

        dbDelta( $sql );
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    /**
     * Store/update one source record and evaluate it against canonical companies.
     *
     * @return array|WP_Error Candidate summary.
     */
    public static function upsert( $source_key, $source_record_key, $payload, $source_url = '' ) {
        global $wpdb;

        $source_key        = sanitize_key( $source_key );
        $source_record_key = sanitize_text_field( (string) $source_record_key );
        $source_url        = esc_url_raw( (string) $source_url );
        $payload           = is_array( $payload ) ? $payload : array();

        if ( ! $source_key ) {
            return new WP_Error( 'invalid_company_source', 'Firma adayı için source_key zorunludur.' );
        }

        self::maybe_install();

        $identity     = self::identity_fields( $payload );
        $fingerprint  = self::fingerprint( $source_key, $source_record_key, $identity );
        $match        = Sektorel_Company_Matcher::match( $payload );
        $status       = in_array( $match['status'], array( 'matched', 'new', 'review' ), true ) ? $match['status'] : 'review';
        $now          = current_time( 'mysql', true );
        $table        = self::table_name();
        $payload_json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $evidence_json = wp_json_encode( $match['evidence'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE fingerprint = %s LIMIT 1",
            $fingerprint
        ) );

        $data = array(
            'source_key'             => $source_key,
            'source_record_key'      => mb_substr( $source_record_key, 0, 191 ),
            'source_url'             => $source_url,
            'normalized_name'        => $identity['normalized_name'],
            'domain'                 => $identity['domain'],
            'email'                  => $identity['email'],
            'mersis_number'          => $identity['mersis_number'],
            'tax_number'             => $identity['tax_number'],
            'trade_registry_number'  => $identity['trade_registry_number'],
            'status'                 => $status,
            'matched_company_id'     => (int) $match['id'],
            'match_method'           => sanitize_key( $match['method'] ),
            'payload_json'           => $payload_json,
            'evidence_json'          => $evidence_json,
            'last_seen_at'           => $now,
            'updated_at'             => $now,
        );

        if ( $existing_id ) {
            $updated = $wpdb->update( $table, $data, array( 'id' => $existing_id ) );
            if ( false === $updated ) {
                return new WP_Error( 'company_candidate_update_failed', $wpdb->last_error ?: 'Firma adayı güncellenemedi.' );
            }
            $candidate_id = $existing_id;
        } else {
            $data['fingerprint']  = $fingerprint;
            $data['first_seen_at'] = $now;
            $data['created_at']    = $now;
            $inserted = $wpdb->insert( $table, $data );
            if ( false === $inserted ) {
                return new WP_Error( 'company_candidate_insert_failed', $wpdb->last_error ?: 'Firma adayı kaydedilemedi.' );
            }
            $candidate_id = (int) $wpdb->insert_id;
        }

        return array(
            'candidate_id'       => $candidate_id,
            'status'             => $status,
            'matched_company_id' => (int) $match['id'],
            'match_method'       => (string) $match['method'],
            'ambiguous'          => (bool) $match['ambiguous'],
            'fingerprint'        => $fingerprint,
        );
    }

    public static function stats() {
        global $wpdb;
        self::maybe_install();
        $table = self::table_name();

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status",
            ARRAY_A
        );

        $stats = array( 'total' => 0, 'new' => 0, 'matched' => 0, 'review' => 0 );
        foreach ( (array) $rows as $row ) {
            $status = sanitize_key( $row['status'] ?? '' );
            $total  = (int) ( $row['total'] ?? 0 );
            $stats['total'] += $total;
            if ( array_key_exists( $status, $stats ) ) {
                $stats[ $status ] = $total;
            }
        }
        return $stats;
    }

    private static function identity_fields( $payload ) {
        $domains = Sektorel_Company_Matcher::domains( $payload );
        $emails  = Sektorel_Company_Matcher::emails( $payload );
        $name    = $payload['company_name'] ?? $payload['title'] ?? $payload['official_name'] ?? '';

        return array(
            'normalized_name'       => mb_substr( Sektorel_Company_Matcher::normalize_text( $name ), 0, 255 ),
            'domain'                => mb_substr( (string) ( $domains[0] ?? '' ), 0, 191 ),
            'email'                 => mb_substr( (string) ( $emails[0] ?? '' ), 0, 191 ),
            'mersis_number'         => mb_substr( preg_replace( '/\D+/', '', (string) ( $payload['mersis_number'] ?? '' ) ), 0, 32 ),
            'tax_number'            => mb_substr( preg_replace( '/\D+/', '', (string) ( $payload['tax_number'] ?? '' ) ), 0, 32 ),
            'trade_registry_number' => mb_substr( sanitize_text_field( (string) ( $payload['trade_registry_number'] ?? '' ) ), 0, 120 ),
        );
    }

    private static function fingerprint( $source_key, $source_record_key, $identity ) {
        if ( $source_record_key ) {
            return hash( 'sha256', $source_key . '|record|' . $source_record_key );
        }

        $stable_identity = array_filter( array(
            $identity['mersis_number'],
            $identity['tax_number'],
            $identity['trade_registry_number'],
            $identity['domain'],
            $identity['email'],
            $identity['normalized_name'],
        ) );

        return hash( 'sha256', $source_key . '|identity|' . implode( '|', $stable_identity ) );
    }
}
