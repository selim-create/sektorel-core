<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Directory_Facets {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelDirectoryFacet', array(
            'description' => 'Firma dizini için sektör veya şehir facet sonucu.',
            'fields'      => array(
                'databaseId' => array( 'type' => 'Int' ),
                'name'       => array( 'type' => 'String' ),
                'slug'       => array( 'type' => 'String' ),
                'type'       => array( 'type' => 'String' ),
                'count'      => array( 'type' => 'Int' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelDirectoryFacets', array(
            'type'        => array( 'list_of' => 'SektorelDirectoryFacet' ),
            'description' => 'Şehir için firma bulunan sektörleri veya sektör için firma bulunan şehirleri getirir.',
            'args'        => array(
                'location' => array( 'type' => 'String' ),
                'sector'   => array( 'type' => 'String' ),
                'first'    => array( 'type' => 'Int', 'defaultValue' => 24 ),
            ),
            'resolve'     => function( $root, $args ) {
                return self::resolve_facets( $args );
            },
        ) );
    }

    private static function resolve_facets( $args ) {
        $location = sanitize_title( $args['location'] ?? '' );
        $sector   = sanitize_title( $args['sector'] ?? '' );
        $first    = min( max( (int) ( $args['first'] ?? 24 ), 1 ), 100 );

        if ( ( '' === $location && '' === $sector ) || ( '' !== $location && '' !== $sector ) ) {
            return array();
        }

        $tax_query = array();
        if ( '' !== $location ) {
            $tax_query[] = array(
                'taxonomy'         => 'location',
                'field'            => 'slug',
                'terms'            => array( $location ),
                'include_children' => true,
            );
        }
        if ( '' !== $sector ) {
            $tax_query[] = array(
                'taxonomy'         => 'sector',
                'field'            => 'slug',
                'terms'            => array( $sector ),
                'include_children' => true,
            );
        }

        $query = new WP_Query( array(
            'post_type'              => 'company',
            'post_status'            => 'publish',
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => true,
            'tax_query'              => $tax_query,
        ) );

        if ( empty( $query->posts ) ) {
            return array();
        }

        $counts = '' !== $location
            ? self::aggregate_sectors( $query->posts )
            : self::aggregate_cities( $query->posts );

        uasort( $counts, static function( $a, $b ) {
            if ( $a['count'] === $b['count'] ) {
                return strcasecmp( $a['name'], $b['name'] );
            }
            return $b['count'] <=> $a['count'];
        } );

        return array_slice( array_values( $counts ), 0, $first );
    }

    private static function aggregate_sectors( $company_ids ) {
        $counts = array();

        foreach ( $company_ids as $company_id ) {
            $terms = get_the_terms( (int) $company_id, 'sector' );
            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                continue;
            }

            $seen = array();
            foreach ( $terms as $term ) {
                if ( isset( $seen[ $term->term_id ] ) ) {
                    continue;
                }
                $seen[ $term->term_id ] = true;

                if ( ! isset( $counts[ $term->term_id ] ) ) {
                    $counts[ $term->term_id ] = array(
                        'databaseId' => (int) $term->term_id,
                        'name'       => (string) $term->name,
                        'slug'       => (string) $term->slug,
                        'type'       => 'sector',
                        'count'      => 0,
                    );
                }
                $counts[ $term->term_id ]['count']++;
            }
        }

        return $counts;
    }

    private static function aggregate_cities( $company_ids ) {
        $counts = array();

        foreach ( $company_ids as $company_id ) {
            $terms = get_the_terms( (int) $company_id, 'location' );
            if ( empty( $terms ) || is_wp_error( $terms ) ) {
                continue;
            }

            $seen = array();
            foreach ( $terms as $term ) {
                $city = self::resolve_city_term( $term );
                if ( ! $city || isset( $seen[ $city->term_id ] ) ) {
                    continue;
                }
                $seen[ $city->term_id ] = true;

                if ( ! isset( $counts[ $city->term_id ] ) ) {
                    $counts[ $city->term_id ] = array(
                        'databaseId' => (int) $city->term_id,
                        'name'       => (string) $city->name,
                        'slug'       => (string) $city->slug,
                        'type'       => 'city',
                        'count'      => 0,
                    );
                }
                $counts[ $city->term_id ]['count']++;
            }
        }

        return $counts;
    }

    private static function resolve_city_term( $term ) {
        $current = $term;
        $guard   = 0;

        while ( $current && ! is_wp_error( $current ) && $guard < 10 ) {
            if ( 'city' === get_term_meta( $current->term_id, 'location_type', true ) ) {
                return $current;
            }

            if ( empty( $current->parent ) ) {
                break;
            }

            $current = get_term( (int) $current->parent, 'location' );
            $guard++;
        }

        return null;
    }
}
