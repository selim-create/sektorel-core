<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Mutations {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_mutations' ) );
    }

    public static function register_mutations() {
        register_graphql_mutation( 'submitCompany', array(
            'description' => 'Giriş yapan kullanıcı adına onay bekleyen firma kaydı oluşturur.',
            'inputFields' => array(
                'title'        => array( 'type' => 'String' ),
                'officialName' => array( 'type' => 'String' ),
                'sector'       => array( 'type' => 'String' ),
                'companyType'  => array( 'type' => 'String' ),
                'description'  => array( 'type' => 'String' ),
                'email'        => array( 'type' => 'String' ),
                'phone'        => array( 'type' => 'String' ),
                'website'      => array( 'type' => 'String' ),
                'city'         => array( 'type' => 'String' ),
                'district'     => array( 'type' => 'String' ),
                'postalCode'   => array( 'type' => 'String' ),
                'address'      => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
                'postId'  => array( 'type' => 'ID' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $user_id = get_current_user_id();
                if ( ! $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Firma eklemek için giriş yapmanız gerekir.' );
                }

                $existing_company_id = Sektorel_Session_Query::get_owned_company_id( $user_id );
                if ( $existing_company_id ) {
                    throw new \GraphQL\Error\UserError( 'Bu hesaba bağlı bir firma kaydı zaten bulunuyor.' );
                }

                $title = sanitize_text_field( $input['title'] ?? '' );
                if ( '' === $title ) {
                    throw new \GraphQL\Error\UserError( 'Firma adı zorunludur.' );
                }

                $post_id = wp_insert_post( array(
                    'post_title'   => $title,
                    'post_content' => wp_kses_post( $input['description'] ?? '' ),
                    'post_status'  => 'pending',
                    'post_type'    => 'company',
                    'post_author'  => $user_id,
                ), true );

                if ( is_wp_error( $post_id ) ) {
                    throw new \GraphQL\Error\UserError( 'Firma oluşturulamadı: ' . $post_id->get_error_message() );
                }

                self::save_company_meta( $post_id, $input );
                self::save_company_terms( $post_id, $input );

                update_user_meta( $user_id, '_sektorel_company_id', $post_id );
                update_user_meta( $user_id, 'account_type', 'kurumsal' );
                update_user_meta( $user_id, 'company_name', $title );

                return array(
                    'success' => true,
                    'message' => 'Firma başvurunuz alındı. İnceleme sonrası yayına alınacaktır.',
                    'postId'  => $post_id,
                );
            },
        ) );
    }

    public static function create_company_for_user( $user_id, $input ) {
        $title = sanitize_text_field( $input['companyName'] ?? '' );
        if ( ! $user_id || '' === $title ) {
            return 0;
        }

        $post_id = wp_insert_post( array(
            'post_title'  => $title,
            'post_status' => 'pending',
            'post_type'   => 'company',
            'post_author' => (int) $user_id,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        update_post_meta( $post_id, 'official_name', $title );
        update_post_meta( $post_id, 'tax_office', sanitize_text_field( $input['taxOffice'] ?? '' ) );
        update_post_meta( $post_id, 'tax_number', sanitize_text_field( $input['taxNumber'] ?? '' ) );

        if ( ! empty( $input['sector'] ) ) {
            self::assign_term( $post_id, $input['sector'], 'sector' );
        }

        update_user_meta( $user_id, '_sektorel_company_id', $post_id );
        return (int) $post_id;
    }

    private static function save_company_meta( $post_id, $input ) {
        $meta_fields = array(
            'company_type' => 'companyType',
            'official_name'=> 'officialName',
            'phone'        => 'phone',
            'postal_code'  => 'postalCode',
            'address'      => 'address',
        );

        foreach ( $meta_fields as $meta_key => $input_key ) {
            if ( isset( $input[ $input_key ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( $input[ $input_key ] ) );
            }
        }

        if ( isset( $input['email'] ) ) {
            update_post_meta( $post_id, 'email', sanitize_email( $input['email'] ) );
        }
        if ( isset( $input['website'] ) ) {
            update_post_meta( $post_id, 'website', esc_url_raw( $input['website'] ) );
        }
    }

    private static function save_company_terms( $post_id, $input ) {
        if ( ! empty( $input['sector'] ) ) {
            self::assign_term( $post_id, $input['sector'], 'sector' );
        }

        $location_ids = array();
        foreach ( array( 'city', 'district' ) as $key ) {
            if ( empty( $input[ $key ] ) ) {
                continue;
            }
            $term = self::find_term( $input[ $key ], 'location' );
            if ( $term ) {
                $location_ids[] = (int) $term->term_id;
            }
        }

        if ( $location_ids ) {
            wp_set_object_terms( $post_id, array_values( array_unique( $location_ids ) ), 'location' );
        }
    }

    private static function assign_term( $post_id, $value, $taxonomy ) {
        $term = self::find_term( $value, $taxonomy );
        if ( $term ) {
            wp_set_object_terms( $post_id, array( (int) $term->term_id ), $taxonomy );
        }
    }

    private static function find_term( $value, $taxonomy ) {
        $value = sanitize_text_field( $value );
        return get_term_by( 'slug', $value, $taxonomy ) ?: get_term_by( 'name', $value, $taxonomy );
    }
}
