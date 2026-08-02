<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Session_Query {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelCompanySummary', array(
            'fields' => array(
                'databaseId' => array( 'type' => 'Int' ),
                'title'      => array( 'type' => 'String' ),
                'slug'       => array( 'type' => 'String' ),
                'status'     => array( 'type' => 'String' ),
                'verified'   => array( 'type' => 'Boolean' ),
                'viewCount'  => array( 'type' => 'Int' ),
            ),
        ) );

        register_graphql_object_type( 'SektorelSessionProfile', array(
            'fields' => array(
                'userId'      => array( 'type' => 'Int' ),
                'displayName' => array( 'type' => 'String' ),
                'email'       => array( 'type' => 'String' ),
                'phone'       => array( 'type' => 'String' ),
                'accountType' => array( 'type' => 'String' ),
                'role'        => array( 'type' => 'String' ),
                'company'     => array( 'type' => 'SektorelCompanySummary' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelSession', array(
            'type'    => 'SektorelSessionProfile',
            'resolve' => function() {
                $user_id = get_current_user_id();
                if ( ! $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Bu alan için giriş yapmanız gerekir.' );
                }

                $user       = get_userdata( $user_id );
                $company_id = self::get_owned_company_id( $user_id );
                $company    = null;

                if ( $company_id ) {
                    $company_post = get_post( $company_id );
                    if ( $company_post && 'company' === $company_post->post_type ) {
                        $company = array(
                            'databaseId' => (int) $company_post->ID,
                            'title'      => get_the_title( $company_post ),
                            'slug'       => $company_post->post_name,
                            'status'     => $company_post->post_status,
                            'verified'   => (bool) get_post_meta( $company_post->ID, 'is_verified', true ),
                            'viewCount'  => (int) get_post_meta( $company_post->ID, 'view_count', true ),
                        );
                    }
                }

                return array(
                    'userId'      => (int) $user_id,
                    'displayName' => $user ? $user->display_name : '',
                    'email'       => $user ? $user->user_email : '',
                    'phone'       => (string) get_user_meta( $user_id, 'phone', true ),
                    'accountType' => (string) ( get_user_meta( $user_id, 'account_type', true ) ?: 'bireysel' ),
                    'role'        => $user && ! empty( $user->roles ) ? (string) reset( $user->roles ) : 'subscriber',
                    'company'     => $company,
                );
            },
        ) );
    }

    public static function get_owned_company_id( $user_id ) {
        $company_id = (int) get_user_meta( $user_id, '_sektorel_company_id', true );
        if ( $company_id && 'company' === get_post_type( $company_id ) ) {
            return $company_id;
        }

        $owned = get_posts( array(
            'post_type'      => 'company',
            'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
            'author'         => (int) $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'ASC',
        ) );

        if ( ! empty( $owned[0] ) ) {
            $company_id = (int) $owned[0];
            update_user_meta( $user_id, '_sektorel_company_id', $company_id );
            return $company_id;
        }

        return 0;
    }
}
