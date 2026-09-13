<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persistent pre-AI candidate store for the Content Source Engine.
 *
 * The store is intentionally independent from Event/Company candidate state.
 * It provides deterministic identity and duplicate evidence before any AI call.
 */
class Sektorel_Content_Candidates {

    const DB_VERSION = '1';
    const DB_OPTION  = 'sektorel_content_candidates_db_version';

    const STATUS_NEW       = 'new';
    const STATUS_DUPLICATE = 'duplicate';
    const STATUS_REVIEW    = 'review';
    const STATUS_READY     = 'ready';
    const STATUS_PROCESSED = 'processed';
    const STATUS_ERROR     = 'error';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
    }

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'sektorel_content_candidates';
    }

    public static function maybe_install() {
        if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;

        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_key varchar(120) NOT NULL,
            source_item_key varchar(191) NOT NULL DEFAULT '',
            source_url text NULL,
            canonical_url text NULL,
            normalized_url text NULL,
            fingerprint char(64) NOT NULL,
            canonical_hash char(64) NOT NULL DEFAULT '',
            content_hash char(64) NOT NULL DEFAULT '',
            title text NULL,
            normalized_title varchar(255) NOT NULL DEFAULT '',
            published_at datetime NULL,
            status varchar(24) NOT NULL DEFAULT 'new',
            duplicate_candidate_id bigint(20) unsigned NOT NULL DEFAULT 0,
            duplicate_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            duplicate_method varchar(64) NOT NULL DEFAULT '',
            ai_status varchar(24) NOT NULL DEFAULT 'pending',
            ai_model varchar(120) NOT NULL DEFAULT '',
            ai_input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
            ai_output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
            ai_estimated_cost decimal(12,6) NOT NULL DEFAULT 0,
            draft_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            extracted_text longtext NULL,
            normalized_payload longtext NULL,
            raw_payload longtext NULL,
            evidence_json longtext NULL,
            error_code varchar(120) NOT NULL DEFAULT '',
            error_message text NULL,
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            processed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY fingerprint (fingerprint),
            KEY source_id (source_id),
            KEY source_key (source_key),
            KEY source_item_key (source_item_key),
            KEY canonical_hash (canonical_hash),
            KEY content_hash (content_hash),
            KEY status (status),
            KEY ai_status (ai_status),
            KEY duplicate_candidate_id (duplicate_candidate_id),
            KEY duplicate_post_id (duplicate_post_id),
            KEY draft_post_id (draft_post_id),
            KEY published_at (published_at)
        ) {$charset};";

        dbDelta( $sql );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return false;
        }

        update_option( self::DB_OPTION, self::DB_VERSION, false );
        return true;
    }

    /**
     * Upsert one normalized item before AI processing.
     *
     * Expected item keys: source_url, canonical_url, title, published_at,
     * extracted_text, normalized_payload, raw_payload and evidence.
     */
    public static function upsert( $source_id, $source_key, $source_item_key, $item ) {
        global $wpdb;

        if ( ! self::maybe_install() ) {
            return new WP_Error( 'content_candidate_table_unavailable', 'İçerik adayı tablosu oluşturulamadı.' );
        }

        $source_id        = absint( $source_id );
        $source_key       = sanitize_key( $source_key );
        $source_item_key  = sanitize_text_field( (string) $source_item_key );
        $item             = is_array( $item ) ? $item : array();
        $source_url       = self::normalize_url( $item['source_url'] ?? '' );
        $canonical_url    = self::normalize_url( $item['canonical_url'] ?? $source_url );
        $normalized_url   = $canonical_url ?: $source_url;
        $title            = sanitize_text_field( wp_strip_all_tags( (string) ( $item['title'] ?? '' ) ) );
        $normalized_title = self::normalize_title( $title );
        $published_at     = self::normalize_datetime( $item['published_at'] ?? null );
        $extracted_text   = self::normalize_text_body( $item['extracted_text'] ?? '' );

        if ( ! $source_key ) {
            return new WP_Error( 'invalid_content_source_key', 'İçerik adayı için source_key zorunludur.' );
        }

        if ( ! $source_item_key && ! $normalized_url && ! $title ) {
            return new WP_Error( 'invalid_content_candidate_identity', 'İçerik adayı için item ID, URL veya başlık zorunludur.' );
        }

        $content_hash   = $extracted_text ? hash( 'sha256', self::normalize_for_hash( $extracted_text ) ) : '';
        $canonical_hash = $normalized_url ? hash( 'sha256', $normalized_url ) : '';
        $fingerprint    = self::fingerprint(
            $source_key,
            $source_item_key,
            $normalized_url,
            $content_hash,
            $normalized_title,
            $published_at
        );

        $duplicate = self::find_duplicate(
            $fingerprint,
            $canonical_hash,
            $content_hash,
            $normalized_title,
            $published_at
        );

        $status = $duplicate ? self::STATUS_DUPLICATE : self::STATUS_NEW;
        $now    = current_time( 'mysql', true );
        $table  = self::table_name();

        $normalized_payload = isset( $item['normalized_payload'] )
            ? wp_json_encode( $item['normalized_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            : null;
        $raw_payload = isset( $item['raw_payload'] )
            ? wp_json_encode( $item['raw_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            : null;

        $evidence = isset( $item['evidence'] ) && is_array( $item['evidence'] ) ? $item['evidence'] : array();
        if ( $duplicate ) {
            $evidence['duplicate'] = array(
                'candidate_id' => (int) $duplicate['id'],
                'method'       => (string) $duplicate['method'],
            );
        }

        $data = array(
            'source_id'              => $source_id,
            'source_key'             => $source_key,
            'source_item_key'        => mb_substr( $source_item_key, 0, 191 ),
            'source_url'             => $source_url,
            'canonical_url'          => $canonical_url,
            'normalized_url'         => $normalized_url,
            'canonical_hash'         => $canonical_hash,
            'content_hash'           => $content_hash,
            'title'                  => $title,
            'normalized_title'       => mb_substr( $normalized_title, 0, 255 ),
            'published_at'           => $published_at,
            'status'                 => $status,
            'duplicate_candidate_id' => $duplicate ? (int) $duplicate['id'] : 0,
            'duplicate_method'       => $duplicate ? sanitize_key( $duplicate['method'] ) : '',
            'extracted_text'         => $extracted_text,
            'normalized_payload'     => $normalized_payload,
            'raw_payload'            => $raw_payload,
            'evidence_json'          => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            'last_seen_at'           => $now,
            'updated_at'             => $now,
        );

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE fingerprint = %s LIMIT 1",
            $fingerprint
        ) );

        if ( $existing_id ) {
            $existing = self::get( $existing_id );

            // Do not reopen already processed candidates unless source content materially changed.
            if ( $existing ) {
                $content_changed = (string) ( $existing['content_hash'] ?? '' ) !== $content_hash;
                $url_changed     = (string) ( $existing['canonical_hash'] ?? '' ) !== $canonical_hash;

                if ( ! $content_changed && ! $url_changed ) {
                    unset( $data['status'], $data['duplicate_candidate_id'], $data['duplicate_method'] );

                    // A routine rescan must never erase lifecycle/enrichment state
                    // already accumulated by triage, detail extraction or AI.
                    // Raw payload and source-facing fields may refresh, but these
                    // stateful fields stay authoritative until material source
                    // identity/content changes are detected.
                    foreach ( array( 'extracted_text', 'normalized_payload', 'evidence_json' ) as $field ) {
                        if ( isset( $existing[ $field ] ) && '' !== trim( (string) $existing[ $field ] ) ) {
                            $data[ $field ] = $existing[ $field ];
                        }
                    }
                } elseif ( self::STATUS_PROCESSED === (string) ( $existing['status'] ?? '' ) ) {
                    $data['status']        = $status;
                    $data['ai_status']     = 'pending';
                    $data['processed_at']  = null;
                    $data['draft_post_id'] = 0;
                }
            }

            $updated = $wpdb->update( $table, $data, array( 'id' => $existing_id ) );
            if ( false === $updated ) {
                return new WP_Error( 'content_candidate_update_failed', $wpdb->last_error ?: 'İçerik adayı güncellenemedi.' );
            }

            $candidate_id = $existing_id;
        } else {
            $data['fingerprint']   = $fingerprint;
            $data['ai_status']     = 'pending';
            $data['first_seen_at'] = $now;
            $data['created_at']    = $now;

            $inserted = $wpdb->insert( $table, $data );
            if ( false === $inserted ) {
                return new WP_Error( 'content_candidate_insert_failed', $wpdb->last_error ?: 'İçerik adayı kaydedilemedi.' );
            }

            $candidate_id = (int) $wpdb->insert_id;
        }

        return array(
            'candidate_id'           => $candidate_id,
            'status'                 => $status,
            'fingerprint'            => $fingerprint,
            'duplicate_candidate_id' => $duplicate ? (int) $duplicate['id'] : 0,
            'duplicate_method'       => $duplicate ? (string) $duplicate['method'] : '',
        );
    }

    public static function get( $candidate_id ) {
        global $wpdb;

        if ( ! self::maybe_install() ) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1',
                absint( $candidate_id )
            ),
            ARRAY_A
        );
    }

    public static function mark_error( $candidate_id, $error_code, $error_message ) {
        global $wpdb;

        $updated = $wpdb->update(
            self::table_name(),
            array(
                'status'        => self::STATUS_ERROR,
                'error_code'    => sanitize_key( $error_code ),
                'error_message' => sanitize_textarea_field( (string) $error_message ),
                'updated_at'    => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $candidate_id ) )
        );

        return false === $updated
            ? new WP_Error( 'content_candidate_error_update_failed', $wpdb->last_error ?: 'İçerik adayı hata durumu güncellenemedi.' )
            : true;
    }

    public static function stats() {
        global $wpdb;

        $stats = array(
            'total'     => 0,
            'new'       => 0,
            'duplicate' => 0,
            'review'    => 0,
            'ready'     => 0,
            'processed' => 0,
            'error'     => 0,
        );

        if ( ! self::maybe_install() ) {
            return $stats;
        }

        $rows = $wpdb->get_results(
            'SELECT status, COUNT(*) AS total FROM ' . self::table_name() . ' GROUP BY status',
            ARRAY_A
        );

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

    public static function recent( $limit = 20 ) {
        global $wpdb;

        if ( ! self::maybe_install() ) {
            return array();
        }

        $limit = max( 1, min( 100, absint( $limit ) ) );

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT %d',
                $limit
            ),
            ARRAY_A
        );
    }

    private static function find_duplicate( $fingerprint, $canonical_hash, $content_hash, $normalized_title, $published_at ) {
        global $wpdb;
        $table = self::table_name();

        if ( $canonical_hash ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE fingerprint <> %s AND canonical_hash = %s ORDER BY id ASC LIMIT 1",
                    $fingerprint,
                    $canonical_hash
                ),
                ARRAY_A
            );
            if ( $row ) {
                return array( 'id' => (int) $row['id'], 'method' => 'canonical_url' );
            }
        }

        if ( $content_hash ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE fingerprint <> %s AND content_hash = %s ORDER BY id ASC LIMIT 1",
                    $fingerprint,
                    $content_hash
                ),
                ARRAY_A
            );
            if ( $row ) {
                return array( 'id' => (int) $row['id'], 'method' => 'content_hash' );
            }
        }

        if ( $normalized_title && $published_at ) {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE fingerprint <> %s AND normalized_title = %s AND published_at = %s ORDER BY id ASC LIMIT 1",
                    $fingerprint,
                    $normalized_title,
                    $published_at
                ),
                ARRAY_A
            );
            if ( $row ) {
                return array( 'id' => (int) $row['id'], 'method' => 'title_date' );
            }
        }

        return null;
    }

    private static function fingerprint( $source_key, $source_item_key, $normalized_url, $content_hash, $normalized_title, $published_at ) {
        if ( $source_item_key ) {
            return hash( 'sha256', $source_key . '|item|' . $source_item_key );
        }

        if ( $normalized_url ) {
            return hash( 'sha256', $source_key . '|url|' . $normalized_url );
        }

        if ( $content_hash ) {
            return hash( 'sha256', $source_key . '|content|' . $content_hash );
        }

        return hash( 'sha256', $source_key . '|title-date|' . $normalized_title . '|' . (string) $published_at );
    }

    private static function normalize_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return '';
        }

        $host = strtolower( (string) $parts['host'] );
        $path = isset( $parts['path'] ) ? preg_replace( '#/+#', '/', (string) $parts['path'] ) : '/';
        $path = '/' . ltrim( (string) $path, '/' );
        if ( '/' !== $path ) {
            $path = untrailingslashit( $path );
        }

        $query = array();
        if ( ! empty( $parts['query'] ) ) {
            parse_str( (string) $parts['query'], $query );
            foreach ( array_keys( $query ) as $key ) {
                if ( preg_match( '/^(utm_|fbclid$|gclid$|mc_cid$|mc_eid$)/i', (string) $key ) ) {
                    unset( $query[ $key ] );
                }
            }
            ksort( $query );
        }

        $normalized = $scheme . '://' . $host . $path;
        if ( $query ) {
            $normalized .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }

        return esc_url_raw( $normalized );
    }

    private static function normalize_title( $title ) {
        $title = remove_accents( wp_strip_all_tags( (string) $title ) );
        $title = mb_strtolower( $title, 'UTF-8' );
        $title = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $title );
        return trim( preg_replace( '/\s+/u', ' ', (string) $title ) );
    }

    private static function normalize_text_body( $text ) {
        $text = wp_strip_all_tags( (string) $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    private static function normalize_for_hash( $text ) {
        $text = remove_accents( (string) $text );
        $text = mb_strtolower( $text, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    private static function normalize_datetime( $value ) {
        if ( empty( $value ) ) {
            return null;
        }

        if ( $value instanceof DateTimeInterface ) {
            return gmdate( 'Y-m-d H:i:s', $value->getTimestamp() );
        }

        $timestamp = strtotime( (string) $value );
        if ( false === $timestamp ) {
            return null;
        }

        return gmdate( 'Y-m-d H:i:s', $timestamp );
    }
}
