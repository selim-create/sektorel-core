<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Directory {

    const DEFAULT_PER_PAGE = 24;
    const MAX_PER_PAGE     = 48;

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelCompanyDirectoryResult', array(
            'description' => 'Filtrelenmiş firma rehberi sonucu ve sayfalama bilgileri.',
            'fields'      => array(
                'nodes'           => array( 'type' => array( 'list_of' => 'Company' ) ),
                'total'           => array( 'type' => 'Int' ),
                'page'            => array( 'type' => 'Int' ),
                'perPage'         => array( 'type' => 'Int' ),
                'totalPages'      => array( 'type' => 'Int' ),
                'hasNextPage'     => array( 'type' => 'Boolean' ),
                'hasPreviousPage' => array( 'type' => 'Boolean' ),
            ),
        ) );

        register_graphql_field( 'Company', 'sektorelViewCount', array(
            'type'        => 'Int',
            'description' => 'Firma profilinin toplam görüntülenme sayısı.',
            'resolve'     => function( $source ) {
                $company_id = self::source_database_id( $source );
                return $company_id ? (int) get_post_meta( $company_id, 'view_count', true ) : 0;
            },
        ) );

        register_graphql_field( 'RootQuery', 'sektorelCompanyDirectory', array(
            'type'        => 'SektorelCompanyDirectoryResult',
            'description' => 'Firma rehberini arama, sektör, lokasyon, doğrulama ve sıralama kriterleriyle getirir.',
            'args'        => array(
                'search'   => array( 'type' => 'String' ),
                'sector'   => array( 'type' => 'String' ),
                'location' => array( 'type' => 'String' ),
                'verified' => array( 'type' => 'Boolean', 'defaultValue' => false ),
                'sort'     => array( 'type' => 'String', 'defaultValue' => 'newest' ),
                'page'     => array( 'type' => 'Int', 'defaultValue' => 1 ),
                'first'    => array( 'type' => 'Int', 'defaultValue' => self::DEFAULT_PER_PAGE ),
            ),
            'resolve'     => function( $root, $args, $context ) {
                return self::resolve_directory( $args, $context );
            },
        ) );
    }

    private static function resolve_directory( $args, $context ) {
        $search   = sanitize_text_field( $args['search'] ?? '' );
        $sector   = sanitize_title( $args['sector'] ?? '' );
        $location = sanitize_title( $args['location'] ?? '' );
        $verified = ! empty( $args['verified'] );
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = min(
            max( 1, (int) ( $args['first'] ?? self::DEFAULT_PER_PAGE ) ),
            self::MAX_PER_PAGE
        );

        $allowed_sorts = array( 'newest', 'oldest', 'alphabetical', 'verified', 'views' );
        $sort = sanitize_key( $args['sort'] ?? 'newest' );
        if ( ! in_array( $sort, $allowed_sorts, true ) ) {
            $sort = 'newest';
        }

        $query_args = array(
            'post_type'           => 'company',
            'post_status'         => 'publish',
            'posts_per_page'      => $per_page,
            'paged'               => $page,
            'ignore_sticky_posts' => true,
            'no_found_rows'       => false,
            'fields'              => 'ids',
        );

        if ( '' !== $search ) {
            $query_args['s'] = $search;
        }

        $tax_query = array( 'relation' => 'AND' );
        if ( '' !== $sector ) {
            $tax_query[] = array(
                'taxonomy'         => 'sector',
                'field'            => 'slug',
                'terms'            => array( $sector ),
                'include_children' => true,
            );
        }
        if ( '' !== $location ) {
            $tax_query[] = array(
                'taxonomy'         => 'location',
                'field'            => 'slug',
                'terms'            => array( $location ),
                'include_children' => true,
            );
        }
        if ( count( $tax_query ) > 1 ) {
            $query_args['tax_query'] = $tax_query;
        }

        if ( $verified ) {
            $query_args['meta_query'] = array(
                array(
                    'key'     => 'is_verified',
                    'value'   => 1,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
            );
        }

        $sort_meta_key = '';
        switch ( $sort ) {
            case 'oldest':
                $query_args['orderby'] = 'date';
                $query_args['order']   = 'ASC';
                break;
            case 'alphabetical':
                $query_args['orderby'] = 'title';
                $query_args['order']   = 'ASC';
                break;
            case 'verified':
                $sort_meta_key = 'is_verified';
                break;
            case 'views':
                $sort_meta_key = 'view_count';
                break;
            case 'newest':
            default:
                $query_args['orderby'] = 'date';
                $query_args['order']   = 'DESC';
                break;
        }

        $clauses_filter = null;
        if ( $sort_meta_key ) {
            $query_args['_sektorel_directory_sort'] = $sort_meta_key;
            $clauses_filter = function( $clauses, $query ) use ( $sort_meta_key ) {
                if ( $query->get( '_sektorel_directory_sort' ) !== $sort_meta_key ) {
                    return $clauses;
                }

                global $wpdb;
                $alias = 'sektorel_directory_sort_meta';
                $clauses['join'] .= $wpdb->prepare(
                    " LEFT JOIN {$wpdb->postmeta} AS {$alias} ON ({$wpdb->posts}.ID = {$alias}.post_id AND {$alias}.meta_key = %s)",
                    $sort_meta_key
                );
                $clauses['groupby'] = "{$wpdb->posts}.ID";
                $clauses['orderby'] = "CAST(COALESCE(NULLIF({$alias}.meta_value, ''), '0') AS UNSIGNED) DESC, {$wpdb->posts}.post_date DESC";
                return $clauses;
            };
            add_filter( 'posts_clauses', $clauses_filter, 10, 2 );
        }

        $query = new WP_Query( $query_args );

        if ( $clauses_filter ) {
            remove_filter( 'posts_clauses', $clauses_filter, 10 );
        }

        $total       = (int) $query->found_posts;
        $total_pages = (int) $query->max_num_pages;
        $post_loader = $context->get_loader( 'post' );
        $nodes       = array_map(
            static function( $post_id ) use ( $post_loader ) {
                return $post_loader->load_deferred( (int) $post_id );
            },
            array_map( 'intval', $query->posts )
        );

        return array(
            'nodes'           => $nodes,
            'total'           => $total,
            'page'            => $page,
            'perPage'         => $per_page,
            'totalPages'      => $total_pages,
            'hasNextPage'     => $page < $total_pages,
            'hasPreviousPage' => $page > 1 && $total_pages > 0,
        );
    }

    private static function source_database_id( $source ) {
        if ( is_object( $source ) ) {
            if ( isset( $source->databaseId ) ) {
                return (int) $source->databaseId;
            }
            if ( isset( $source->ID ) ) {
                return (int) $source->ID;
            }
        }

        if ( is_array( $source ) ) {
            return (int) ( $source['databaseId'] ?? $source['ID'] ?? 0 );
        }

        return 0;
    }
}
