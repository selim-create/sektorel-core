<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reports meaningful HTML candidate changes without changing parser behavior.
 *
 * The legacy HTML parser historically reports every existing fingerprint as
 * "updated", even when the stored event payload is identical. This wrapper
 * keeps the legacy scanner as the source of truth, but snapshots parser-owned
 * candidate fields before/after each source scan and splits those legacy
 * updates into real "updated" and "unchanged" counts.
 */
class Sektorel_Event_HTML_Scan_Observability {

    const ENGINE_VERSION = '1346';

    private static $payload_keys = array(
        'source_url',
        'event_url',
        'start_date',
        'end_date',
        'location_type',
        'venue',
        'address',
        'organizer',
        'registration_link',
        'parser_type',
    );

    public static function init() {
        // All plugin init() calls have completed by admin_init, so the legacy
        // AJAX callback definitely exists and can be replaced deterministically.
        add_action( 'admin_init', array( __CLASS__, 'replace_batch_handler' ), 99 );
    }

    public static function replace_batch_handler() {
        remove_action(
            'wp_ajax_sektorel_html_event_scan_batch',
            array( 'Sektorel_Event_Candidate_HTML', 'ajax_scan_batch' )
        );

        add_action(
            'wp_ajax_sektorel_html_event_scan_batch',
            array( __CLASS__, 'ajax_scan_batch' )
        );
    }

    public static function ajax_scan_batch() {
        check_ajax_referer( 'sektorel_event_candidate_html', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $key    = self::queue_key( get_current_user_id(), $token );
        $ids    = get_transient( $key );

        if ( ! is_array( $ids ) ) {
            wp_send_json_error( array( 'message' => 'HTML tarama kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $created   = 0;
        $updated   = 0;
        $unchanged = 0;
        $skipped   = 0;
        $error     = 0;
        $messages  = array();

        foreach ( array_slice( $ids, $offset, Sektorel_Event_Candidate_HTML::BATCH_SIZE ) as $source_id ) {
            $source_id = absint( $source_id );
            $title     = get_the_title( $source_id );
            $before    = self::candidate_snapshot( $source_id );
            $result    = self::scan_source( $source_id );

            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Hata: ' . $title . ' — ' . $result->get_error_message();
                continue;
            }

            $after          = self::candidate_snapshot( $source_id );
            $legacy_created = isset( $result['created'] ) ? absint( $result['created'] ) : 0;
            $legacy_updated = isset( $result['updated'] ) ? absint( $result['updated'] ) : 0;
            $legacy_skipped = isset( $result['skipped'] ) ? absint( $result['skipped'] ) : 0;

            $meaningful_updates = min(
                $legacy_updated,
                self::count_meaningful_existing_changes( $before, $after )
            );
            $same_payload = max( 0, $legacy_updated - $meaningful_updates );

            $created   += $legacy_created;
            $updated   += $meaningful_updates;
            $unchanged += $same_payload;
            $skipped   += $legacy_skipped;

            $messages[] = sprintf(
                '%1$s: %2$d yeni, %3$d güncel, %4$d değişmedi, %5$d atlandı.',
                $title,
                $legacy_created,
                $meaningful_updates,
                $same_payload,
                $legacy_skipped
            );
        }

        $total = count( $ids );
        $next  = min( $total, $offset + Sektorel_Event_Candidate_HTML::BATCH_SIZE );
        $done  = $next >= $total;

        if ( $done ) {
            delete_transient( $key );
        }

        wp_send_json_success( array(
            'created'        => $created,
            'updated'        => $updated,
            'unchanged'      => $unchanged,
            'skipped'        => $skipped,
            'error'          => $error,
            'messages'       => $messages,
            'next_offset'    => $next,
            'done'           => $done,
            'metrics_version'=> self::ENGINE_VERSION,
        ) );
    }

    private static function scan_source( $source_id ) {
        try {
            $method = new ReflectionMethod( 'Sektorel_Event_Candidate_HTML', 'scan_source' );
            if ( method_exists( $method, 'setAccessible' ) ) {
                $method->setAccessible( true );
            }
            return $method->invoke( null, absint( $source_id ) );
        } catch ( Throwable $e ) {
            return new WP_Error( 'html_scan_observability', $e->getMessage() );
        }
    }

    private static function candidate_snapshot( $source_id ) {
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => 'source_id',
                    'value' => absint( $source_id ),
                ),
                array(
                    'key'   => 'parser_type',
                    'value' => 'html',
                ),
            ),
        ) );

        $snapshot = array();

        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            $row = array(
                'post_title'   => (string) get_post_field( 'post_title', $candidate_id, 'raw' ),
                'post_content' => (string) get_post_field( 'post_content', $candidate_id, 'raw' ),
            );

            foreach ( self::$payload_keys as $meta_key ) {
                $row[ $meta_key ] = (string) get_post_meta( $candidate_id, $meta_key, true );
            }

            $snapshot[ $candidate_id ] = self::signature( $row );
        }

        return $snapshot;
    }

    private static function count_meaningful_existing_changes( $before, $after ) {
        $changed = 0;

        foreach ( $before as $candidate_id => $signature ) {
            if ( ! isset( $after[ $candidate_id ] ) ) {
                continue;
            }

            if ( ! hash_equals( (string) $signature, (string) $after[ $candidate_id ] ) ) {
                $changed++;
            }
        }

        return $changed;
    }

    private static function signature( $payload ) {
        return hash( 'sha256', wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_html_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
