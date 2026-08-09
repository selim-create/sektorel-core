<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Candidate_CPT {

    public static function register() {
        register_post_type( 'event_candidate', array(
            'labels' => array(
                'name'               => 'Aday Etkinlikler',
                'singular_name'      => 'Aday Etkinlik',
                'add_new'            => 'Yeni Aday',
                'add_new_item'       => 'Yeni Aday Etkinlik',
                'edit_item'          => 'Aday Etkinliği İncele',
                'new_item'           => 'Yeni Aday Etkinlik',
                'view_item'          => 'Aday Etkinliği Görüntüle',
                'search_items'       => 'Aday Etkinliklerde Ara',
                'not_found'          => 'Aday etkinlik bulunamadı',
                'menu_name'          => 'Aday Etkinlikler',
            ),
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=event',
            'show_in_rest'        => false,
            'supports'            => array( 'title', 'editor' ),
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'rewrite'             => false,
            'query_var'           => false,
            'menu_icon'           => 'dashicons-visibility',
        ) );
    }
}
