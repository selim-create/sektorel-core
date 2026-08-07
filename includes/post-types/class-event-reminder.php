<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Reminder_CPT {

    public static function register() {
        register_post_type( 'event_reminder', array(
            'labels' => array(
                'name'          => 'Etkinlik Hatırlatmaları',
                'singular_name' => 'Etkinlik Hatırlatması',
            ),
            'public'          => false,
            'show_ui'         => false,
            'show_in_menu'    => false,
            'show_in_rest'    => false,
            'supports'        => array( 'title', 'author' ),
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ) );
    }
}
