<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Career_CPT {

    public static function register() {
        $labels = array(
            'name'                  => 'İş İlanları',
            'singular_name'         => 'İş İlanı',
            'menu_name'             => 'Kariyer',
            'add_new'               => 'Yeni İlan Ekle',
            'add_new_item'          => 'Yeni İlan Ekle',
            'edit_item'             => 'İlanı Düzenle',
            'new_item'              => 'Yeni İlan',
            'view_item'             => 'İlanı Görüntüle',
            'search_items'          => 'İlan Ara',
            'not_found'             => 'İlan Bulunamadı',
            'not_found_in_trash'    => 'Çöp Kutusunda İlan Yok',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'kariyer' ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 6,
            'menu_icon'          => 'dashicons-businessperson',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author' ),
            'show_in_rest'       => true,
            
            // GraphQL
            'show_in_graphql'     => true,
            'graphql_single_name' => 'Job',
            'graphql_plural_name' => 'Jobs',
        );

        register_post_type( 'career', $args );
    }
}