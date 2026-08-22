<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Small, evidence-bound lifecycle repairs for event_source records.
 *
 * This class never deletes sources, candidates or Events. Repairs are exact,
 * idempotent and preserve the original source record for provenance.
 */
class Sektorel_Event_Source_Lifecycle_Repair {

    const VERSION        = '1585';
    const OPTION_KEY     = 'sektorel_source_lifecycle_repair_version';
    const IWES_TITLE     = 'IWES (Atık Yönetimi Sempozyumu ve Sergisi)';
    const IWES_HOST      = 'iwes.com.tr';
    const IWES_SIGNATURE = '1585_iwes_domain_reassigned';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'apply_repairs' ), 30 );
    }

    public static function apply_repairs() {
        if ( self::VERSION === (string) get_option( self::OPTION_KEY, '' ) ) {
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );

        $handled = false;
        foreach ( $ids as $source_id ) {
            $source_id = absint( $source_id );
            if ( self::IWES_TITLE !== trim( (string) get_the_title( $source_id ) ) ) {
                continue;
            }

            $url  = trim( (string) get_post_meta( $source_id, 'source_url', true ) );
            $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            $host = preg_replace( '/^www\./i', '', $host );
            if ( self::IWES_HOST !== $host ) {
                continue;
            }

            self::retire_iwes_source( $source_id );
            $handled = true;
            break;
        }

        if ( $handled ) {
            update_option( self::OPTION_KEY, self::VERSION, false );
        }
    }

    private static function retire_iwes_source( $source_id ) {
        if ( self::IWES_SIGNATURE === (string) get_post_meta( $source_id, 'source_retirement_signature', true ) ) {
            return;
        }

        $old_status = sanitize_key( (string) get_post_meta( $source_id, 'source_status', true ) );
        if ( ! get_post_meta( $source_id, 'source_status_before_retirement', true ) ) {
            update_post_meta( $source_id, 'source_status_before_retirement', $old_status ?: 'active' );
        }

        update_post_meta( $source_id, 'source_status', 'paused' );
        update_post_meta( $source_id, 'check_state', 'skipped' );
        update_post_meta( $source_id, 'last_result', 'Kaynak emekliye ayrıldı: iwes.com.tr artık IWES Atık Teknolojileri etkinlik kaynağı değil; alan adı eğitim yazılımı olarak kullanılıyor.' );
        update_post_meta( $source_id, 'last_error', '' );
        update_post_meta( $source_id, 'source_retirement_reason', 'iwes_domain_reassigned_unrelated_education_platform' );
        update_post_meta( $source_id, 'source_retirement_signature', self::IWES_SIGNATURE );
        update_post_meta( $source_id, 'source_retired_at', current_time( 'mysql' ) );
    }
}
