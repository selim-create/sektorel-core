<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_CPT {

    public static function register() {
        $labels = array(
            'name'          => 'Etkinlikler',
            'singular_name' => 'Etkinlik',
            'menu_name'     => 'Ajanda',
        );

        $args = array(
            'labels'              => $labels,
            'public'              => true,
            'show_ui'             => true,
            'menu_icon'           => 'dashicons-calendar-alt',
            'supports'            => array( 'title', 'editor', 'thumbnail' ),
            'rewrite'             => array( 'slug' => 'etkinlik' ),
            
            // GraphQL
            'show_in_graphql'     => true,
            'graphql_single_name' => 'Event',
            'graphql_plural_name' => 'Events',
        );

        register_post_type( 'event', $args );
    }
}