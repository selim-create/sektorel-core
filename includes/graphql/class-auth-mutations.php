<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Auth_Mutations {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_mutations' ) );
    }

    public static function register_mutations() {
        
        // ---------------------------------------------------------
        // 1. LOGIN MUTATION (Giriş Yap)
        // ---------------------------------------------------------
        register_graphql_mutation( 'login', array(
            'description' => 'Kullanıcı girişi yapar ve token döner.',
            'inputFields' => array(
                'username' => array( 'type' => 'String', 'description' => 'E-posta veya Kullanıcı Adı' ),
                'password' => array( 'type' => 'String', 'description' => 'Şifre' ),
            ),
            'outputFields' => array(
                'authToken'    => array( 'type' => 'String' ),
                'refreshToken' => array( 'type' => 'String' ),
                'user'         => array( 'type' => 'User' ), // WPGraphQL User Tipi
            ),
            'mutateAndGetPayload' => function( $input ) {
                $username = sanitize_text_field( $input['username'] );
                $password = $input['password'];

                // WordPress Native Giriş İşlemi
                $creds = array(
                    'user_login'    => $username,
                    'user_password' => $password,
                    'remember'      => true
                );

                $user = wp_signon( $creds, false );

                if ( is_wp_error( $user ) ) {
                    // Hatalı giriş
                    throw new \GraphQL\Error\UserError( 'Giriş başarısız! E-posta veya şifre hatalı.' );
                }

                // Basit Token Oluşturma (Prototip İçin)
                // Not: Prodüksiyonda JWT kütüphanesi kullanılması önerilir.
                // Burada kullanıcı ID ve email'i base64 ile şifreliyoruz.
                $token_payload = $user->ID . '|' . $user->user_email . '|' . time();
                $authToken = base64_encode( $token_payload );

                return array(
                    'authToken'    => $authToken,
                    'refreshToken' => $authToken, // Şimdilik aynısı
                    'user'         => $user,
                );
            }
        ));

        // ---------------------------------------------------------
        // 2. REGISTER MUTATION (Kayıt Ol)
        // ---------------------------------------------------------
        register_graphql_mutation( 'registerSektorelUser', array(
            'description' => 'Yeni kullanıcı ve firma kaydı oluşturur.',
            'inputFields' => array(
                'email'       => array( 'type' => 'String', 'description' => 'E-posta adresi' ),
                'password'    => array( 'type' => 'String', 'description' => 'Şifre' ),
                'firstName'   => array( 'type' => 'String', 'description' => 'Ad' ),
                'lastName'    => array( 'type' => 'String', 'description' => 'Soyad (Opsiyonel)' ),
                'phone'       => array( 'type' => 'String', 'description' => 'Telefon Numarası' ),
                'accountType' => array( 'type' => 'String', 'description' => 'bireysel veya kurumsal' ),
                // Kurumsal Alanlar
                'companyName' => array( 'type' => 'String', 'description' => 'Firma Ünvanı' ),
                'taxOffice'   => array( 'type' => 'String', 'description' => 'Vergi Dairesi' ),
                'taxNumber'   => array( 'type' => 'String', 'description' => 'Vergi Numarası' ),
                'sector'      => array( 'type' => 'String', 'description' => 'Sektör' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
                'userId'  => array( 'type' => 'ID' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $email = sanitize_email( $input['email'] );
                $password = $input['password'];
                $username = $email;

                if ( email_exists( $email ) ) {
                    throw new \GraphQL\Error\UserError( 'Bu e-posta adresi zaten kayıtlı.' );
                }

                // Kullanıcıyı Oluştur
                $user_id = wp_create_user( $username, $password, $email );

                if ( is_wp_error( $user_id ) ) {
                    throw new \GraphQL\Error\UserError( $user_id->get_error_message() );
                }

                // İsim Ayrıştırma
                $name_parts = explode(' ', $input['firstName']);
                $first_name = $name_parts[0];
                $last_name = isset($input['lastName']) && !empty($input['lastName']) ? $input['lastName'] : (count($name_parts) > 1 ? end($name_parts) : '');
                
                wp_update_user( array(
                    'ID'           => $user_id,
                    'first_name'   => $first_name,
                    'last_name'    => $last_name,
                    'display_name' => $input['firstName']
                ) );

                // Meta Verileri Kaydet
                update_user_meta( $user_id, 'phone', sanitize_text_field( $input['phone'] ) );
                update_user_meta( $user_id, 'account_type', sanitize_text_field( $input['accountType'] ) );

                if ( $input['accountType'] === 'kurumsal' ) {
                    update_user_meta( $user_id, 'company_name', sanitize_text_field( $input['companyName'] ) );
                    update_user_meta( $user_id, 'tax_office', sanitize_text_field( $input['taxOffice'] ) );
                    update_user_meta( $user_id, 'tax_number', sanitize_text_field( $input['taxNumber'] ) );
                    update_user_meta( $user_id, 'sector', sanitize_text_field( $input['sector'] ) );
                }

                return array(
                    'success' => true,
                    'message' => 'Kayıt başarılı! Giriş yapabilirsiniz.',
                    'userId'  => $user_id
                );
            }
        ));
    }
}