<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Review_Expiry {

    public static function eligible( $candidate_id ) {
        $candidate_id = absint( $candidate_id );
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) || 'trash' === get_post_status( $candidate_id ) ) {
            return false;
        }
        if ( ! class_exists( 'Sektorel_Event_Source_Role' ) || ! in_array( Sektorel_Event_Source_Role::role_for_candidate( $candidate_id ), array( 'discovery', 'canonical_registry' ), true ) ) {
            return false;
        }
        $status = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
        if ( ! in_array( $status, array( 'new', 'incomplete' ), true ) ) {
            return false;
        }
        if ( absint( get_post_meta( $candidate_id, 'matched_event_id', true ) ) || absint( get_post_meta( $candidate_id, 'imported_event_id', true ) ) ) {
            return false;
        }
        $start = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
        $end   = self::date_part( get_post_meta( $candidate_id, 'end_date', true ) );
        $last  = $end ?: $start;
        return $last && $last < current_time( 'Y-m-d' );
    }

    public static function resolve( $candidate_id ) {
        if ( ! self::eligible( $candidate_id ) ) {
            return false;
        }
        update_post_meta( $candidate_id, 'candidate_status', 'expired' );
        update_post_meta( $candidate_id, 'candidate_resolution', 'past_unmatched_occurrence' );
        update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
        delete_post_meta( $candidate_id, 'candidate_match_signature' );
        return true;
    }

    private static function date_part( $value ) {
        return preg_match( '/^(\d{4}-\d{2}-\d{2})/', (string) $value, $m ) ? $m[1] : '';
    }
}
