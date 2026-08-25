<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Headless_Routing {

    const FRONTEND_ORIGIN = 'https://www.sektorelajanda.com';
    const API_HOST        = 'api.sektorelajanda.com';

    private static $post_type_routes = array(
        'post'    => 'haber',
        'company' => 'firma',
        'event'   => 'ajanda',
        'lead'    => 'firsatlar',
        'career'  => 'kariyer',
    );

    public static function init() {
        add_filter( 'post_link', array( __CLASS__, 'filter_post_link' ), 10, 2 );
        add_filter( 'post_type_link', array( __CLASS__, 'filter_post_type_link' ), 10, 2 );
        add_action( 'template_redirect', array( __CLASS__, 'redirect_public_wordpress_request' ), 0 );
    }

    public static function filter_post_link( $permalink, $post ) {
        $frontend_url = self::get_frontend_post_url( $post );
        return $frontend_url ? $frontend_url : $permalink;
    }

    public static function filter_post_type_link( $permalink, $post ) {
        $frontend_url = self::get_frontend_post_url( $post );
        return $frontend_url ? $frontend_url : $permalink;
    }

    public static function redirect_public_wordpress_request() {
        if ( ! self::is_api_host_request() || self::is_machine_or_admin_request() ) {
            return;
        }

        $target = self::get_contextual_frontend_url();
        if ( ! $target ) {
            $target = self::get_fallback_frontend_url();
        }

        if ( ! $target ) {
            return;
        }

        wp_redirect( $target, 301, 'Sektorel Headless Routing' );
        exit;
    }

    private static function get_frontend_post_url( $post ) {
        if ( ! $post instanceof WP_Post ) {
            $post = get_post( $post );
        }

        if ( ! $post || 'publish' !== $post->post_status ) {
            return '';
        }

        $route = isset( self::$post_type_routes[ $post->post_type ] )
            ? self::$post_type_routes[ $post->post_type ]
            : '';

        if ( ! $route || ! $post->post_name ) {
            return '';
        }

        return self::FRONTEND_ORIGIN . '/' . $route . '/' . rawurlencode( $post->post_name );
    }

    private static function get_contextual_frontend_url() {
        if ( is_front_page() || is_home() ) {
            return self::FRONTEND_ORIGIN . '/';
        }

        if ( is_singular() ) {
            $post = get_queried_object();
            $url  = self::get_frontend_post_url( $post );
            if ( $url ) {
                return $url;
            }
        }

        if ( is_post_type_archive( 'company' ) ) {
            return self::FRONTEND_ORIGIN . '/firmalar';
        }

        if ( is_post_type_archive( 'event' ) ) {
            return self::FRONTEND_ORIGIN . '/ajanda';
        }

        if ( is_post_type_archive( 'lead' ) ) {
            return self::FRONTEND_ORIGIN . '/firsatlar';
        }

        if ( is_post_type_archive( 'career' ) ) {
            return self::FRONTEND_ORIGIN . '/kariyer';
        }

        if ( is_category() ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term && $term->slug ) {
                return self::FRONTEND_ORIGIN . '/haberler/kategori/' . rawurlencode( $term->slug );
            }
        }

        if ( is_tag() ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term && $term->slug ) {
                return self::FRONTEND_ORIGIN . '/haberler/etiket/' . rawurlencode( $term->slug );
            }
        }

        if ( is_tax( 'sector' ) ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term && $term->slug ) {
                return self::FRONTEND_ORIGIN . '/sektor/' . rawurlencode( $term->slug );
            }
        }

        if ( is_search() ) {
            $query = get_search_query( false );
            return add_query_arg( 'q', $query, self::FRONTEND_ORIGIN . '/ara' );
        }

        return '';
    }

    private static function get_fallback_frontend_url() {
        $path = self::get_request_path();

        if ( '/wp-sitemap.xml' === $path || 0 === strpos( $path, '/sitemap_index.xml' ) ) {
            return self::FRONTEND_ORIGIN . '/sitemap.xml';
        }

        if ( ! $path ) {
            $path = '/';
        }

        return self::FRONTEND_ORIGIN . $path;
    }

    private static function is_api_host_request() {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
        $host = preg_replace( '/:\d+$/', '', $host );
        return self::API_HOST === $host;
    }

    private static function is_machine_or_admin_request() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return true;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }

        if ( isset( $_GET['rest_route'] ) ) {
            return true;
        }

        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
            return true;
        }

        $path = self::get_request_path();
        $exempt_prefixes = array(
            '/wp-admin',
            '/wp-login.php',
            '/wp-json',
            '/graphql',
            '/wp-cron.php',
            '/wp-content/',
            '/wp-includes/',
            '/xmlrpc.php',
        );

        foreach ( $exempt_prefixes as $prefix ) {
            if ( 0 === strpos( $path, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    private static function get_request_path() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $path        = wp_parse_url( 'https://placeholder.invalid' . $request_uri, PHP_URL_PATH );

        if ( ! is_string( $path ) || '' === $path ) {
            return '/';
        }

        return '/' . ltrim( $path, '/' );
    }
}
