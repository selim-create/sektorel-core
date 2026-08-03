<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Settings {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        $settings_fields = array_merge(
            array(
                'databaseId'   => array( 'type' => 'Int' ),
                'title'        => array( 'type' => 'String' ),
                'officialName' => array( 'type' => 'String' ),
                'description'  => array( 'type' => 'String' ),
                'companyType'  => array( 'type' => 'String' ),
                'email'        => array( 'type' => 'String' ),
                'phone'        => array( 'type' => 'String' ),
                'website'      => array( 'type' => 'String' ),
                'address'      => array( 'type' => 'String' ),
                'postalCode'   => array( 'type' => 'String' ),
                'sector'       => array( 'type' => 'String' ),
                'city'         => array( 'type' => 'String' ),
                'district'     => array( 'type' => 'String' ),
                'status'       => array( 'type' => 'String' ),
            ),
            Sektorel_Company_Profile::settings_fields()
        );

        register_graphql_object_type( 'SektorelCompanySettings', array(
            'description' => 'Giriş yapan kullanıcının sahip olduğu firma ayarları.',
            'fields'      => $settings_fields,
        ) );

        register_graphql_field( 'RootQuery', 'sektorelCompanySettings', array(
            'type'        => 'SektorelCompanySettings',
            'description' => 'Oturum sahibinin firma ayarlarını döndürür.',
            'resolve'     => function() {
                return self::format_company_settings( self::get_owned_company_id_or_error() );
            },
        ) );

        $mutation_fields = array_merge(
            array(
                'title'        => array( 'type' => 'String' ),
                'officialName' => array( 'type' => 'String' ),
                'description'  => array( 'type' => 'String' ),
                'companyType'  => array( 'type' => 'String' ),
                'email'        => array( 'type' => 'String' ),
                'phone'        => array( 'type' => 'String' ),
                'website'      => array( 'type' => 'String' ),
                'address'      => array( 'type' => 'String' ),
                'postalCode'   => array( 'type' => 'String' ),
                'sector'       => array( 'type' => 'String' ),
                'city'         => array( 'type' => 'String' ),
                'district'     => array( 'type' => 'String' ),
            ),
            Sektorel_Company_Profile::mutation_fields()
        );

        register_graphql_mutation( 'updateSektorelCompany', array(
            'description' => 'Oturum sahibinin firma profilini günceller.',
            'inputFields' => $mutation_fields,
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
                'company' => array( 'type' => 'SektorelCompanySettings' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $company_id = self::get_owned_company_id_or_error();
                $title = sanitize_text_field( $input['title'] ?? '' );

                if ( '' === $title ) {
                    throw new \GraphQL\Error\UserError( 'Firma adı zorunludur.' );
                }

                if ( array_key_exists( 'email', $input ) ) {
                    $email = sanitize_email( $input['email'] );
                    if ( ! empty( $input['email'] ) && ! is_email( $email ) ) {
                        throw new \GraphQL\Error\UserError( 'Geçerli bir e-posta adresi girin.' );
                    }
                }

                $post_update = array(
                    'ID'         => $company_id,
                    'post_title' => $title,
                );
                if ( array_key_exists( 'description', $input ) ) {
                    $post_update['post_content'] = wp_kses_post( $input['description'] );
                }

                $updated = wp_update_post( $post_update, true );
                if ( is_wp_error( $updated ) ) {
                    throw new \GraphQL\Error\UserError( 'Firma güncellenemedi: ' . $updated->get_error_message() );
                }

                self::save_meta( $company_id, $input );
                self::save_terms( $company_id, $input );
                Sektorel_Company_Profile::save_profile( $company_id, $input );

                update_user_meta( get_current_user_id(), 'company_name', $title );
                clean_post_cache( $company_id );

                return array(
                    'success' => true,
                    'message' => 'Firma profiliniz güncellendi.',
                    'company' => self::format_company_settings( $company_id ),
                );
            },
        ) );
    }

    private static function get_owned_company_id_or_error() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            throw new \GraphQL\Error\UserError( 'Bu işlem için giriş yapmanız gerekir.' );
        }

        $company_id = Sektorel_Session_Query::get_owned_company_id( $user_id );
        if ( ! $company_id ) {
            throw new \GraphQL\Error\UserError( 'Bu hesaba bağlı bir firma bulunamadı.' );
        }

        $post = get_post( $company_id );
        if ( ! $post || 'company' !== $post->post_type || (int) $post->post_author !== (int) $user_id ) {
            throw new \GraphQL\Error\UserError( 'Bu firmayı düzenleme yetkiniz bulunmuyor.' );
        }

        return (int) $company_id;
    }

    private static function format_company_settings( $company_id ) {
        $post = get_post( $company_id );
        $locations = wp_get_object_terms( $company_id, 'location', array( 'orderby' => 'parent', 'order' => 'ASC' ) );
        $city = '';
        $district = '';

        if ( ! is_wp_error( $locations ) ) {
            foreach ( $locations as $term ) {
                $type = (string) get_term_meta( $term->term_id, 'location_type', true );
                if ( 'city' === $type && '' === $city ) {
                    $city = $term->slug;
                } elseif ( 'district' === $type && '' === $district ) {
                    $district = $term->slug;
                }
            }

            // Eski kayıtlarda location_type bulunmuyorsa hiyerarşiden tahmin et.
            if ( '' === $city ) {
                foreach ( $locations as $term ) {
                    $parent = $term->parent ? get_term( $term->parent, 'location' ) : null;
                    $parent_type = $parent && ! is_wp_error( $parent )
                        ? (string) get_term_meta( $parent->term_id, 'location_type', true )
                        : '';

                    if ( 'country' === $parent_type ) {
                        $city = $term->slug;
                    } elseif ( $term->parent && '' === $district ) {
                        $district = $term->slug;
                    }
                }
            }
        }

        return array_merge(
            array(
                'databaseId'   => (int) $company_id,
                'title'        => get_the_title( $company_id ),
                'officialName' => (string) get_post_meta( $company_id, 'official_name', true ),
                'description'  => $post ? $post->post_content : '',
                'companyType'  => (string) get_post_meta( $company_id, 'company_type', true ),
                'email'        => (string) get_post_meta( $company_id, 'email', true ),
                'phone'        => (string) get_post_meta( $company_id, 'phone', true ),
                'website'      => (string) get_post_meta( $company_id, 'website', true ),
                'address'      => (string) get_post_meta( $company_id, 'address', true ),
                'postalCode'   => (string) get_post_meta( $company_id, 'postal_code', true ),
                'sector'       => self::first_term_slug( $company_id, 'sector' ),
                'city'         => $city,
                'district'     => $district,
                'status'       => $post ? $post->post_status : '',
            ),
            Sektorel_Company_Profile::settings_payload( $company_id )
        );
    }

    private static function save_meta( $company_id, $input ) {
        $text_fields = array(
            'official_name' => 'officialName',
            'company_type'  => 'companyType',
            'phone'         => 'phone',
            'postal_code'   => 'postalCode',
        );

        foreach ( $text_fields as $meta_key => $input_key ) {
            if ( array_key_exists( $input_key, $input ) ) {
                update_post_meta( $company_id, $meta_key, sanitize_text_field( $input[ $input_key ] ) );
            }
        }

        if ( array_key_exists( 'email', $input ) ) {
            update_post_meta( $company_id, 'email', sanitize_email( $input['email'] ) );
        }
        if ( array_key_exists( 'website', $input ) ) {
            update_post_meta( $company_id, 'website', esc_url_raw( $input['website'], array( 'http', 'https' ) ) );
        }
        if ( array_key_exists( 'address', $input ) ) {
            update_post_meta( $company_id, 'address', sanitize_textarea_field( $input['address'] ) );
        }
    }

    private static function save_terms( $company_id, $input ) {
        if ( array_key_exists( 'sector', $input ) ) {
            if ( ! empty( $input['sector'] ) ) {
                self::set_single_term( $company_id, $input['sector'], 'sector' );
            } else {
                wp_set_object_terms( $company_id, array(), 'sector', false );
            }
        }

        if ( ! array_key_exists( 'city', $input ) && ! array_key_exists( 'district', $input ) ) {
            return;
        }

        $location_ids = array();
        $city_term = null;
        if ( ! empty( $input['city'] ) ) {
            $city_term = self::find_term( $input['city'], 'location' );
            if ( ! $city_term || 'city' !== (string) get_term_meta( $city_term->term_id, 'location_type', true ) ) {
                throw new \GraphQL\Error\UserError( 'Seçilen şehir bulunamadı.' );
            }
            $location_ids[] = (int) $city_term->term_id;
        }

        if ( ! empty( $input['district'] ) ) {
            $district_term = self::find_term( $input['district'], 'location' );
            if ( ! $district_term || 'district' !== (string) get_term_meta( $district_term->term_id, 'location_type', true ) ) {
                throw new \GraphQL\Error\UserError( 'Seçilen ilçe bulunamadı.' );
            }
            if ( $city_term && (int) $district_term->parent !== (int) $city_term->term_id ) {
                throw new \GraphQL\Error\UserError( 'Seçilen ilçe bu şehre bağlı değil.' );
            }
            $location_ids[] = (int) $district_term->term_id;
        }

        wp_set_object_terms( $company_id, array_values( array_unique( $location_ids ) ), 'location', false );
    }

    private static function set_single_term( $company_id, $value, $taxonomy ) {
        $term = self::find_term( $value, $taxonomy );
        if ( ! $term ) {
            throw new \GraphQL\Error\UserError( 'Seçilen sektör bulunamadı.' );
        }
        wp_set_object_terms( $company_id, array( (int) $term->term_id ), $taxonomy, false );
    }

    private static function find_term( $value, $taxonomy ) {
        $value = sanitize_text_field( $value );
        return get_term_by( 'slug', $value, $taxonomy ) ?: get_term_by( 'name', $value, $taxonomy );
    }

    private static function first_term_slug( $company_id, $taxonomy ) {
        $terms = wp_get_object_terms( $company_id, $taxonomy, array( 'number' => 1 ) );
        return ! is_wp_error( $terms ) && ! empty( $terms[0] ) ? (string) $terms[0]->slug : '';
    }
}
