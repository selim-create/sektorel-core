<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Location_Taxonomy {

    public static function register() {
        $labels = array(
            'name'              => 'Lokasyonlar',
            'singular_name'     => 'Lokasyon',
            'menu_name'         => 'Lokasyonlar',
            'search_items'      => 'Lokasyon Ara',
            'all_items'         => 'Tüm Lokasyonlar',
            'parent_item'       => 'Üst Lokasyon (Ülke/Şehir)',
            'parent_item_colon' => 'Üst Lokasyon:',
            'edit_item'         => 'Lokasyonu Düzenle',
            'update_item'       => 'Lokasyonu Güncelle',
            'add_new_item'      => 'Yeni Lokasyon Ekle',
            'new_item_name'     => 'Yeni Lokasyon Adı',
            'not_found'         => 'Lokasyon Bulunamadı',
            'desc'              => 'Ülke > Şehir > İlçe hiyerarşisini kullanın.',
        );

        $args = array(
            'hierarchical'      => true, // Bu ayar Parent/Child yapısını açar
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'konum' ),
            'show_in_graphql'     => true,
            'graphql_single_name' => 'Location',
            'graphql_plural_name' => 'Locations',
        );

        register_taxonomy( 'location', array( 'company', 'lead', 'event', 'post' ), $args );
    }
}