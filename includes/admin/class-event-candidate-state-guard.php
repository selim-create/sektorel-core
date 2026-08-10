<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keeps explicit admin resolutions stable across later source scans.
 * Also disables the old blind bulk-convert action while ES-5 classification is active.
 */
class Sektorel_Event_Candidate_State_Guard {

    private static $restoring = false;

    public static function init() {
        add_action( 'updated_post_meta', array( __CLASS__, 'preserve_resolution' ), 100, 4 );
        add_action( 'added_post_meta', array( __CLASS__, 'preserve_resolution' ), 100, 4 );
        add_filter( 'bulk_actions-edit-event_candidate', array( __CLASS__, 'disable_blind_bulk_convert' ), 100 );
    }

    public static function preserve_resolution( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$restoring || 'candidate_status' !== $meta_key || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        $resolution = (string) get_post_meta( $object_id, 'candidate_resolution', true );
        $imported   = absint( get_post_meta( $object_id, 'imported_event_id', true ) );
        $expected   = '';

        if ( 'ignored' === $resolution ) {
            $expected = 'ignored';
        } elseif ( $imported && 'event' === get_post_type( $imported ) ) {
            $expected = 'imported';
        }

        if ( ! $expected || $expected === (string) $meta_value ) {
            return;
        }

        self::$restoring = true;
        update_post_meta( $object_id, 'candidate_status', $expected );
        self::$restoring = false;
    }

    public static function disable_blind_bulk_convert( $actions ) {
        unset( $actions['sektorel_convert_candidates'] );
        return $actions;
    }
}
