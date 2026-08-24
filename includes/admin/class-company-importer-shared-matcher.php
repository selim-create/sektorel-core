<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Compatibility bridge that keeps the mature CSV import/enrichment pipeline
 * intact while making Sektorel_Company_Matcher the single identity authority.
 *
 * This intentionally does not change parsing, taxonomy, logo, provenance or
 * fill-empty behavior. Only the deterministic match decision is centralized.
 */
class Sektorel_Company_Importer_Shared_Matcher {

    public static function init() {
        remove_action(
            'wp_ajax_' . Sektorel_Company_Importer::AJAX_ACTION,
            array( 'Sektorel_Company_Importer', 'ajax_import_batch' )
        );
        add_action(
            'wp_ajax_' . Sektorel_Company_Importer::AJAX_ACTION,
            array( __CLASS__, 'ajax_import_batch' )
        );
    }

    public static function ajax_import_batch() {
        check_ajax_referer( Sektorel_Company_Importer::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $mode        = isset( $_POST['mode'] ) && 'import' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ? 'import' : 'analyze';
        $post_status = isset( $_POST['post_status'] ) && 'publish' === sanitize_key( wp_unslash( $_POST['post_status'] ) ) ? 'publish' : 'draft';
        $raw_rows    = isset( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : '';
        $rows        = json_decode( $raw_rows, true );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            wp_send_json_error( array( 'message' => 'İşlenecek kayıt bulunamadı.' ), 400 );
        }

        if ( count( $rows ) > Sektorel_Company_Importer::BATCH_SIZE + 5 ) {
            wp_send_json_error( array( 'message' => 'Batch boyutu izin verilen sınırı aşıyor.' ), 400 );
        }

        $stats    = self::call_importer( 'empty_stats' );
        $messages = array();

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                ++$stats['errors'];
                continue;
            }

            $result = self::process_row( $row, 'analyze' === $mode, $post_status );
            foreach ( $result['stats'] as $key => $value ) {
                if ( isset( $stats[ $key ] ) ) {
                    $stats[ $key ] += (int) $value;
                }
            }

            if ( ! empty( $result['message'] ) && count( $messages ) < 10 ) {
                $messages[] = array(
                    'message' => $result['message'],
                    'type'    => isset( $result['type'] ) ? $result['type'] : 'info',
                );
            }
        }

