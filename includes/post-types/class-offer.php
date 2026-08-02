<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Offer_CPT {

    public static function register() {
        $labels = array(
            'name'          => 'Teklifler',
            'singular_name' => 'Teklif',
            'menu_name'     => 'Teklifler',
            'add_new_item'  => 'Yeni Teklif Ekle',
            'edit_item'     => 'Teklifi Düzenle',
            'view_item'     => 'Teklifi Görüntüle',
        );

        register_post_type( 'offer', array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'menu_icon'           => 'dashicons-feedback',
            'supports'            => array( 'title', 'editor', 'author' ),
            'show_in_graphql'     => false,
            'map_meta_cap'        => true,
        ) );
    }
}
