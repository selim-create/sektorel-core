<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keep the Event Candidate list read-only.
 *
 * Candidate classification and cleanup now belong to the explicit Source Center
 * pipeline. Merely opening the admin list must never mutate archived/resolved
 * candidate state. This also preserves the previous filtered-list safety mode.
 */
class Sektorel_Event_Candidate_Filter_Safety {

    public static function init() {
        add_action( 'load-edit.php', array( __CLASS__, 'enable_read_only_filter_mode' ), 1 );
    }

    public static function enable_read_only_filter_mode() {
        global $typenow;

        if ( 'event_candidate' !== $typenow || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Source Center owns deterministic matching/maintenance. The candidate
        // list is an operational review surface and must not rewrite status just
        // because an administrator opened or refreshed it.
        if ( class_exists( 'Sektorel_Event_Candidate_Quality' ) ) {
            remove_action( 'load-edit.php', array( 'Sektorel_Event_Candidate_Quality', 'maybe_cleanup_existing_candidates' ) );
        }
        if ( class_exists( 'Sektorel_Event_Candidate_Matcher' ) ) {
            remove_action( 'load-edit.php', array( 'Sektorel_Event_Candidate_Matcher', 'classify_candidates_batch' ), 20 );
        }
        if ( class_exists( 'Sektorel_Event_Candidate_Confidence' ) ) {
            remove_action( 'load-edit.php', array( 'Sektorel_Event_Candidate_Confidence', 'classify_existing_batch' ), 26 );
        }
        if ( class_exists( 'Sektorel_Event_Content_Quality' ) ) {
            remove_action( 'load-edit.php', array( 'Sektorel_Event_Content_Quality', 'cleanup_existing_records' ), 50 );
        }
        if ( class_exists( 'Sektorel_Event_Candidate_Field_Quality' ) ) {
            remove_action( 'load-edit.php', array( 'Sektorel_Event_Candidate_Field_Quality', 'cleanup_existing_candidates' ), 65 );
        }
    }
}
