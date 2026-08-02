<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Owned_Content {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelOwnedContentItem', array(
            'fields' => array(
                'databaseId' => array( 'type' => 'Int' ),
                'title'      => array( 'type' => 'String' ),
                'type'       => array( 'type' => 'String' ),
                'status'     => array( 'type' => 'String' ),
                'date'       => array( 'type' => 'String' ),
                'slug'       => array( 'type' => 'String' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelOwnedContent', array(
            'type' => array( 'list_of' => 'SektorelOwnedContentItem' ),
            'args' => array(
                'type' => array( 'type' => 'String' ),
            ),
            'resolve' => function( $root, $args ) {
                $user_id = get_current_user_id();
                if ( ! $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Bu alan için giriş yapmanız gerekir.' );
                }

                $allowed_types = array( 'lead', 'career', 'event' );
                $requested     = sanitize_key( $args['type'] ?? '' );
                $post_types    = $requested && in_array( $requested, $allowed_types, true )
                    ? array( $requested )
                    : $allowed_types;

                $posts = get_posts( array(
                    'post_type'      => $post_types,
                    'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
                    'author'         => (int) $user_id,
                    'posts_per_page' => 100,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ) );

                return array_map( function( $post ) {
                    return array(
                        'databaseId' => (int) $post->ID,
                        'title'      => get_the_title( $post ),
                        'type'       => $post->post_type,
                        'status'     => $post->post_status,
                        'date'       => get_post_time( DATE_ATOM, true, $post ),
                        'slug'       => $post->post_name,
                    );
                }, $posts );
            },
        ) );

        register_graphql_mutation( 'trashSektorelOwnedContent', array(
            'inputFields' => array(
                'databaseId' => array( 'type' => 'Int' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $user_id = get_current_user_id();
                if ( ! $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Bu işlem için giriş yapmanız gerekir.' );
                }

                $post_id = (int) ( $input['databaseId'] ?? 0 );
                $post    = get_post( $post_id );

                if ( ! $post || ! in_array( $post->post_type, array( 'lead', 'career', 'event' ), true ) ) {
                    throw new \GraphQL\Error\UserError( 'İçerik bulunamadı.' );
                }

                if ( (int) $post->post_author !== (int) $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Bu içerik üzerinde işlem yapma yetkiniz yok.' );
                }

                if ( ! wp_trash_post( $post_id ) ) {
                    throw new \GraphQL\Error\UserError( 'İçerik çöp kutusuna taşınamadı.' );
                }

                return array(
                    'success' => true,
                    'message' => 'İçerik çöp kutusuna taşındı.',
                );
            },
        ) );
    }
}
