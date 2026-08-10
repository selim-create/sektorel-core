<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Candidate_Filter_Safety {

    public static function init() {
        add_action( 'load-edit.php', array( __CLASS__, 'enable_read_only_filter_mode' ), 1 );
    }

    public static function enable_read_only_filter_mode() {
        global $typenow;

        if ( 'event_candidate' !== $typenow || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! self::has_candidate_filter() ) {
            return;
        }

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

    private static function has_candidate_filter() {
        $keys = array(
            'candidate_confidence',
            'candidate_match_status',
            'candidate_parser',
            'candidate_quality',
            's',
            'm',
        );

        foreach ( $keys as $key ) {
            if ( ! isset( $_GET[ $key ] ) ) {
                continue;
            }

            $value = wp_unslash( $_GET[ $key ] );
            if ( is_array( $value ) ) {
                $value = implode( '', array_map( 'sanitize_text_field', $value ) );
            } else {
                $value = sanitize_text_field( (string) $value );
            }

            if ( '' !== trim( $value ) && '0' !== trim( $value ) ) {
                return true;
            }
        }

        return false;
    }
}
