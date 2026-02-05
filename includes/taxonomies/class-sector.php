<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Sector_Taxonomy {

    public static function register() {
        $labels = array(
            'name'              => 'Sektörler',
            'singular_name'     => 'Sektör',
            'search_items'      => 'Sektör Ara',
            'all_items'         => 'Tüm Sektörler',
            'parent_item'       => 'Üst Sektör',
            'parent_item_colon' => 'Üst Sektör:',
            'edit_item'         => 'Sektörü Düzenle',
            'update_item'       => 'Sektörü Güncelle',
            'add_new_item'      => 'Yeni Sektör Ekle',
            'menu_name'         => 'Sektörler',
        );

        $args = array(
            'hierarchical'      => true, // Kategori gibi alt alta olabilir
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array( 'slug' => 'sektor' ),
            
            // GraphQL
            'show_in_graphql'     => true,
            'graphql_single_name' => 'Sector',
            'graphql_plural_name' => 'Sectors',
        );

        // Bu taksonomiyi Firmalar, Talepler ve Etkinlikler ile ilişkilendir
        register_taxonomy( 'sector', array( 'company', 'lead', 'event' ), $args );
    }
}