<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Lead_CPT {

    public static function register() {
        $labels = array(
            'name'          => 'Talepler & İlanlar',
            'singular_name' => 'Talep',
            'menu_name'     => 'Talepler',
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'show_ui'             => true,
            'menu_icon'           => 'dashicons-megaphone',
            'supports'            => array( 'title', 'editor', 'author' ),
            'rewrite'             => array( 'slug' => 'firsat' ),
            
            // GraphQL
            'show_in_graphql'     => true,
            'graphql_single_name' => 'Lead',
            'graphql_plural_name' => 'Leads',
        );

        register_post_type( 'lead', $args );
    }
}