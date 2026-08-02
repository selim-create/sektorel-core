<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lightweight signed access/refresh token service for headless requests.
 *
 * Tokens are HMAC signed with the WordPress auth salt. Refresh tokens are
 * additionally tracked in user meta, allowing logout and token rotation.
 */
class Sektorel_Token_Service {

    const ACCESS_TTL  = 900;      // 15 minutes.
    const REFRESH_TTL = 2592000;  // 30 days.
    const REFRESH_META_KEY = '_sektorel_refresh_tokens';

    public static function init() {
        add_filter( 'determine_current_user', array( __CLASS__, 'authenticate_bearer_token' ), 20 );
    }

    public static function create_token_pair( $user_id ) {
        return array(
            'authToken'    => self::create_token( $user_id, 'access', self::ACCESS_TTL ),
            'refreshToken' => self::create_refresh_token( $user_id ),
        );
    }

    public static function create_refresh_token( $user_id ) {
        $token   = self::create_token( $user_id, 'refresh', self::REFRESH_TTL );
        $payload = self::decode_token( $token, 'refresh' );

        if ( is_wp_error( $payload ) ) {
            return '';
        }

        $tokens = self::get_refresh_tokens( $user_id );
        $tokens[ $payload['jti'] ] = array(
            'hash'    => hash( 'sha256', $token ),
            'expires' => (int) $payload['exp'],
        );

        self::store_refresh_tokens( $user_id, $tokens );

        return $token;
    }

    public static function rotate_refresh_token( $token ) {
        $payload = self::decode_token( $token, 'refresh' );

        if ( is_wp_error( $payload ) ) {
            return $payload;
        }

        $user_id = (int) $payload['sub'];
        $tokens  = self::get_refresh_tokens( $user_id );
        $jti     = $payload['jti'];

        if ( empty( $tokens[ $jti ] ) || ! hash_equals( $tokens[ $jti ]['hash'], hash( 'sha256', $token ) ) ) {
            return new WP_Error( 'invalid_refresh_token', 'Yenileme anahtarı geçersiz veya daha önce kullanılmış.' );
        }

        unset( $tokens[ $jti ] );
        self::store_refresh_tokens( $user_id, $tokens );

        return self::create_token_pair( $user_id );
    }

    public static function revoke_refresh_token( $token ) {
        $payload = self::decode_token( $token, 'refresh' );

        if ( is_wp_error( $payload ) ) {
            return false;
        }

        $user_id = (int) $payload['sub'];
        $tokens  = self::get_refresh_tokens( $user_id );
        unset( $tokens[ $payload['jti'] ] );
        self::store_refresh_tokens( $user_id, $tokens );

        return true;
    }

    public static function authenticate_bearer_token( $user_id ) {
        if ( $user_id ) {
            return $user_id;
        }

        $header = self::get_authorization_header();
        if ( ! $header || ! preg_match( '/^Bearer\s+(.+)$/i', $header, $matches ) ) {
            return $user_id;
        }

        $payload = self::decode_token( trim( $matches[1] ), 'access' );
        if ( is_wp_error( $payload ) ) {
            return $user_id;
        }

        return get_user_by( 'id', (int) $payload['sub'] ) ? (int) $payload['sub'] : $user_id;
    }

    public static function decode_token( $token, $expected_type = 'access' ) {
        $parts = explode( '.', (string) $token );
        if ( 3 !== count( $parts ) ) {
            return new WP_Error( 'invalid_token', 'Token biçimi geçersiz.' );
        }

        list( $encoded_header, $encoded_payload, $encoded_signature ) = $parts;
        $expected_signature = self::base64url_encode(
            hash_hmac( 'sha256', $encoded_header . '.' . $encoded_payload, self::secret(), true )
        );

        if ( ! hash_equals( $expected_signature, $encoded_signature ) ) {
            return new WP_Error( 'invalid_signature', 'Token imzası doğrulanamadı.' );
        }

        $payload = json_decode( self::base64url_decode( $encoded_payload ), true );
        if ( ! is_array( $payload ) || empty( $payload['sub'] ) || empty( $payload['exp'] ) || empty( $payload['type'] ) ) {
            return new WP_Error( 'invalid_payload', 'Token içeriği geçersiz.' );
        }

        if ( $payload['type'] !== $expected_type ) {
            return new WP_Error( 'invalid_token_type', 'Token türü geçersiz.' );
        }

        if ( (int) $payload['exp'] < time() ) {
            return new WP_Error( 'expired_token', 'Oturum süresi doldu.' );
        }

        return $payload;
    }

    private static function create_token( $user_id, $type, $ttl ) {
        $now = time();
        $header = array( 'alg' => 'HS256', 'typ' => 'JWT' );
        $payload = array(
            'iss'  => home_url( '/' ),
            'sub'  => (int) $user_id,
            'type' => $type,
            'iat'  => $now,
            'exp'  => $now + (int) $ttl,
            'jti'  => wp_generate_uuid4(),
        );

        $encoded_header  = self::base64url_encode( wp_json_encode( $header ) );
        $encoded_payload = self::base64url_encode( wp_json_encode( $payload ) );
        $signature       = self::base64url_encode(
            hash_hmac( 'sha256', $encoded_header . '.' . $encoded_payload, self::secret(), true )
        );

        return $encoded_header . '.' . $encoded_payload . '.' . $signature;
    }

    private static function get_refresh_tokens( $user_id ) {
        $tokens = get_user_meta( $user_id, self::REFRESH_META_KEY, true );
        $tokens = is_array( $tokens ) ? $tokens : array();
        $now    = time();

        return array_filter(
            $tokens,
            function( $entry ) use ( $now ) {
                return ! empty( $entry['expires'] ) && (int) $entry['expires'] >= $now;
            }
        );
    }

    private static function store_refresh_tokens( $user_id, $tokens ) {
        update_user_meta( $user_id, self::REFRESH_META_KEY, array_slice( $tokens, -5, null, true ) );
    }

    private static function get_authorization_header() {
        if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
        }

        if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
        }

        return '';
    }

    private static function secret() {
        return wp_salt( 'auth' );
    }

    private static function base64url_encode( $value ) {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    private static function base64url_decode( $value ) {
        $padding = strlen( $value ) % 4;
        if ( $padding ) {
            $value .= str_repeat( '=', 4 - $padding );
        }
        return base64_decode( strtr( $value, '-_', '+/' ) );
    }
}
