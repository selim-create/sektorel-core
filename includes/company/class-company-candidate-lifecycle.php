<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Fail-closed candidate lifecycle.
 *
 * - matched candidates may fill only empty canonical fields.
 * - new candidates may create published companies after a final deterministic rematch.
 * - review candidates never advance automatically.
 */
class Sektorel_Company_Candidate_Lifecycle {

    public static function apply( $candidate_id ) {
        $candidate = Sektorel_Company_Candidates::get( $candidate_id );
        if ( ! $candidate ) {
            return new WP_Error( 'company_candidate_missing', 'Firma adayı bulunamadı.' );
        }

        if ( 'pending' !== ( $candidate['lifecycle_status'] ?? 'pending' ) ) {
            return new WP_Error( 'company_candidate_already_applied', 'Bu firma adayı daha önce işlendi. Yeni kaynak verisi gelmedikçe tekrar uygulanamaz.' );
        }

        $payload = json_decode( (string) $candidate['payload_json'], true );
        $payload = is_array( $payload ) ? $payload : array();
        $status  = sanitize_key( $candidate['status'] ?? '' );

        if ( 'review' === $status ) {
            return new WP_Error( 'company_candidate_requires_review', 'İnceleme durumundaki aday otomatik ilerletilemez.' );
        }

        if ( 'matched' === $status ) {
            $company_id = (int) ( $candidate['matched_company_id'] ?? 0 );
            if ( ! $company_id || 'company' !== get_post_type( $company_id ) ) {
                return new WP_Error( 'company_candidate_invalid_match', 'Adayın eşleştiği firma artık geçerli değil.' );
            }

            $changed = self::enrich_existing( $company_id, $payload, $candidate );
            $result  = Sektorel_Company_Candidates::mark_applied( $candidate_id, $company_id, $changed ? 'enriched' : 'matched_no_change' );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            return array(
                'action'     => $changed ? 'enriched' : 'matched_no_change',
                'company_id' => $company_id,
            );
        }

        if ( 'new' !== $status ) {
            return new WP_Error( 'company_candidate_invalid_status', 'Aday durumu işlenebilir değil.' );
        }

        // Re-run deterministic matching immediately before creation to prevent races/duplicates.
        $rematch = Sektorel_Company_Matcher::match( $payload );
        if ( 'matched' === ( $rematch['status'] ?? '' ) && ! empty( $rematch['id'] ) ) {
            $updated = Sektorel_Company_Candidates::update_match( $candidate_id, $rematch );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
            return new WP_Error( 'company_candidate_became_match', 'Aday artık mevcut bir firmayla eşleşiyor. Sayfayı yenileyip tekrar kontrol edin.' );
        }
        if ( 'review' === ( $rematch['status'] ?? '' ) ) {
            $updated = Sektorel_Company_Candidates::update_match( $candidate_id, $rematch );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
            return new WP_Error( 'company_candidate_became_review', 'Aday yeni deterministic kanıt nedeniyle incelemeye düştü.' );
        }

        $title = self::first_value( $payload, array( 'company_name', 'official_name', 'title' ) );
        if ( '' === $title ) {
            return new WP_Error( 'company_candidate_missing_name', 'Firma yayınlamak için firma unvanı gerekli.' );
        }

        $post_id = wp_insert_post(
            array(
                'post_type'   => 'company',
                'post_status' => 'publish',
                'post_title'  => sanitize_text_field( $title ),
                'post_author' => get_current_user_id(),
            ),
            true
        );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, Sektorel_Company_Ranking::META_ORIGIN, Sektorel_Company_Ranking::ORIGIN_AUTO_REGISTRY );
        update_post_meta( $post_id, 'official_name', sanitize_text_field( $title ) );
        self::enrich_existing( (int) $post_id, $payload, $candidate );

        $marked = Sektorel_Company_Candidates::mark_applied( $candidate_id, (int) $post_id, 'published_created' );
        if ( is_wp_error( $marked ) ) {
            wp_trash_post( (int) $post_id );
            return $marked;
        }

        return array(
            'action'     => 'published_created',
            'company_id' => (int) $post_id,
        );
    }

    private static function enrich_existing( $company_id, $payload, $candidate ) {
        $changed = false;
        $map = array(
            'email'                 => array( 'email', 'email' ),
            'phone'                 => array( 'phone', 'phone' ),
            'website'               => array( 'website', 'url' ),
            'address'               => array( 'address', 'text' ),
            'mersis_number'         => array( 'mersis_number', 'text' ),
            'tax_number'            => array( 'tax_number', 'text' ),
            'trade_registry_number' => array( 'trade_registry_number', 'text' ),
            'trade_registry_office' => array( 'trade_registry_office', 'text' ),
        );

        foreach ( $map as $payload_key => $definition ) {
            if ( empty( $payload[ $payload_key ] ) ) {
                continue;
            }
            list( $meta_key, $type ) = $definition;
            if ( '' !== (string) get_post_meta( $company_id, $meta_key, true ) ) {
                continue;
            }

            $value = (string) $payload[ $payload_key ];
            if ( 'email' === $type ) {
                $value = sanitize_email( $value );
            } elseif ( 'url' === $type ) {
                $value = esc_url_raw( $value );
            } else {
                $value = sanitize_text_field( $value );
            }
            if ( '' === $value ) {
                continue;
            }
            update_post_meta( $company_id, $meta_key, $value );
            $changed = true;
        }

        $source_key = sanitize_key( $candidate['source_key'] ?? '' );
        $source_url = esc_url_raw( $candidate['source_url'] ?? '' );
        if ( $source_key ) {
            self::append_unique_meta( $company_id, '_sektorel_company_source_keys', $source_key );
        }
        if ( $source_url ) {
            self::append_unique_meta( $company_id, '_sektorel_company_source_urls', $source_url );
        }
        update_post_meta( $company_id, '_sektorel_company_last_candidate_id', (int) $candidate['id'] );
        update_post_meta( $company_id, '_sektorel_company_last_enriched_at', current_time( 'mysql', true ) );

        return $changed;
    }

    private static function append_unique_meta( $company_id, $key, $value ) {
        $existing = get_post_meta( $company_id, $key, true );
        $existing = is_array( $existing ) ? $existing : array_filter( array( $existing ) );
        $existing[] = $value;
        update_post_meta( $company_id, $key, array_values( array_unique( array_filter( array_map( 'strval', $existing ) ) ) ) );
    }

    private static function first_value( $payload, $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $payload[ $key ] ) && '' !== trim( (string) $payload[ $key ] ) ) {
                return trim( (string) $payload[ $key ] );
            }
        }
        return '';
    }
}
