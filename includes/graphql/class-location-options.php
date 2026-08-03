<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Location_Options {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelLocationOption', array(
            'fields' => array(
                'databaseId' => array( 'type' => 'Int' ),
                'name'       => array( 'type' => 'String' ),
                'slug'       => array( 'type' => 'String' ),
                'type'       => array( 'type' => 'String' ),
                'parentId'   => array( 'type' => 'Int' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelLocationOptions', array(
            'type'        => array( 'list_of' => 'SektorelLocationOption' ),
            'description' => 'Lokasyon seçeneklerini tip, üst lokasyon ve arama metnine göre getirir.',
            'args'        => array(
                'type'       => array( 'type' => 'String' ),
                'parentSlug' => array( 'type' => 'String' ),
                'search'     => array( 'type' => 'String' ),
                'first'      => array( 'type' => 'Int', 'defaultValue' => 100 ),
            ),
            'resolve'     => function( $root, $args ) {
                $type = sanitize_key( $args['type'] ?? '' );
                $search = sanitize_text_field( $args['search'] ?? '' );
                $first = min( max( (int) ( $args['first'] ?? 100 ), 1 ), 200 );
                $parent = 0;

                if ( ! empty( $args['parentSlug'] ) ) {
                    $parent_term = get_term_by( 'slug', sanitize_title( $args['parentSlug'] ), 'location' );
                    if ( ! $parent_term ) {
                        return array();
                    }
                    $parent = (int) $parent_term->term_id;
                }

                $query_args = array(
                    'taxonomy'   => 'location',
                    'hide_empty' => false,
                    'number'     => $first,
                    'orderby'    => 'name',
                    'order'      => 'ASC',
                );

                if ( $search ) {
                    $query_args['search'] = $search;
                }

                if ( array_key_exists( 'parentSlug', $args ) ) {
                    $query_args['parent'] = $parent;
                }

                if ( in_array( $type, array( 'country', 'city', 'district' ), true ) ) {
                    $query_args['meta_query'] = array(
                        array(
                            'key'   => 'location_type',
                            'value' => $type,
                        ),
                    );
                }

                $terms = get_terms( $query_args );
                if ( is_wp_error( $terms ) ) {
                    return array();
                }

                return array_map(
                    function( $term ) {
                        return array(
                            'databaseId' => (int) $term->term_id,
                            'name'       => (string) $term->name,
                            'slug'       => (string) $term->slug,
                            'type'       => (string) get_term_meta( $term->term_id, 'location_type', true ),
                            'parentId'   => (int) $term->parent,
                        );
                    },
                    $terms
                );
            },
        ) );
    }
}
