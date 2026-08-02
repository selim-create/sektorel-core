<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Auth_Mutations {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_mutations' ) );
    }

    public static function register_mutations() {
        register_graphql_mutation( 'login', array(
            'description' => 'Kullanıcı girişi yapar ve imzalı token çifti döner.',
            'inputFields' => array(
                'username' => array( 'type' => 'String' ),
                'password' => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'authToken'    => array( 'type' => 'String' ),
                'refreshToken' => array( 'type' => 'String' ),
                'user'         => array( 'type' => 'User' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $username = sanitize_text_field( $input['username'] ?? '' );
                $password = (string) ( $input['password'] ?? '' );

                if ( '' === $username || '' === $password ) {
                    throw new \GraphQL\Error\UserError( 'E-posta ve şifre zorunludur.' );
                }

                $user = wp_authenticate( $username, $password );
                if ( is_wp_error( $user ) ) {
                    throw new \GraphQL\Error\UserError( 'Giriş başarısız. E-posta veya şifre hatalı.' );
                }

                $tokens = Sektorel_Token_Service::create_token_pair( $user->ID );

                return array(
                    'authToken'    => $tokens['authToken'],
                    'refreshToken' => $tokens['refreshToken'],
                    'user'         => $user,
                );
            },
        ) );

        register_graphql_mutation( 'refreshSektorelToken', array(
            'description' => 'Yenileme anahtarını döndürerek yeni bir token çifti üretir.',
            'inputFields' => array(
                'refreshToken' => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'authToken'    => array( 'type' => 'String' ),
                'refreshToken' => array( 'type' => 'String' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $tokens = Sektorel_Token_Service::rotate_refresh_token( (string) ( $input['refreshToken'] ?? '' ) );
                if ( is_wp_error( $tokens ) ) {
                    throw new \GraphQL\Error\UserError( $tokens->get_error_message() );
                }
                return $tokens;
            },
        ) );

        register_graphql_mutation( 'logoutSektorelUser', array(
            'description' => 'Yenileme anahtarını iptal ederek oturumu kapatır.',
            'inputFields' => array(
                'refreshToken' => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                return array(
                    'success' => Sektorel_Token_Service::revoke_refresh_token( (string) ( $input['refreshToken'] ?? '' ) ),
                );
            },
        ) );

        register_graphql_mutation( 'registerSektorelUser', array(
            'description' => 'Yeni bireysel veya kurumsal kullanıcı oluşturur.',
            'inputFields' => array(
                'email'       => array( 'type' => 'String' ),
                'password'    => array( 'type' => 'String' ),
                'firstName'   => array( 'type' => 'String' ),
                'lastName'    => array( 'type' => 'String' ),
                'phone'       => array( 'type' => 'String' ),
                'accountType' => array( 'type' => 'String' ),
                'companyName' => array( 'type' => 'String' ),
                'taxOffice'   => array( 'type' => 'String' ),
                'taxNumber'   => array( 'type' => 'String' ),
                'sector'      => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success'   => array( 'type' => 'Boolean' ),
                'message'   => array( 'type' => 'String' ),
                'userId'    => array( 'type' => 'ID' ),
                'companyId' => array( 'type' => 'ID' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $email        = sanitize_email( $input['email'] ?? '' );
                $password     = (string) ( $input['password'] ?? '' );
                $account_type = sanitize_key( $input['accountType'] ?? 'bireysel' );

                if ( ! is_email( $email ) ) {
                    throw new \GraphQL\Error\UserError( 'Geçerli bir e-posta adresi girin.' );
                }
                if ( strlen( $password ) < 10 ) {
                    throw new \GraphQL\Error\UserError( 'Şifre en az 10 karakter olmalıdır.' );
                }
                if ( email_exists( $email ) ) {
                    throw new \GraphQL\Error\UserError( 'Bu e-posta adresi zaten kayıtlı.' );
                }
                if ( ! in_array( $account_type, array( 'bireysel', 'kurumsal' ), true ) ) {
                    throw new \GraphQL\Error\UserError( 'Hesap türü geçersiz.' );
                }
                if ( 'kurumsal' === $account_type && empty( $input['companyName'] ) ) {
                    throw new \GraphQL\Error\UserError( 'Kurumsal hesap için firma adı zorunludur.' );
                }

                $user_id = wp_create_user( $email, $password, $email );
                if ( is_wp_error( $user_id ) ) {
                    throw new \GraphQL\Error\UserError( $user_id->get_error_message() );
                }

                $first_name = sanitize_text_field( $input['firstName'] ?? '' );
                $last_name  = sanitize_text_field( $input['lastName'] ?? '' );
                wp_update_user( array(
                    'ID'           => $user_id,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'display_name' => trim( $first_name . ' ' . $last_name ) ?: $email,
                    'role'         => 'subscriber',
                ) );

                update_user_meta( $user_id, 'phone', sanitize_text_field( $input['phone'] ?? '' ) );
                update_user_meta( $user_id, 'account_type', $account_type );

                $company_id = 0;
                if ( 'kurumsal' === $account_type ) {
                    update_user_meta( $user_id, 'company_name', sanitize_text_field( $input['companyName'] ?? '' ) );
                    update_user_meta( $user_id, 'tax_office', sanitize_text_field( $input['taxOffice'] ?? '' ) );
                    update_user_meta( $user_id, 'tax_number', sanitize_text_field( $input['taxNumber'] ?? '' ) );
                    update_user_meta( $user_id, 'sector', sanitize_text_field( $input['sector'] ?? '' ) );
                    $company_id = Sektorel_Company_Mutations::create_company_for_user( $user_id, $input );

                    if ( ! $company_id ) {
                        require_once ABSPATH . 'wp-admin/includes/user.php';
                        wp_delete_user( $user_id );
                        throw new \GraphQL\Error\UserError( 'Firma kaydı oluşturulamadığı için üyelik tamamlanamadı.' );
                    }
                }

                return array(
                    'success'   => true,
                    'message'   => 'Kayıt başarılı. Giriş yapabilirsiniz.',
                    'userId'    => $user_id,
                    'companyId' => $company_id ?: null,
                );
            },
        ) );
    }
}
