<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Owned_Content {

    private static $allowed_types = array( 'lead', 'career', 'event' );

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
       