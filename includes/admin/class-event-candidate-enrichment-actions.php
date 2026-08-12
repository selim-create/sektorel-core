<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keeps enrichment-only candidates from presenting a misleading create-event
 * row action. The source-role guard remains the authoritative safety layer;
 * this class only aligns the admin UI with that policy.
 */
class Sektorel_Event_Candidate_Enrichment_Actions {

    public static function init() {
        add_filter( 'post_row_actions', array( __CLASS__, 'filter_row_actions' ), 200, 2 );
    }

    public static function filter_row_actions( $actions, $post ) {
        if ( ! $post || 'event_candidate' !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
            return $actions;
        }

        if ( ! class_exists( 'Sektorel_Event_Source_Role' ) || Sektorel_Event_Source_Role::can_candidate_create_event( $post->ID ) ) {
            return $actions;
        }

        unset( $actions['import_event'] );

        $matched_event_id = absint( get_post_meta( $post->ID, 'matched_event_id', true ) );
        if ( $matched_event_id && 'event' === get_post_type( $matched_event_id ) ) {
            $actions['enrichment_match'] = '<a href="' . esc_url( get_edit_post_link( $matched_event_id, 'url' ) ) . '">Eşleşen Etkinliği Aç</a>';
        } else {
            $actions['enrichment_waiting'] = '<span style="color:#996800;">Eşleşme gerekli</span>';
        }

        return $actions;
    }
}
