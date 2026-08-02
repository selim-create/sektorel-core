<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Password_Reset_Mutations {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_mutations' ) );
    }

    public static function register_mutations() {
        register_graphql_mutation( 'requestSektorelPasswordReset', array(
            'description' => 'Parola sıfırlama bağlantısını e-posta ile gönderir.',
            'inputFields' => array(
                'email' => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $email = sanitize_email( $input['email'] ?? '' );

                if ( ! is_email( $email ) ) {
                    throw new \GraphQL\Error\UserError( 'Geçerli bir e-posta adresi girin.' );
                }

                $rate_key = self::rate_limit_key( 'request', $email );
                if ( get_transient( $rate_key ) ) {
                    return self::generic_response();
                }

                set_transient( $rate_key, 1, 5 * MINUTE_IN_SECONDS );
                $user = get_user_by( 'email', $email );

                if ( $user ) {
                    $filter = function( $message, $key, $user_login ) {
                        $frontend = untrailingslashit( apply_filters( 'sektorel_frontend_url', 'https://sektorelajanda.com' ) );
                        $url = add_query_arg(
                            array(
                                'key'   => rawurlencode( $key ),
                                'login' => rawurlencode( $user_login ),
                            ),
                            $frontend . '/sifre-yenile'
                        );

                        return "Sektörel Ajanda hesabınız için parola sıfırlama talebi alındı.\n\nYeni parolanızı belirlemek için bağlantıyı açın:\n{$url}\n\nBu talebi siz yapmadıysanız bu e-postayı yok sayabilirsiniz.";
                    };

                    add_filter( 'retrieve_password_message', $filter, 10, 3 );
                    retrieve_password( $user->user_login );
                    remove_filter( 'retrieve_password_message', $filter, 10 );
                }

                return self::generic_response();
            },
        ) );

        register_graphql_mutation( 'resetSektorelPassword', array(
            'description' => 'Geçerli sıfırlama anahtarıyla yeni parola belirler.',
            'inputFields' => array(
                'login'       => array( 'type' => 'String' ),
                'key'         => array( 'type' => 'String' ),
                'newPassword' => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $login        = sanitize_user( $input['login'] ?? '', true );
                $key          = sanitize_text_field( $input['key'] ?? '' );
                $new_password = (string) ( $input['newPassword'] ?? '' );

                if ( strlen( $new_password ) < 10 ) {
                    throw new \GraphQL\Error\UserError( 'Yeni şifre en az 10 karakter olmalıdır.' );
                }

                $rate_key = self::rate_limit_key( 'reset', $login );
                $attempts = (int) get_transient( $rate_key );
                if ( $attempts >= 5 ) {
                    throw new \GraphQL\Error\UserError( 'Çok fazla deneme yapıldı. Lütfen daha sonra tekrar deneyin.' );
                }

                set_transient( $rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
                $user = check_password_reset_key( $key, $login );

                if ( is_wp_error( $user ) ) {
                    throw new \GraphQL\Error\UserError( 'Parola sıfırlama bağlantısı geçersiz veya süresi dolmuş.' );
                }

                reset_password( $user, $new_password );
                delete_transient( $rate_key );
                delete_user_meta( $user->ID, '_sektorel_refresh_tokens' );

                return array(
                    'success' => true,
                    'message' => 'Şifreniz güncellendi. Yeni şifrenizle giriş yapabilirsiniz.',
                );
            },
        ) );
    }

    private static function generic_response() {
        return array(
            'success' => true,
            'message' => 'Bu e-posta sistemde kayıtlıysa parola sıfırlama bağlantısı gönderildi.',
        );
    }

    private static function rate_limit_key( $action, $identity ) {
        $ip = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        return 'sektorel_pwd_' . md5( $action . '|' . strtolower( $identity ) . '|' . $ip );
    }
}
