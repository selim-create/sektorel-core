<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Source_CPT {

    public static function register() {
        register_post_type( 'event_source', array(
            'labels' => array(
                'name'               => 'Etkinlik Kaynakları',
                'singular_name'      => 'Etkinlik Kaynağı',
                'add_new'            => 'Yeni Kaynak',
                'add_new_item'       => 'Yeni Etkinlik Kaynağı',
                'edit_item'          => 'Etkinlik Kaynağını Düzenle',
                'new_item'           => 'Yeni Etkinlik Kaynağı',
                'view_item'          => 'Kaynağı Görüntüle',
                'search_items'       => 'Kaynaklarda Ara',
                'not_found'          => 'Kaynak bulunamadı',
                'menu_name'          => 'Etkinlik Kaynakları',
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=event',
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
