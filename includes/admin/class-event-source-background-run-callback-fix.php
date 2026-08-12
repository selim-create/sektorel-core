<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hotfix for Core 1.38.0.
 *
 * The background runner registered a private static method directly as an
 * `admin_footer` callback. WordPress invokes callbacks from outside the class
 * scope, so PHP rejects the private method at runtime. Keep the worker logic
 * untouched and route the footer hook through a public compatibility wrapper.
 */
class Sektorel_Event_Source_Background_Run_Callback_Fix {

    public static function init() {
        remove_action(
            'admin_footer',
            array( 'Sektorel_Event_Source_Background_Run', 'render_source_center_script' ),
            120
        );

        add_action( 'admin_footer', array( __CLASS__, 'render_source_center_script' ), 120 );
    }

    public static function render_source_center_script() {
        if ( ! class_exists( 'Sektorel_Event_Source_Background_Run' ) ) {
            return;
        }

        $runner = function() {
            Sektorel_Event_Source_Background_Run::render_source_center_script();
        };

        $runner = Closure::bind(
            $runner,
            null,
            'Sektorel_Event_Source_Background_Run'
        );

        if ( $runner instanceof Closure ) {
            $runner();
        }
    }
}
