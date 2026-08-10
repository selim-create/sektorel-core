<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Candidate_Quality {

    const CLEANUP_CURSOR_OPTION = 'sektorel_candidate_quality_cursor_1262';
    const CLEANUP_DONE_OPTION   = 'sektorel_candidate_quality_done_1262';
    const CLEANUP_BATCH_SIZE    = 100;

    private static $dedupe_lock = false;

    public static function init() {
        // Core 1.26.1 performed a potentially expensive full candidate repair on every
        // admin request until completion. Candidate/event URLs are already normalized
        // lazily during import/save, so remove the global admin_init scan.
        if ( class_exists( 'Sektorel_Event_Candidate_URL_Fix' ) ) {
            remove_action( 'admin_init', array( 'Sektorel_Event_Candidate_URL_Fix', 'repair_existing_imports' ) );
        }

        add_filter( 'wp_insert_post_data', array( __CLASS__, 'decode_post_title_entities' ), 20, 2 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_normalize_candidate' ), 30, 4 );
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_normalize_candidate' ), 30, 4 );

        add_filter( 'bulk_actions-edit-event_candidate', array( __CLASS__, 'bulk_actions' ) );
        add_filter( 'handle_bulk_actions-edit-event_candidate', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
        add_action( 'admin_notices', array( __CLASS__, 'bulk_admin_notice' ) );

        add_action( 'load-edit.php', array( __CLASS__, 'maybe_cleanup_existing_candidates' ) );
    }

    public static function decode_post_title_entities( $data, $postarr ) {
        $post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : '';
        if ( ! in_array( $post_type, array( 'event_candidate', 'event' ), true ) ) {
            return $data;
        }

        if ( isset( $data['post_title'] ) ) {
            $data['post_title'] = self::clean_text( $data['post_title'] );
        }

        return $data;
    }

    public static function maybe_normalize_candidate( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$dedupe_lock || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        if ( ! in_array( $meta_key, array( 'source_id', 'start_date', 'candidate_fingerprint', 'event_url' ), true ) ) {
            return;
        }

        self::normalize_and_dedupe_candidate( absint( $object_id ) );
    }

    public static function bulk_actions( $actions ) {
        $actions['sektorel_convert_candidates'] = 'Seçilenleri Etkinliğe Dönüştür';
        return $actions;
    }

    public static function handle_bulk_action( $redirect_url, $action, $post_ids ) {
        if ( 'sektorel_convert_candidates' !== $action ) {
            return $redirect_url;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return add_query_arg( 'sektorel_bulk_error', 'permission', $redirect_url );
        }

        $converted = 0;
        $existing  = 0;
        $failed    = 0;

        foreach ( array_map( 'absint', (array) $post_ids ) as $candidate_id ) {
            if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
                $failed++;
                continue;
            }

            $result = self::convert_candidate( $candidate_id );
            if ( is_wp_error( $result ) ) {
                $failed++;
            } elseif ( 'existing' === $result ) {
                $existing++;
            } else {
                $converted++;
            }
        }

        return add_query_arg(
            array(
                'sektorel_bulk_converted' => $converted,
                'sektorel_bulk_existing'  => $existing,
                'sektorel_bulk_failed'    => $failed,
            ),
            $redirect_url
        );
    }

    public static function bulk_admin_notice() {
        if ( ! isset( $_GET['sektorel_bulk_converted'] ) ) {
            return;
        }

        $converted = absint( $_GET['sektorel_bulk_converted'] );
        $existing  = isset( $_GET['sektorel_bulk_existing'] ) ? absint( $_GET['sektorel_bulk_existing'] ) : 0;
        $failed    = isset( $_GET['sektorel_bulk_failed'] ) ? absint( $_GET['sektorel_bulk_failed'] ) : 0;

        echo '<div class="notice notice-success is-dismissible"><p>';
        echo 'Toplu dönüşüm tamamlandı. Yeni taslak: <strong>' . esc_html( (string) $converted ) . '</strong>, ';
        echo 'zaten dönüştürülmüş: <strong>' . esc_html( (string) $existing ) . '</strong>, ';
        echo 'hata: <strong>' . esc_html( (string) $failed ) . '</strong>.';
        echo '</p></div>';
    }

    public static function maybe_cleanup_existing_candidates() {
        global $typenow;

        if ( 'event_candidate' !== $typenow || get_option( self::CLEANUP_DONE_OPTION ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $cursor = absint( get_option( self::CLEANUP_CURSOR_OPTION, 0 ) );
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => self::CLEANUP_BATCH_SIZE,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'post__not_in'   => $cursor ? range( 1, $cursor ) : array(),
        ) );

        $last_id = $cursor;
        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            $last_id = max( $last_id, $candidate_id );
            self::normalize_and_dedupe_candidate( $candidate_id );
        }

        if ( count( $ids ) < self::CLEANUP_BATCH_SIZE ) {
            delete_option( self::CLEANUP_CURSOR_OPTION );
            update_option( self::CLEANUP_DONE_OPTION, current_time( 'mysql' ), false );
        } else {
            update_option( self::CLEANUP_CURSOR_OPTION, $last_id, false );
        }
    }

    private static function normalize_and_dedupe_candidate( $candidate_id ) {
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return;
        }

        self::$dedupe_lock = true;

        $clean_title = self::clean_text( get_the_title( $candidate_id ) );
        if ( $clean_title && $clean_title !== get_the_title( $candidate_id ) ) {
            wp_update_post( array( 'ID' => $candidate_id, 'post_title' => $clean_title ) );
        }

        $source_id = absint( get_post_meta( $candidate_id, 'source_id', true ) );
        $start     = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );

        if ( ! $clean_title || ! $source_id || ! $start ) {
            self::$dedupe_lock = false;
            return;
        }

        $canonical_key = self::canonical_key( $source_id, $clean_title, $start );
        update_post_meta( $candidate_id, 'candidate_canonical_key', $canonical_key );

        $matches = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_key'       => 'candidate_canonical_key',
            'meta_value'     => $canonical_key,
        ) );

        if ( count( $matches ) > 1 ) {
            $primary_id = self::choose_primary_candidate( $matches );
            foreach ( $matches as $duplicate_id ) {
                $duplicate_id = absint( $duplicate_id );
                if ( $duplicate_id === $primary_id ) {
                    continue;
                }
                self::merge_candidate_meta( $primary_id, $duplicate_id );
                wp_trash_post( $duplicate_id );
            }
        }

        self::$dedupe_lock = false;
    }

    private static function choose_primary_candidate( $ids ) {
        foreach ( $ids as $id ) {
            if ( (int) get_post_meta( $id, 'imported_event_id', true ) > 0 ) {
                return absint( $id );
            }
        }
        return absint( reset( $ids ) );
    }

    private static function merge_candidate_meta( $primary_id, $duplicate_id ) {
        $keys = array(
            'event_url', 'source_url', 'start_date', 'end_date', 'location_type',
            'venue', 'address', 'organizer', 'registration_link', 'parser_type',
            'imported_event_id', 'source_id', 'candidate_fingerprint',
        );

        foreach ( $keys as $key ) {
            $primary_value   = get_post_meta( $primary_id, $key, true );
            $duplicate_value = get_post_meta( $duplicate_id, $key, true );
            if ( self::is_empty_value( $primary_value ) && ! self::is_empty_value( $duplicate_value ) ) {
                update_post_meta( $primary_id, $key, $duplicate_value );
            }
        }

        $primary_content   = trim( (string) get_post_field( 'post_content', $primary_id ) );
        $duplicate_content = trim( (string) get_post_field( 'post_content', $duplicate_id ) );
        if ( '' === $primary_content && '' !== $duplicate_content ) {
            wp_update_post( array( 'ID' => $primary_id, 'post_content' => $duplicate_content ) );
        }
    }

    private static function convert_candidate( $candidate_id ) {
        self::normalize_and_dedupe_candidate( $candidate_id );

        if ( 'trash' === get_post_status( $candidate_id ) ) {
            return new WP_Error( 'candidate_duplicate', 'Aday duplicate olarak birleştirildi.' );
        }

        $existing_event_id = (int) get_post_meta( $candidate_id, 'imported_event_id', true );
        if ( $existing_event_id && 'event' === get_post_type( $existing_event_id ) ) {
            return 'existing';
        }

        $title = self::clean_text( get_the_title( $candidate_id ) );
        $event_id = wp_insert_post( array(
            'post_type'    => 'event',
            'post_status'  => 'draft',
            'post_title'   => $title,
            'post_content' => get_post_field( 'post_content', $candidate_id ),
        ), true );

        if ( is_wp_error( $event_id ) ) {
            return $event_id;
        }

        $keys = array( 'start_date', 'end_date', 'location_type', 'venue', 'address', 'organizer' );
        foreach ( $keys as $key ) {
            update_post_meta( $event_id, $key, get_post_meta( $candidate_id, $key, true ) );
        }

        update_post_meta( $event_id, 'event_type', 'konferans' );
        update_post_meta( $event_id, 'source_candidate_id', $candidate_id );

        if ( class_exists( 'Sektorel_Event_Candidate_URL_Fix' ) ) {
            // Trigger the existing lazy normalizer without running its removed full admin scan.
            Sektorel_Event_Candidate_URL_Fix::normalize_imported_event( $event_id, get_post( $event_id ) );
        } else {
            $registration = get_post_meta( $candidate_id, 'registration_link', true );
            $source_url   = get_post_meta( $candidate_id, 'event_url', true );
            if ( $registration ) {
                update_post_meta( $event_id, 'registration_link', $registration );
            }
            if ( $source_url ) {
                update_post_meta( $event_id, 'source_url', $source_url );
            }
        }

        update_post_meta( $candidate_id, 'candidate_status', 'imported' );
        update_post_meta( $candidate_id, 'imported_event_id', $event_id );

        return absint( $event_id );
    }

    private static function canonical_key( $source_id, $title, $start ) {
        $normalized_title = strtolower( remove_accents( self::clean_text( $title ) ) );
        $normalized_title = preg_replace( '/[^a-z0-9]+/i', ' ', $normalized_title );
        $normalized_title = trim( preg_replace( '/\s+/', ' ', $normalized_title ) );
        return sha1( absint( $source_id ) . '|' . $normalized_title . '|' . trim( (string) $start ) );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        return sanitize_text_field( $value );
    }

    private static function is_empty_value( $value ) {
        return '' === $value || null === $value || array() === $value;
    }
}
