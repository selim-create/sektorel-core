<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Job_Application_CPT {

    public static function register() {
        $labels = array(
            'name'               => 'İş Başvuruları',
            'singular_name'      => 'İş Başvurusu',
            'menu_name'          => 'Başvurular',
            'all_items'          => 'Tüm Başvurular',
            'edit_item'          => 'Başvuruyu İncele',
            'view_item'          => 'Başvuruyu Görüntüle',
            'search_items'       => 'Başvuru Ara',
            'not_found'          => 'Başvuru bulunamadı',
            'not_found_in_trash' => 'Çöp kutusunda başvuru yok',
        );

        register_post_type( 'job_application', array(
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=career',
            'show_in_admin_bar'   => false,
            'show_in_nav_menus'   => false,
            'show_in_rest'        => false,
            'show_in_graphql'     => false,
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => array( 'title', 'author' ),
        ) );
    }
}
