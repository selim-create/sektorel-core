<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Profile_Completion {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_fields' ), 20 );
    }

    public static function register_fields() {
        register_graphql_field( 'SektorelCompanySettings', 'completionMissing', array(
            'type'        => array( 'list_of' => 'String' ),
            'description' => 'Firma profilinin yüzde 100 olması için eksik kalan kriterlerin kullanıcı dostu adları.',
            'resolve'     => function( $source ) {
                $company_id = self::source_database_id( $source );
                return $company_id ? self::missing_labels( $company_id ) : array();
            },
        ) );
    }

    public static function missing_labels( $company_id ) {
        $post = get_post( $company_id );
        $sector_ids = wp_get_object_terms( $company_id, 'sector', array( 'fields' => 'ids' ) );
        $location_ids = wp_get_object_terms( $company_id, 'location', array( 'fields' => 'ids' ) );
        $services = get_post_meta( $company_id, '_sektorel_profile_services', true );
        $products = get_post_meta( $company_id, '_sektorel_profile_products', true );
        $working_hours = (string) get_post_meta( $company_id, '_sektorel_working_hours_text', true );
        $gallery = self::non_empty_lines( (string) get_post_meta( $company_id, 'gallery_urls', true ) );
        $social = array_filter( array(
            get_post_meta( $company_id, 'linkedin_url', true ),
            get_post_meta( $company_id, 'instagram_url', true ),
            get_post_meta( $company_id, 'facebook_url', true ),
            get_post_meta( $company_id, 'twitter_url', true ),
            get_post_meta( $company_id, 'youtube_url', true ),
        ) );

        if ( '' === trim( $working_hours ) ) {
            $legacy_hours = get_post_meta( $company_id, 'working_hours', true );
            $working_hours = is_array( $legacy_hours ) && ! empty( $legacy_hours ) ? 'legacy' : '';
        }

        if ( ! is_array( $services ) ) {
            $services = array();
        }
        if ( ! is_array( $products ) ) {
            $products = array();
        }
        if ( empty( $services ) && empty( $products ) ) {
            $legacy_services = get_post_meta( $company_id, 'company_services', true );
            if ( is_array( $legacy_services ) && ! empty( $legacy_services ) ) {
                $services = $legacy_services;
            }
        }

        $criteria = array(
            'Firma adı'                  => (bool) trim( get_the_title( $company_id ) ),
            'Resmî unvan'                => (bool) trim( (string) get_post_meta( $company_id, 'official_name', true ) ),
            'En az 100 karakter açıklama'=> $post && mb_strlen( trim( wp_strip_all_tags( $post->post_content ) ) ) >= 100,
            'Firma tipi'                 => (bool) trim( (string) get_post_meta( $company_id, 'company_type', true ) ),
            'Sektör'                     => ! is_wp_error( $sector_ids ) && ! empty( $sector_ids ),
            'Şehir veya ilçe'            => ! is_wp_error( $location_ids ) && ! empty( $location_ids ),
            'Açık adres'                 => (bool) trim( (string) get_post_meta( $company_id, 'address', true ) ),
            'Telefon veya e-posta'       => (bool) trim( (string) get_post_meta( $company_id, 'phone', true ) ) || is_email( get_post_meta( $company_id, 'email', true ) ),
            'Web sitesi'                 => (bool) trim( (string) get_post_meta( $company_id, 'website', true ) ),
            'Kare firma logosu'          => (bool) trim( (string) get_post_meta( $company_id, 'logo_image', true ) ),
            'Kapak görseli'              => (bool) trim( (string) get_post_meta( $company_id, 'cover_image', true ) ),
            'En az bir sosyal medya'     => ! empty( $social ),
            'En az bir hizmet veya ürün' => ! empty( $services ) || ! empty( $products ),
            'Çalışma saatleri'           => (bool) trim( $working_hours ),
            'En az bir galeri görseli'   => ! empty( $gallery ),
        );

        return array_keys( array_filter( $criteria, function( $value ) {
            return ! $value;
        } ) );
    }

    private static function non_empty_lines( $value ) {
        return array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $value ) ) ) );
    }

    private static function source_database_id( $source ) {
        if ( is_object( $source ) ) {
            if ( isset( $source->databaseId ) ) {
                return (int) $source->databaseId;
            }
            if ( isset( $source->ID ) ) {
                return (int) $source->ID;
            }
        }
        if ( is_array( $source ) ) {
            return (int) ( $source['databaseId'] ?? $source['ID'] ?? 0 );
        }
        return 0;
    }
}
