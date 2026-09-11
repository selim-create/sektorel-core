<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Private source registry for the AI-supported Content Source Engine.
 */
class Sektorel_Content_Source_CPT {

    public static function register() {
        register_post_type( 'content_source', array(
            'labels' => array(
                'name'               => 'İçerik Kaynakları',
                'singular_name'      => 'İçerik Kaynağı',
                'add_new'            => 'Yeni Kaynak',
                'add_new_item'       => 'Yeni İçerik Kaynağı',
                'edit_item'          => 'İçerik Kaynağını Düzenle',
                'new_item'           => 'Yeni İçerik Kaynağı',
                'view_item'          => 'Kaynağı Görüntüle',
                'search_items'       => 'Kaynaklarda Ara',
                'not_found'          => 'Kaynak bulunamadı',
                'menu_name'          => 'İçerik Kaynakları',
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php',
            'show_in_rest'        => false,
            'supports'            => array( 'title' ),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'rewrite'             => false,
            'query_var'           => false,
            'menu_icon'           => 'dashicons-rss',
        ) );
    }
}
