<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Profile {

    const MAX_LIST_ITEMS = 30;
    const MAX_GALLERY_ITEMS = 12;

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelCompanyProfile', array(
            'description' => 'Firma profilinin medya, sosyal, hizmet, ürün ve çalışma saati alanları.',
            'fields'      => array(
                'logoImage'         => array( 'type' => 'String' ),
                'coverImage'        => array( 'type' => 'String' ),
                'linkedinUrl'       => array( 'type' => 'String' ),
                'instagramUrl'      => array( 'type' => 'String' ),
                'facebookUrl'       => array( 'type' => 'String' ),
                'twitterUrl'        => array( 'type' => 'String' ),
                'youtubeUrl'        => array( 'type' => 'String' ),
                'services'          => array( 'type' => array( 'list_of' => 'String' ) ),
                'products'          => array( 'type' => array( 'list_of' => 'String' ) ),
                'workingHoursText'  => array( 'type' => 'String' ),
                'galleryUrls'       => array( 'type' => array( 'list_of' => 'String' ) ),
                'completionPercent' => array( 'type' => 'Int' ),
            ),
        ) );

        register_graphql_field( 'Company', 'sektorelProfile', array(
            'type'        => 'SektorelCompanyProfile',
            'description' => 'Firma profilinin zengin public alanlarını döndürür.',
            'resolve'     => function( $source ) {
                $company_id = self::source_database_id( $source );
                return $company_id ? self::format_profile( $company_id ) : null;
            },
        ) );
    }

    public static function settings_fields() {
        return array(
            'logoImage'         => array( 'type' => 'String' ),
            'coverImage'        => array( 'type' => 'String' ),
            'linkedinUrl'       => array( 'type' => 'String' ),
            'instagramUrl'      => array( 'type' => 'String' ),
            'facebookUrl'       => array( 'type' => 'String' ),
            'twitterUrl'        => array( 'type' => 'String' ),
            'youtubeUrl'        => array( 'type' => 'String' ),
            'servicesText'      => array( 'type' => 'String' ),
            'productsText'      => array( 'type' => 'String' ),
            'workingHoursText'  => array( 'type' => 'String' ),
            'galleryUrlsText'   => array( 'type' => 'String' ),
            'completionPercent' => array( 'type' => 'Int' ),
        );
    }

    public static function mutation_fields() {
        $fields = self::settings_fields();
        unset( $fields['completionPercent'] );
        return $fields;
    }

    public static function settings_payload( $company_id ) {
        $profile = self::format_profile( $company_id );

        return array(
            'logoImage'         => $profile['logoImage'],
            'coverImage'        => $profile['coverImage'],
            'linkedinUrl'       => $profile['linkedinUrl'],
            'instagramUrl'      => $profile['instagramUrl'],
            'facebookUrl'       => $profile['facebookUrl'],
            'twitterUrl'        => $profile['twitterUrl'],
            'youtubeUrl'        => $profile['youtubeUrl'],
            'servicesText'      => implode( "\n", $profile['services'] ),
            'productsText'      => implode( "\n", $profile['products'] ),
            'workingHoursText'  => $profile['workingHoursText'],
            'galleryUrlsText'   => implode( "\n", $profile['galleryUrls'] ),
            'completionPercent' => $profile['completionPercent'],
        );
    }

    public static function save_profile( $company_id, $input ) {
        $url_fields = array(
            'logo_image'    => 'logoImage',
            'cover_image'   => 'coverImage',
            'linkedin_url'  => 'linkedinUrl',
            'instagram_url' => 'instagramUrl',
            'facebook_url'  => 'facebookUrl',
            'twitter_url'   => 'twitterUrl',
            'youtube_url'   => 'youtubeUrl',
        );

        foreach ( $url_fields as $meta_key => $input_key ) {
            if ( array_key_exists( $input_key, $input ) ) {
                update_post_meta( $company_id, $meta_key, self::sanitize_url( $input[ $input_key ] ) );
            }
        }

        if ( array_key_exists( 'galleryUrlsText', $input ) ) {
            $gallery = self::sanitize_url_list( $input['galleryUrlsText'], self::MAX_GALLERY_ITEMS );
            update_post_meta( $company_id, 'gallery_urls', implode( "\n", $gallery ) );
        }

        if ( array_key_exists( 'servicesText', $input ) || array_key_exists( 'productsText', $input ) ) {
            $services = array_key_exists( 'servicesText', $input )
                ? self::sanitize_text_list( $input['servicesText'], self::MAX_LIST_ITEMS )
                : self::stored_service_names( $company_id, 'service' );
            $products = array_key_exists( 'productsText', $input )
                ? self::sanitize_text_list( $input['productsText'], self::MAX_LIST_ITEMS )
                : self::stored_service_names( $company_id, 'product' );

            update_post_meta( $company_id, '_sektorel_profile_services', $services );
            update_post_meta( $company_id, '_sektorel_profile_products', $products );

            $legacy_services = array();
            foreach ( $services as $title ) {
                $legacy_services[] = array(
                    'icon'  => 'BriefcaseBusiness',
                    'title' => $title,
                    'desc'  => 'Hizmet',
                    'kind'  => 'service',
                );
            }
            foreach ( $products as $title ) {
                $legacy_services[] = array(
                    'icon'  => 'Package',
                    'title' => $title,
                    'desc'  => 'Ürün',
                    'kind'  => 'product',
                );
            }
            update_post_meta( $company_id, 'company_services', $legacy_services );
        }

        if ( array_key_exists( 'workingHoursText', $input ) ) {
            $working_hours_text = mb_substr( sanitize_textarea_field( $input['workingHoursText'] ), 0, 1200 );
            update_post_meta( $company_id, '_sektorel_working_hours_text', $working_hours_text );
            update_post_meta( $company_id, 'working_hours', self::working_hours_rows( $working_hours_text ) );
        }
    }

    public static function format_profile( $company_id ) {
        $services = self::stored_service_names( $company_id, 'service' );
        $products = self::stored_service_names( $company_id, 'product' );
        $working_hours_text = (string) get_post_meta( $company_id, '_sektorel_working_hours_text', true );

        if ( '' === $working_hours_text ) {
            $working_hours_text = self::legacy_working_hours_text( $company_id );
        }

        $profile = array(
            'logoImage'        => (string) get_post_meta( $company_id, 'logo_image', true ),
            'coverImage'       => (string) get_post_meta( $company_id, 'cover_image', true ),
            'linkedinUrl'      => (string) get_post_meta( $company_id, 'linkedin_url', true ),
            'instagramUrl'     => (string) get_post_meta( $company_id, 'instagram_url', true ),
            'facebookUrl'      => (string) get_post_meta( $company_id, 'facebook_url', true ),
            'twitterUrl'       => (string) get_post_meta( $company_id, 'twitter_url', true ),
            'youtubeUrl'       => (string) get_post_meta( $company_id, 'youtube_url', true ),
            'services'         => $services,
            'products'         => $products,
            'workingHoursText' => $working_hours_text,
            'galleryUrls'      => self::sanitize_url_list(
                (string) get_post_meta( $company_id, 'gallery_urls', true ),
                self::MAX_GALLERY_ITEMS
            ),
        );

        $profile['completionPercent'] = self::completion_percent( $company_id, $profile );
        return $profile;
    }

    private static function completion_percent( $company_id, $profile ) {
        $post = get_post( $company_id );
        $sector = wp_get_object_terms( $company_id, 'sector', array( 'fields' => 'ids' ) );
        $location = wp_get_object_terms( $company_id, 'location', array( 'fields' => 'ids' ) );
        $social = array_filter( array(
            $profile['linkedinUrl'],
            $profile['instagramUrl'],
            $profile['facebookUrl'],
            $profile['twitterUrl'],
            $profile['youtubeUrl'],
        ) );

        $checks = array(
            (bool) trim( get_the_title( $company_id ) ),
            (bool) trim( (string) get_post_meta( $company_id, 'official_name', true ) ),
            $post && mb_strlen( trim( wp_strip_all_tags( $post->post_content ) ) ) >= 100,
            (bool) trim( (string) get_post_meta( $company_id, 'company_type', true ) ),
            ! is_wp_error( $sector ) && ! empty( $sector ),
            ! is_wp_error( $location ) && ! empty( $location ),
            (bool) trim( (string) get_post_meta( $company_id, 'address', true ) ),
            (bool) trim( (string) get_post_meta( $company_id, 'phone', true ) ) || is_email( get_post_meta( $company_id, 'email', true ) ),
            (bool) trim( (string) get_post_meta( $company_id, 'website', true ) ),
            (bool) $profile['logoImage'],
            (bool) $profile['coverImage'],
            ! empty( $social ),
            ! empty( $profile['services'] ) || ! empty( $profile['products'] ),
            (bool) trim( $profile['workingHoursText'] ),
            ! empty( $profile['galleryUrls'] ),
        );

        return (int) round( ( count( array_filter( $checks ) ) / count( $checks ) ) * 100 );
    }

    private static function stored_service_names( $company_id, $kind ) {
        $meta_key = 'product' === $kind ? '_sektorel_profile_products' : '_sektorel_profile_services';
        $stored = get_post_meta( $company_id, $meta_key, true );
        if ( is_array( $stored ) ) {
            return self::sanitize_text_list( implode( "\n", $stored ), self::MAX_LIST_ITEMS );
        }

        $legacy = get_post_meta( $company_id, 'company_services', true );
        if ( ! is_array( $legacy ) ) {
            return array();
        }

        $items = array();
        foreach ( $legacy as $item ) {
            if ( ! is_array( $item ) || empty( $item['title'] ) ) {
                continue;
            }
            $legacy_kind = sanitize_key( $item['kind'] ?? 'service' );
            if ( $legacy_kind === $kind || ( 'service' === $kind && ! isset( $item['kind'] ) ) ) {
                $items[] = sanitize_text_field( $item['title'] );
            }
        }

        return array_values( array_unique( array_filter( $items ) ) );
    }

    private static function working_hours_rows( $value ) {
        $rows = array();
        foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
            $line = trim( sanitize_text_field( $line ) );
            if ( '' === $line ) {
                continue;
            }
            $parts = preg_split( '/\s*[:|]\s*/', $line, 2 );
            $rows[] = array(
                'days' => trim( $parts[0] ?? $line ),
                'time' => trim( $parts[1] ?? '' ),
            );
            if ( count( $rows ) >= 14 ) {
                break;
            }
        }
        return $rows;
    }

    private static function legacy_working_hours_text( $company_id ) {
        $rows = get_post_meta( $company_id, 'working_hours', true );
        if ( ! is_array( $rows ) ) {
            return '';
        }
        $lines = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $days = sanitize_text_field( $row['days'] ?? '' );
            $time = sanitize_text_field( $row['time'] ?? '' );
            if ( $days || $time ) {
                $lines[] = trim( $days . ( $time ? ': ' . $time : '' ) );
            }
        }
        return implode( "\n", $lines );
    }

    private static function sanitize_text_list( $value, $limit ) {
        $items = array();
        foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
            $line = mb_substr( trim( sanitize_text_field( $line ) ), 0, 140 );
            if ( '' !== $line ) {
                $items[] = $line;
            }
            if ( count( $items ) >= $limit ) {
                break;
            }
        }
        return array_values( array_unique( $items ) );
    }

    private static function sanitize_url_list( $value, $limit ) {
        $items = array();
        foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) as $line ) {
            $url = self::sanitize_url( $line );
            if ( $url ) {
                $items[] = $url;
            }
            if ( count( $items ) >= $limit ) {
                break;
            }
        }
        return array_values( array_unique( $items ) );
    }

    private static function sanitize_url( $value ) {
        $url = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
        if ( ! $url || ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) {
            return '';
        }
        return mb_substr( $url, 0, 2048 );
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
