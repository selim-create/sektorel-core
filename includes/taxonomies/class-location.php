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

        // Rank Math Pro Link Genius builds its General Settings JSON by querying
        // terms for every UI-visible taxonomy attached to every public post type.
        // With tens of thousands of hierarchical location terms, WordPress can
        // spend enough time preparing the hierarchy to stall authenticated
        // wp-admin requests even though Rank Math requests only a small page of
        // terms. Keep the taxonomy hierarchical everywhere else, but avoid that
        // hierarchy expansion for this one settings-time lookup.
        if ( is_admin() && false === has_filter( 'get_terms_args', array( __CLASS__, 'optimize_rank_math_term_query' ) ) ) {
            add_filter( 'get_terms_args', array( __CLASS__, 'optimize_rank_math_term_query' ), 10, 2 );
        }
    }

    public static function optimize_rank_math_term_query( $args, $taxonomies ) {
        if ( ! is_admin() || ! doing_filter( 'rank_math/settings/general' ) ) {
            return $args;
        }

        if ( ! in_array( 'location', (array) $taxonomies, true ) ) {
            return $args;
        }

        $args['hierarchical'] = false;
        $args['pad_counts']   = false;

        return $args;
    }
}
