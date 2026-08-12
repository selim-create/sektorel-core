<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keeps matched Tüyap records with non-empty source conflicts visible in the
 * candidate review inbox while still allowing imported_event_id evidence to be
 * attached to the matched Event.
 */
class Sektorel_Event_Source_Tuyap_Conflict_Review {

    private static $lock = false;

    public static function init() {
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_mark_for_review' ), 140, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_mark_for_review' ), 140, 4 );
    }

    public static function maybe_mark_for_review( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$lock || 'tuyap_conflicts' !== $meta_key || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        $candidate_id = absint( $object_id );
        $conflicts    = is_array( $meta_value ) ? array_values( array_filter( array_map( 'sanitize_key', $meta_value ) ) ) : array();
        if ( ! $candidate_id || ! $conflicts ) {
            return;
        }

        self::$lock = true;
        update_post_meta( $candidate_id, 'candidate_status', 'incomplete' );
        update_post_meta( $candidate_id, 'candidate_resolution', 'source_conflict' );
        update_post_meta( $candidate_id, 'candidate_conflict_source', 'tuyap' );
        update_post_meta( $candidate_id, 'candidate_conflict_fields', $conflicts );
        update_post_meta( $candidate_id, 'candidate_conflict_detected_at', current_time( 'mysql' ) );
        self::$lock = false;
    }
}
