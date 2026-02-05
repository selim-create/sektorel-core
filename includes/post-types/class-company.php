<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_CPT {

    public static function register() {
        $labels = array(
            'name'                  => 'Firmalar',
            'singular_name'         => 'Firma',
            'menu_name'             => 'Firmalar',
            'add_new'               => 'Yeni Firma Ekle',
            'add_new_item'          => 'Yeni Firma Ekle',
            'edit_item'             => 'Firmayı Düzenle',
            'new_item'              => 'Yeni Firma',
            'view_item'             => 'Firmayı Görüntüle',
            'search_items'          => 'Firma Ara',
            'not_found'             => 'Firma Bulunamadı',
            'not_found_in_trash'    => 'Çöp Kutusunda Firma Yok',
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'firma' ), // URL yapısı: /firma/firma-adi
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 5,
            'menu_icon'          => 'dashicons-building',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author' ),
            'show_in_rest'       => true, // Gutenberg editörü için
            
            // GraphQL Ayarları
            'show_in_graphql'     => true,
            'graphql_single_name' => 'Company',
            'graphql_plural_name' => 'Companies',
        );

        register_post_type( 'company', $args );
    }
}