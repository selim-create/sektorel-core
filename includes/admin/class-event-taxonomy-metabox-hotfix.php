<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Core 1.35.4 hotfix.
 *
 * WordPress hierarchical taxonomy meta boxes use `{$taxonomy}div` IDs, not
 * `{$taxonomy}-div`. Core 1.35.3 therefore left the legacy Sector/Location
 * checklists in place next to the new searchable selectors. With the large
 * location hierarchy that made event edit screens very slow and extremely
 * long.
 *
 * Remove the legacy boxes at the final do_meta_boxes stage, after WordPress
 * and plugins have had a chance to register them. The searchable selector
 * meta boxes remain untouched and taxonomy data/relationships are unchanged.
 */
class Sektorel_Event_Taxonomy_Metabox_Hotfix {

    public static function init() {
        add_action( 'do_meta_boxes', array( __CLASS__, 'remove_legacy_boxes' ), 999, 3 );
    }

    public static function remove_legacy_boxes( $post_type, $context, $post ) {
        if ( 'event' !== $post_type || 'side' !== $context ) {
            return;
        }

        remove_meta_box( 'sectordiv', 'event', 'side' );
        remove_meta_box( 'locationdiv', 'event', 'side' );

        // Defensive fallbacks for non-hierarchical/custom callbacks.
        remove_meta_box( 'tagsdiv-sector', 'event', 'side' );
        remove_meta_box( 'tagsdiv-location', 'event', 'side' );
    }
}
