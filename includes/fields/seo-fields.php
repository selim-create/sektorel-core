<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_SEO_Fields {

    private const GRAPHQL_TYPES = array(
        'Post',
        'Page',
        'Company',
        'Event',
        'Lead',
        'Job',
        'Category',
        'Tag',
        'Sector',
        'Location',
    );

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ) );
    }

    public static function register_graphql_fields() {
        register_graphql_object_type( 'SektorelSeo', array(
            'description' => 'Rank Math explicit SEO metadata for headless rendering.',
            'fields'      => array(
                'title'              => array( 'type' => 'String' ),
                'description'        => array( 'type' => 'String' ),
                'canonicalUrl'       => array( 'type' => 'String' ),
                'robots'             => array( 'type' => array( 'list_of' => 'String' ) ),
                'openGraphTitle'     => array( 'type' => 'String' ),
                'openGraphDescription' => array( 'type' => 'String' ),
                'openGraphImage'     => array( 'type' => 'String' ),
                'twitterTitle'       => array( 'type' => 'String' ),
                'twitterDescription' => array( 'type' => 'String' ),
                'twitterImage'       => array( 'type' => 'String' ),
            ),
        ) );

        foreach ( self::GRAPHQL_TYPES as $type ) {
            register_graphql_field( $type, 'sektorelSeo', array(
                'type'        => 'SektorelSeo',
                'description' => 'Explicit Rank Math SEO metadata. Empty values should fall back to frontend defaults.',
                'resolve'     => function( $source ) {
                    return self::resolve_seo( $source );
                },
            ) );
        }
    }

    private static function resolve_seo( $source ) {
        $is_term = self::is_term_source( $source );
        $object_id = self::get_object_id( $source, $is_term );

        if ( ! $object_id ) {
            return self::empty_payload();
        }

        $read = function( $key ) use ( $object_id, $is_term ) {
            return $is_term
                ? get_term_meta( $object_id, $key, true )
                : get_post_meta( $object_id, $key, true );
        };

        $facebook_title = self::resolve_text( $read( 'rank_math_facebook_title' ), $source );
        $facebook_description = self::resolve_text( $read( 'rank_math_facebook_description' ), $source );
        $facebook_image = self::resolve_url( $read( 'rank_math_facebook_image' ), $source );

        $twitter_title = self::resolve_text( $read( 'rank_math_twitter_title' ), $source );
        $twitter_description = self::resolve_text( $read( 'rank_math_twitter_description' ), $source );
        $twitter_image = self::resolve_url( $read( 'rank_math_twitter_image' ), $source );
        $twitter_use_facebook = self::is_truthy( $read( 'rank_math_twitter_use_facebook' ) );

        if ( $twitter_use_facebook ) {
            $twitter_title = $twitter_title ?: $facebook_title;
            $twitter_description = $twitter_description ?: $facebook_description;
            $twitter_image = $twitter_image ?: $facebook_image;
        }

        return array(
            'title'                => self::resolve_text( $read( 'rank_math_title' ), $source ),
            'description'          => self::resolve_text( $read( 'rank_math_description' ), $source ),
            'canonicalUrl'         => self::resolve_url( $read( 'rank_math_canonical_url' ), $source ),
            'robots'               => self::normalize_robots( $read( 'rank_math_robots' ) ),
            'openGraphTitle'       => $facebook_title,
            'openGraphDescription' => $facebook_description,
            'openGraphImage'       => $facebook_image,
            'twitterTitle'         => $twitter_title,
            'twitterDescription'   => $twitter_description,
            'twitterImage'         => $twitter_image,
        );
    }

    private static function get_object_id( $source, $is_term ) {
        if ( ! is_object( $source ) ) {
            return 0;
        }

        if ( $is_term && isset( $source->term_id ) ) {
            return absint( $source->term_id );
        }

        if ( isset( $source->ID ) ) {
            return absint( $source->ID );
        }

        if ( isset( $source->databaseId ) ) {
            return absint( $source->databaseId );
        }

        if ( isset( $source->database_id ) ) {
            return absint( $source->database_id );
        }

        return 0;
    }

    private static function is_term_source( $source ) {
        return is_object( $source ) && ( $source instanceof WP_Term || isset( $source->term_id ) );
    }

    private static function resolve_text( $value, $source ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        $value = self::replace_rank_math_vars( $value, $source );
        if ( '' === $value || preg_match( '/%[a-zA-Z0-9_().:-]+%/', $value ) ) {
            return '';
        }

        return sanitize_text_field( wp_strip_all_tags( $value ) );
    }

    private static function resolve_url( $value, $source ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }

        $value = trim( (string) $value );
        if ( '' === $value ) {
            return '';
        }

        $value = self::replace_rank_math_vars( $value, $source );
        if ( '' === $value || false !== strpos( $value, '%' ) ) {
            return '';
        }

        return esc_url_raw( $value );
    }

    private static function replace_rank_math_vars( $value, $source ) {
        if ( false === strpos( $value, '%' ) ) {
            return $value;
        }

        if ( ! class_exists( '\\RankMath\\Helper' ) || ! is_callable( array( '\\RankMath\\Helper', 'replace_vars' ) ) ) {
            return '';
        }

        try {
            return (string) \RankMath\Helper::replace_vars( $value, $source );
        } catch ( Throwable $error ) {
            return '';
        }
    }

    private static function normalize_robots( $value ) {
        if ( is_string( $value ) ) {
            $maybe_unserialized = maybe_unserialize( $value );
            if ( is_array( $maybe_unserialized ) ) {
                $value = $maybe_unserialized;
            } else {
                $value = preg_split( '/[;,\s]+/', $value );
            }
        }

        if ( ! is_array( $value ) ) {
            return array();
        }

        $allowed = array( 'index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex' );
        $robots = array();

        foreach ( $value as $directive ) {
            $directive = sanitize_key( (string) $directive );
            if ( in_array( $directive, $allowed, true ) ) {
                $robots[] = $directive;
            }
        }

        return array_values( array_unique( $robots ) );
    }

    private static function is_truthy( $value ) {
        if ( is_bool( $value ) ) {
            return $value;
        }

        return in_array( strtolower( trim( (string) $value ) ), array( '1', 'on', 'yes', 'true' ), true );
    }

    private static function empty_payload() {
        return array(
            'title'                => '',
            'description'          => '',
            'canonicalUrl'         => '',
            'robots'               => array(),
            'openGraphTitle'       => '',
            'openGraphDescription' => '',
            'openGraphImage'       => '',
            'twitterTitle'         => '',
            'twitterDescription'   => '',
            'twitterImage'         => '',
        );
    }
}
