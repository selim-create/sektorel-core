<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Access {

    private static $content_types = array( 'lead', 'career', 'event' );
    private static $content_statuses = array( 'publish', 'pending', 'draft', 'private' );

    public static function get_context( $user_id = 0 ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return array(
                'user_id'    => 0,
                'company_id' => 0,
                'role'       => '',
                'is_owner'   => false,
                'can_edit'   => false,
            );
        }

        $owned_company_id = Sektorel_Session_Query::get_owned_company_id( $user_id );
        if ( $owned_company_id ) {
            return array(
                'user_id'    => (int) $user_id,
                'company_id' => (int) $owned_company_id,
                'role'       => 'owner',
                'is_owner'   => true,
                'can_edit'   => true,
            );
        }

        $member_company_id = Sektorel_Session_Query::get_member_company_id( $user_id );
        if ( $member_company_id ) {
            $role = sanitize_key( get_user_meta( $user_id, '_sektorel_company_role', true ) ?: 'viewer' );
            if ( ! in_array( $role, array( 'editor', 'viewer' ), true ) ) {
                $role = 'viewer';
            }

            return array(
                'user_id'    => (int) $user_id,
                'company_id' => (int) $member_company_id,
                'role'       => $role,
                'is_owner'   => false,
                'can_edit'   => 'editor' === $role,
            );
        }

        return array(
            'user_id'    => (int) $user_id,
            'company_id' => 0,
            'role'       => 'personal',
            'is_owner'   => false,
            'can_edit'   => true,
        );
    }

    public static function require_context( $write = false ) {
        $context = self::get_context();
        if ( ! $context['user_id'] ) {
            throw new \GraphQL\Error\UserError( 'Bu işlem için giriş yapmanız gerekir.' );
        }

        if ( $write && ! $context['can_edit'] ) {
            throw new \GraphQL\Error\UserError( 'Görüntüleyici rolü içerik oluşturamaz veya değiştiremez.' );
        }

        return $context;
    }

    public static function get_content_company_id( $post, $backfill = true ) {
        if ( ! $post instanceof WP_Post ) {
            $post = get_post( $post );
        }

        if ( ! $post || ! in_array( $post->post_type, self::$content_types, true ) ) {
            return 0;
        }

        $company_id = (int) get_post_meta( $post->ID, '_sektorel_company_id', true );
        if ( $company_id && 'company' === get_post_type( $company_id ) ) {
            return $company_id;
        }

        // Geriye uyumluluk: yalnızca firma sahibinin eski içerikleri otomatik bağlanır.
        // Eski kişisel içeriklerin sonradan firmaya katılan üyeler üzerinden taşınması engellenir.
        $owned_company_id = Sektorel_Session_Query::get_owned_company_id( (int) $post->post_author );
        if ( $owned_company_id ) {
            if ( $backfill ) {
                update_post_meta( $post->ID, '_sektorel_company_id', (int) $owned_company_id );
                if ( ! get_post_meta( $post->ID, '_sektorel_created_by', true ) ) {
                    update_post_meta( $post->ID, '_sektorel_created_by', (int) $post->post_author );
                }
            }
            return (int) $owned_company_id;
        }

        return 0;
    }

    public static function can_view_post( $post, $context = null ) {
        if ( ! $post instanceof WP_Post ) {
            $post = get_post( $post );
        }
        if ( ! $post || ! in_array( $post->post_type, self::$content_types, true ) ) {
            return false;
        }

        $context = is_array( $context ) ? $context : self::get_context();
        if ( ! $context['user_id'] ) {
            return false;
        }

        $post_company_id = self::get_content_company_id( $post );
        if ( $post_company_id ) {
            return $context['company_id'] && (int) $context['company_id'] === (int) $post_company_id;
        }

        return ! $context['company_id'] && (int) $post->post_author === (int) $context['user_id'];
    }

    public static function can_edit_post( $post, $context = null ) {
        $context = is_array( $context ) ? $context : self::get_context();
        return ! empty( $context['can_edit'] ) && self::can_view_post( $post, $context );
    }

    public static function get_accessible_posts( $user_id, $post_types = null, $limit = 100 ) {
        $context = self::get_context( $user_id );
        $post_types = array_values( array_intersect(
            is_array( $post_types ) ? $post_types : self::$content_types,
            self::$content_types
        ) );

        if ( empty( $post_types ) ) {
            return array();
        }

        if ( $context['company_id'] ) {
            $company_posts = get_posts( array(
                'post_type'      => $post_types,
                'post_status'    => self::$content_statuses,
                'posts_per_page' => $limit,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => array(
                    array(
                        'key'     => '_sektorel_company_id',
                        'value'   => (int) $context['company_id'],
                        'compare' => '=',
                        'type'    => 'NUMERIC',
                    ),
                ),
            ) );

            $owner_id = self::get_company_owner_id( $context['company_id'] );
            $legacy_posts = $owner_id ? get_posts( array(
                'post_type'      => $post_types,
                'post_status'    => self::$content_statuses,
                'author'         => $owner_id,
                'posts_per_page' => $limit,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => array(
                    array(
                        'key'     => '_sektorel_company_id',
                        'compare' => 'NOT EXISTS',
                    ),
                ),
            ) ) : array();

            foreach ( $legacy_posts as $legacy_post ) {
                self::get_content_company_id( $legacy_post, true );
            }

            return self::merge_and_sort_posts( array_merge( $company_posts, $legacy_posts ), $limit );
        }

        return get_posts( array(
            'post_type'      => $post_types,
            'post_status'    => self::$content_statuses,
            'author'         => (int) $context['user_id'],
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array(
                    'key'     => '_sektorel_company_id',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        ) );
    }

    public static function count_accessible_posts( $user_id, $post_type ) {
        return count( self::get_accessible_posts( $user_id, array( $post_type ), 500 ) );
    }

    public static function attach_post_to_context( $post_id, $context ) {
        if ( ! get_post_meta( $post_id, '_sektorel_created_by', true ) ) {
            update_post_meta( $post_id, '_sektorel_created_by', (int) $context['user_id'] );
        }
        if ( ! empty( $context['company_id'] ) ) {
            update_post_meta( $post_id, '_sektorel_company_id', (int) $context['company_id'] );
        } else {
            delete_post_meta( $post_id, '_sektorel_company_id' );
        }
    }

    public static function get_company_owner_id( $company_id ) {
        $company = get_post( (int) $company_id );
        return $company && 'company' === $company->post_type ? (int) $company->post_author : 0;
    }

    private static function merge_and_sort_posts( $posts, $limit ) {
        $unique = array();
        foreach ( $posts as $post ) {
            if ( $post instanceof WP_Post ) {
                $unique[ $post->ID ] = $post;
            }
        }

        usort( $unique, function( $left, $right ) {
            return strcmp( $right->post_date_gmt, $left->post_date_gmt );
        } );

        return array_slice( array_values( $unique ), 0, $limit );
    }
}