        wp_send_json_success(
            array(
                'stats'    => $stats,
                'messages' => $messages,
            )
        );
    }

    private static function process_row( $raw_row, $dry_run, $post_status ) {
        $row   = self::call_importer( 'sanitize_row', array( $raw_row ) );
        $stats = self::call_importer( 'empty_stats' );
        $name  = $row['company_name'];

        if ( '' === $name ) {
            ++$stats['errors'];
            return array( 'stats' => $stats, 'message' => 'Firma adı boş olan satır atlandı.', 'type' => 'error' );
        }

        $match = Sektorel_Company_Matcher::match( $row );
        if ( 'review' === ( $match['status'] ?? '' ) || ! empty( $match['ambiguous'] ) ) {
            ++$stats['errors'];
            return array(
                'stats'   => $stats,
                'message' => sprintf(
                    '%s → deterministic kanıtlar güvenli tek eşleşme üretmedi; satır atlandı (%s).',
                    $name,
                    sanitize_key( $match['method'] ?? 'review' )
                ),
                'type'    => 'error',
            );
        }

        $company_id   = 'matched' === ( $match['status'] ?? '' ) ? (int) ( $match['id'] ?? 0 ) : 0;
        $match_method = sanitize_key( $match['method'] ?? ( $company_id ? 'matched' : 'new' ) );
        $has_conflict = false;
        $comparison   = array( 'fillable' => array(), 'conflicts' => array() );

        if ( $company_id ) {
            $comparison   = self::call_importer( 'compare_existing', array( $company_id, $row ) );
            $has_conflict = ! empty( $comparison['conflicts'] );

            if ( $dry_run ) {
                ++$stats['would_match'];
                if ( $has_conflict ) {
                    ++$stats['would_conflict'];
                }
                self::count_sector_status( $company_id, $row, $stats );
                self::call_importer( 'count_logo_status', array( $row, &$stats ) );
                return array(
                    'stats'   => $stats,
                    'message' => sprintf( '%s → mevcut #%d (%s)%s', $name, $company_id, $match_method, $has_conflict ? ' / conflict' : '' ),
                    'type'    => $has_conflict ? 'warn' : 'info',
                );
            }

            ++$stats['matched'];
            $changed = self::call_importer( 'enrich_company', array( $company_id, $row, $comparison ) );
            if ( $changed ) {
                ++$stats['enriched'];
            } else {
                ++$stats['unchanged'];
            }
            if ( $has_conflict ) {
                ++$stats['conflicts'];
                self::call_importer( 'store_conflicts', array( $company_id, $comparison['conflicts'], $row ) );
            }
        } else {
            if ( $dry_run ) {
                ++$stats['would_create'];
                self::count_sector_status( 0, $row, $stats );
                self::call_importer( 'count_logo_status', array( $row, &$stats ) );
                return array(
                    'stats'   => $stats,
                    'message' => $name . ' → yeni firma olarak oluşturulacak',
                    'type'    => 'info',
                );
            }

            $company_id = self::call_importer( 'create_company', array( $row, $post_status ) );
            if ( is_wp_error( $company_id ) ) {
                ++$stats['errors'];
                return array(
                    'stats'   => $stats,
                    'message' => $name . ' oluşturulamadı: ' . $company_id->get_error_message(),
                    'type'    => 'error',
                );
            }
            ++$stats['created'];
        }

        self::call_importer( 'save_provenance', array( $company_id, $row, $match_method ) );

        $sector_result = self::call_importer( 'link_sectors', array( $company_id, $row['sector_slugs'] ) );
        $stats['sectors_linked']  += count( $sector_result['added'] );
        $stats['sectors_missing'] += count( $sector_result['missing'] );
        if ( ! empty( $sector_result['missing'] ) ) {
            update_post_meta( $company_id, '_sektorel_import_missing_sector_slugs', $sector_result['missing'] );
        } else {
            delete_post_meta( $company_id, '_sektorel_import_missing_sector_slugs' );
        }

        $logo_result = self::call_importer( 'link_logo', array( $company_id, $row['logo_file'] ) );
        if ( $logo_result['found'] ) {
            ++$stats['logo_linked'];
        } elseif ( '' !== $row['logo_file'] ) {
            ++$stats['logo_missing'];
        }

        $message_parts = array( sprintf( '%s → #%d', $name, $company_id ) );
        if ( ! empty( $sector_result['added'] ) ) {
            $message_parts[] = count( $sector_result['added'] ) . ' sektör eklendi';
        }
        if ( ! empty( $sector_result['missing'] ) ) {
            $message_parts[] = count( $sector_result['missing'] ) . ' sektör slug bulunamadı';
        }
        if ( $logo_result['found'] ) {
            $message_parts[] = 'logo bağlı';
        }

        return array(
            'stats'   => $stats,
            'message' => implode( ' / ', $message_parts ),
            'type'    => ( $has_conflict || ! empty( $sector_result['missing'] ) ) ? 'warn' : 'success',
        );
    }

    private static function count_sector_status( $company_id, $row, &$stats ) {
        $args = array( $company_id, $row, &$stats, true );
        self::call_importer_ref( 'count_sector_status', $args );
    }

    /**
     * Invoke a private static method on the legacy importer. PHP 8+ Reflection
     * allows invocation without mutating method visibility.
     */
    private static function call_importer( $method, $args = array() ) {
        $reflection = new ReflectionMethod( 'Sektorel_Company_Importer', $method );
        return $reflection->invokeArgs( null, $args );
    }

    private static function call_importer_ref( $method, &$args ) {
        $reflection = new ReflectionMethod( 'Sektorel_Company_Importer', $method );
        return $reflection->invokeArgs( null, $args );
    }
}
