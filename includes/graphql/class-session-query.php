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

        register_graphql_object_type( 'SektorelDashboardStats', array(
            'fields' => array(
                'leadCount'  => array( 'type' => 'Int' ),
                'jobCount'   => array( 'type' => 'Int' ),
                'eventCount' => array( 'type' => 'Int' ),
                'viewCount'  => array( 'type' => 'Int' ),
            ),
        ) );

        register_graphql_object_type( 'SektorelDashboardItem', array(
            'fields' => array(
                'databaseId' => array( 'type' => 'Int' ),
                'title'      => array( 'type' => 'String' ),
                'type'       => array( 'type' => 'String' ),
                'status'     => array( 'type' => 'String' ),
                'date'       => array( 'type' => 'String' ),
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
                'companyRole' => array( 'type' => 'String' ),
                'company'     => array( 'type' => 'SektorelCompanySummary' ),
                'stats'       => array( 'type' => 'SektorelDashboardStats' ),
                'recentItems' => array( 'type' => array( 'list_of' => 'SektorelDashboardItem' ) ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelSession', array(
            'type'    => 'SektorelSessionProfile',
            'resolve' => function() {
                $user_id = get_current_user_id();
                if ( ! $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Bu alan için giriş yapmanız gerekir.' );
                }

                $user         = get_userdata( $user_id );
                $owned_id     = self::get_owned_company_id( $user_id );
                $company_id   = $owned_id ?: self::get_member_company_id( $user_id );
                $company_role = $owned_id ? 'owner' : (string) ( get_user_meta( $user_id, '_sektorel_company_role', true ) ?: '' );
                $company      = null;
                $view_count   = 0;

                if ( $company_id ) {
                    $company_post = get_post( $company_id );
                    if ( $company_post && 'company' === $company_post->post_type ) {
                        $view_count = (int) get_post_meta( $company_post->ID, 'view_count', true );
                        $company = array(
                            'databaseId' => (int) $company_post->ID,
                            'title'      => get_the_title( $company_post ),
                            'slug'       => $company_post->post_name,
                            'status'     => $company_post->post_status,
                            'verified'   => (bool) get_post_meta( $company_post->ID, 'is_verified', true ),
                            'viewCount'  => $view_count,
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
                    'companyRole' => $company_role,
                    'company'     => $company,
                    'stats'       => array(
                        'leadCount'  => self::count_owned_posts( $user_id, 'lead' ),
                        'jobCount'   => self::count_owned_posts( $user_id, 'career' ),
                        'eventCount' => self::count_owned_posts( $user_id, 'event' ),
                        'viewCount'  => $view_count,
                    ),
                    'recentItems' => self::get_recent_items( $user_id ),
                );
            },
        ) );
    }

    private static function count_owned_posts( $user_id, $post_type ) {
        $query = new WP_Query( array(
            'post_type'      => $post_type,
            'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
            'author'         => (int) $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        ) );

        return (int) $query->found_posts;
    }

    private static function get_recent_items( $user_id ) {
        $posts = get_posts( array(
            'post_type'      => array( 'lead', 'career', 'event' ),
            'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
            'author'         => (int) $user_id,
            'posts_per_page' => 5,
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
            );
        }, $posts );
    }

    public static function get_owned_company_id( $user_id ) {
        $company_id = (int) get_user_meta( $user_id, '_sektorel_company_id', true );
        if ( $company_id && 'company' === get_post_type( $company_id ) ) {
            $company = get_post( $company_id );
            if ( $company && (int) $company->post_author === (int) $user_id ) {
                return $company_id;
            }
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

    public static function get_member_company_id( $user_id ) {
        $company_id = (int) get_user_meta( $user_id, '_sektorel_member_company_id', true );
        return $company_id && 'company' === get_post_type( $company_id ) ? $company_id : 0;
    }

    public static function get_accessible_company_id( $user_id ) {
        return self::get_owned_company_id( $user_id ) ?: self::get_member_company_id( $user_id );
    }
}
