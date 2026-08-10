<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ES-5 candidate matching / change detection.
 *
 * Candidates are never published automatically. This layer only classifies
 * candidates against existing event posts and exposes explicit admin actions.
 */
class Sektorel_Event_Candidate_Matcher {

    const NONCE_ACTION    = 'sektorel_event_candidate_match';
    const ENGINE_VERSION  = '1280';
    const MATCH_THRESHOLD = 75;
    const REVIEW_THRESHOLD = 65;
    const BATCH_SIZE      = 150;
    const EVENT_REVISION_OPTION = 'sektorel_event_match_revision';

    private static $event_index = null;

    public static function init() {
        add_action( 'load-edit.php', array( __CLASS__, 'classify_candidates_batch' ), 20 );
        add_action( 'save_post_event', array( __CLASS__, 'bump_event_revision' ), 90, 2 );

        add_filter( 'manage_event_candidate_posts_columns', array( __CLASS__, 'columns' ), 30 );
        add_action( 'manage_event_candidate_posts_custom_column', array( __CLASS__, 'render_column' ), 30, 2 );
        add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 50, 2 );

        add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_filters' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 30 );

        add_action( 'admin_post_sektorel_update_event_candidate', array( __CLASS__, 'handle_update_candidate' ) );
        add_action( 'admin_post_sektorel_ignore_event_candidate', array( __CLASS__, 'handle_ignore_candidate' ) );
        add_action( 'admin_post_sektorel_recheck_event_candidate', array( __CLASS__, 'handle_recheck_candidate' ) );
    }

    public static function bump_event_revision( $post_id, $post ) {
        if ( ! $post || 'event' !== $post->post_type || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $revision = absint( get_option( self::EVENT_REVISION_OPTION, 1 ) );
        update_option( self::EVENT_REVISION_OPTION, $revision + 1, false );
        self::$event_index = null;
    }

    public static function classify_candidates_batch() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        if ( 'event_candidate' !== $post_type ) {
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        if ( ! $ids ) {
            return;
        }

        update_meta_cache( 'post', $ids );
        $revision = absint( get_option( self::EVENT_REVISION_OPTION, 1 ) );
        $pending  = array();

        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
            if ( in_array( $status, array( 'imported', 'ignored' ), true ) ) {
                continue;
            }

            $signature = self::candidate_signature( $candidate_id, $revision );
            if ( $signature === (string) get_post_meta( $candidate_id, 'candidate_match_signature', true ) ) {
                continue;
            }

            $pending[] = array( $candidate_id, $signature );
            if ( count( $pending ) >= self::BATCH_SIZE ) {
                break;
            }
        }

        if ( ! $pending ) {
            return;
        }

        self::event_index();
        foreach ( $pending as $item ) {
            self::classify_candidate( $item[0], $item[1] );
        }
    }

    private static function classify_candidate( $candidate_id, $signature ) {
        $candidate = self::candidate_record( $candidate_id );
        if ( ! $candidate['title_norm'] || ! $candidate['start_date'] ) {
            self::save_match_state( $candidate_id, 'incomplete', 0, 0, array(), 'missing_core_fields', $signature );
            return;
        }

        $best = null;
        foreach ( self::event_index() as $event ) {
            $score = self::score_event( $candidate, $event );
            if ( null === $score ) {
                continue;
            }
            if ( null === $best || $score['score'] > $best['score'] ) {
                $best = $score;
            }
        }

        if ( ! $best || $best['score'] < self::REVIEW_THRESHOLD ) {
            self::save_match_state( $candidate_id, 'new', 0, 0, array(), 'no_match', $signature );
            return;
        }

        if ( $best['score'] < self::MATCH_THRESHOLD ) {
            self::save_match_state(
                $candidate_id,
                'incomplete',
                $best['event']['id'],
                $best['score'],
                array(),
                'manual_review',
                $signature
            );
            return;
        }

        $changes = self::detect_changes( $candidate, $best['event'] );
        $status  = $changes ? 'changed' : 'existing';
        self::save_match_state(
            $candidate_id,
            $status,
            $best['event']['id'],
            $best['score'],
            $changes,
            $changes ? 'material_changes' : 'same_event',
            $signature
        );
    }

    private static function score_event( $candidate, $event ) {
        $title_similarity = self::title_similarity( $candidate['title_norm'], $event['title_norm'] );
        if ( $title_similarity < 55 ) {
            return null;
        }

        $date_gap = self::date_gap_days( $candidate['start_date'], $event['start_date'] );
        if ( null !== $date_gap && $date_gap > 60 ) {
            // Recurring annual editions must remain separate events even if they reuse a URL/title.
            return null;
        }

        $score = 0;
        if ( 100 === $title_similarity ) {
            $score += 60;
        } elseif ( $title_similarity >= 90 ) {
            $score += 50;
        } elseif ( $title_similarity >= 80 ) {
            $score += 40;
        } elseif ( $title_similarity >= 70 ) {
            $score += 25;
        } else {
            $score += 10;
        }

        if ( null !== $date_gap ) {
            if ( 0 === $date_gap ) {
                $score += 35;
            } elseif ( $date_gap <= 7 ) {
                $score += 15;
            } elseif ( $date_gap <= 30 ) {
                $score += 5;
            }
        }

        $candidate_event_url = self::url_identity( $candidate['event_url'] );
        $event_source_url    = self::url_identity( $event['source_url'] );
        if ( $candidate_event_url && $event_source_url && $candidate_event_url === $event_source_url ) {
            $score += 35;
        }

        $candidate_registration = self::url_identity( $candidate['registration_link'] );
        $event_registration     = self::url_identity( $event['registration_link'] );
        if ( $candidate_registration && $event_registration && $candidate_registration === $event_registration ) {
            $score += 25;
        }

        $candidate_host = self::url_host( $candidate['event_url'] ?: $candidate['source_url'] );
        $event_host     = self::url_host( $event['source_url'] );
        if ( $candidate_host && $event_host && $candidate_host === $event_host ) {
            $score += 5;
        }

        return array(
            'score'            => min( 100, $score ),
            'event'            => $event,
            'title_similarity' => $title_similarity,
            'date_gap'         => $date_gap,
        );
    }

    private static function detect_changes( $candidate, $event ) {
        $changes = array();

        if ( self::datetime_changed( $candidate['start_date'], $event['start_date'] ) ) {
            $changes[] = 'start_date';
        }
        if ( $candidate['end_date'] && self::datetime_changed( $candidate['end_date'], $event['end_date'] ) ) {
            $changes[] = 'end_date';
        }

        $text_fields = array( 'location_type', 'venue', 'address', 'organizer' );
        foreach ( $text_fields as $field ) {
            if ( ! self::is_empty( $candidate[ $field ] ) && self::text_identity( $candidate[ $field ] ) !== self::text_identity( $event[ $field ] ) ) {
                $changes[] = $field;
            }
        }

        if ( $candidate['registration_link'] ) {
            $candidate_url = self::url_identity( $candidate['registration_link'] );
            $event_url     = self::url_identity( $event['registration_link'] );
            if ( $candidate_url && $candidate_url !== $event_url ) {
                $changes[] = 'registration_link';
            }
        }

        return array_values( array_unique( $changes ) );
    }

    private static function datetime_changed( $candidate_value, $event_value ) {
        if ( ! $candidate_value ) {
            return false;
        }
        if ( ! $event_value ) {
            return true;
        }

        $candidate_date = substr( (string) $candidate_value, 0, 10 );
        $event_date     = substr( (string) $event_value, 0, 10 );
        if ( $candidate_date !== $event_date ) {
            return true;
        }

        $candidate_time = substr( (string) $candidate_value, 11, 5 );
        $event_time     = substr( (string) $event_value, 11, 5 );
        if ( in_array( $candidate_time, array( '', '00:00' ), true ) || in_array( $event_time, array( '', '00:00' ), true ) ) {
            return false;
        }

        return $candidate_time !== $event_time;
    }

    private static function save_match_state( $candidate_id, $status, $event_id, $score, $changes, $reason, $signature ) {
        self::update_meta_if_changed( $candidate_id, 'candidate_status', sanitize_key( $status ) );
        self::update_meta_if_changed( $candidate_id, 'matched_event_id', absint( $event_id ) );
        self::update_meta_if_changed( $candidate_id, 'candidate_match_score', absint( $score ) );
        self::update_meta_if_changed( $candidate_id, 'candidate_changes', array_values( (array) $changes ) );
        self::update_meta_if_changed( $candidate_id, 'candidate_match_reason', sanitize_key( $reason ) );
        self::update_meta_if_changed( $candidate_id, 'candidate_match_signature', $signature );
        self::update_meta_if_changed( $candidate_id, 'candidate_matched_at', current_time( 'mysql' ) );
    }

    private static function update_meta_if_changed( $post_id, $key, $value ) {
        if ( get_post_meta( $post_id, $key, true ) !== $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    private static function event_index() {
        if ( is_array( self::$event_index ) ) {
            return self::$event_index;
        }

        $ids = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        if ( ! $ids ) {
            self::$event_index = array();
            return self::$event_index;
        }

        update_meta_cache( 'post', $ids );
        self::$event_index = array();
        foreach ( $ids as $event_id ) {
            $event_id = absint( $event_id );
            self::$event_index[] = array(
                'id'                => $event_id,
                'title'             => self::clean_text( get_the_title( $event_id ) ),
                'title_norm'        => self::normalize_title( get_the_title( $event_id ) ),
                'start_date'        => trim( (string) get_post_meta( $event_id, 'start_date', true ) ),
                'end_date'          => trim( (string) get_post_meta( $event_id, 'end_date', true ) ),
                'location_type'     => trim( (string) get_post_meta( $event_id, 'location_type', true ) ),
                'venue'             => trim( (string) get_post_meta( $event_id, 'venue', true ) ),
                'address'           => trim( (string) get_post_meta( $event_id, 'address', true ) ),
                'organizer'         => trim( (string) get_post_meta( $event_id, 'organizer', true ) ),
                'registration_link' => trim( (string) get_post_meta( $event_id, 'registration_link', true ) ),
                'source_url'        => trim( (string) get_post_meta( $event_id, 'source_url', true ) ),
            );
        }

        return self::$event_index;
    }

    private static function candidate_record( $candidate_id ) {
        return array(
            'id'                => absint( $candidate_id ),
            'title'             => self::clean_text( get_the_title( $candidate_id ) ),
            'title_norm'        => self::normalize_title( get_the_title( $candidate_id ) ),
            'start_date'        => trim( (string) get_post_meta( $candidate_id, 'start_date', true ) ),
            'end_date'          => trim( (string) get_post_meta( $candidate_id, 'end_date', true ) ),
            'location_type'     => trim( (string) get_post_meta( $candidate_id, 'location_type', true ) ),
            'venue'             => trim( (string) get_post_meta( $candidate_id, 'venue', true ) ),
            'address'           => trim( (string) get_post_meta( $candidate_id, 'address', true ) ),
            'organizer'         => trim( (string) get_post_meta( $candidate_id, 'organizer', true ) ),
            'registration_link' => trim( (string) get_post_meta( $candidate_id, 'registration_link', true ) ),
            'event_url'         => trim( (string) get_post_meta( $candidate_id, 'event_url', true ) ),
            'source_url'        => trim( (string) get_post_meta( $candidate_id, 'source_url', true ) ),
        );
    }

    private static function candidate_signature( $candidate_id, $revision ) {
        $record = self::candidate_record( $candidate_id );
        return sha1( self::ENGINE_VERSION . '|' . absint( $revision ) . '|' . wp_json_encode( $record ) );
    }

    public static function columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            if ( 'candidate_status' === $key ) {
                $new['es5_state']       = 'Durum';
                $new['matched_event']   = 'Eşleşen Etkinlik';
                $new['match_changes']   = 'Değişiklik';
                continue;
            }
            $new[ $key ] = $label;
        }
        if ( ! isset( $new['es5_state'] ) ) {
            $new['es5_state']     = 'Durum';
            $new['matched_event'] = 'Eşleşen Etkinlik';
            $new['match_changes'] = 'Değişiklik';
        }
        return $new;
    }

    public static function render_column( $column, $post_id ) {
        if ( 'es5_state' === $column ) {
            $status = (string) get_post_meta( $post_id, 'candidate_status', true );
            echo self::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $score = absint( get_post_meta( $post_id, 'candidate_match_score', true ) );
            if ( $score ) {
                echo '<br><span style="color:#646970;font-size:11px;">eşleşme ' . esc_html( (string) $score ) . '/100</span>';
            }
            return;
        }

        if ( 'matched_event' === $column ) {
            $event_id = absint( get_post_meta( $post_id, 'matched_event_id', true ) );
            if ( ! $event_id || 'event' !== get_post_type( $event_id ) ) {
                echo '—';
                return;
            }
            $url = get_edit_post_link( $event_id, 'url' );
            echo '<a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $event_id ) ) . '</a>';
            return;
        }

        if ( 'match_changes' === $column ) {
            $changes = get_post_meta( $post_id, 'candidate_changes', true );
            if ( ! is_array( $changes ) || ! $changes ) {
                echo '—';
                return;
            }
            $labels = self::change_labels();
            $output = array();
            foreach ( $changes as $change ) {
                $output[] = isset( $labels[ $change ] ) ? $labels[ $change ] : $change;
            }
            echo esc_html( implode( ', ', $output ) );
        }
    }

    public static function row_actions( $actions, $post ) {
        if ( ! $post || 'event_candidate' !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
            return $actions;
        }

        $status   = (string) get_post_meta( $post->ID, 'candidate_status', true );
        $event_id = absint( get_post_meta( $post->ID, 'matched_event_id', true ) );
        $source   = (string) get_post_meta( $post->ID, 'event_url', true );
        if ( ! $source ) {
            $source = (string) get_post_meta( $post->ID, 'source_url', true );
        }

        if ( in_array( $status, array( 'existing', 'changed', 'incomplete', 'ignored' ), true ) ) {
            unset( $actions['import_event'] );
        }

        if ( 'changed' === $status && $event_id && 'event' === get_post_type( $event_id ) ) {
            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=sektorel_update_event_candidate&candidate_id=' . absint( $post->ID ) ),
                self::NONCE_ACTION . '_update_' . absint( $post->ID )
            );
            $actions['es5_update'] = '<a href="' . esc_url( $url ) . '" style="font-weight:700;">Güncelle</a>';
        }

        if ( in_array( $status, array( 'existing', 'changed', 'incomplete' ), true ) && $event_id && 'event' === get_post_type( $event_id ) ) {
            $actions['es5_match'] = '<a href="' . esc_url( get_edit_post_link( $event_id, 'url' ) ) . '">Eşleşeni Aç</a>';
        }

        if ( $source ) {
            $actions['es5_source'] = '<a href="' . esc_url( $source ) . '" target="_blank" rel="noopener noreferrer">Kaynağı Aç</a>';
        }

        if ( ! in_array( $status, array( 'imported', 'ignored' ), true ) ) {
            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=sektorel_ignore_event_candidate&candidate_id=' . absint( $post->ID ) ),
                self::NONCE_ACTION . '_ignore_' . absint( $post->ID )
            );
            $actions['es5_ignore'] = '<a href="' . esc_url( $url ) . '" style="color:#b32d2e;">Yok Say</a>';
        }

        if ( 'ignored' === $status ) {
            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=sektorel_recheck_event_candidate&candidate_id=' . absint( $post->ID ) ),
                self::NONCE_ACTION . '_recheck_' . absint( $post->ID )
            );
            $actions['es5_recheck'] = '<a href="' . esc_url( $url ) . '">Tekrar Değerlendir</a>';
        }

        return $actions;
    }

    public static function filters() {
        global $typenow;
        if ( 'event_candidate' !== $typenow ) {
            return;
        }

        $selected_status = isset( $_GET['candidate_match_status'] ) ? sanitize_key( wp_unslash( $_GET['candidate_match_status'] ) ) : '';
        $selected_parser = isset( $_GET['candidate_parser'] ) ? sanitize_key( wp_unslash( $_GET['candidate_parser'] ) ) : '';

        echo '<select name="candidate_match_status">';
        echo '<option value="">Tüm aday durumları</option>';
        foreach ( self::status_labels() as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $selected_status, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';

        echo '<select name="candidate_parser">';
        echo '<option value="">Tüm parserlar</option>';
        foreach ( array( 'html' => 'HTML', 'jsonld' => 'JSON-LD' ) as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $selected_parser, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
    }

    public static function apply_filters( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( 'event_candidate' !== $post_type ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );
        if ( ! empty( $_GET['candidate_match_status'] ) ) {
            $meta_query[] = array(
                'key'   => 'candidate_status',
                'value' => sanitize_key( wp_unslash( $_GET['candidate_match_status'] ) ),
            );
        }
        if ( ! empty( $_GET['candidate_parser'] ) ) {
            $meta_query[] = array(
                'key'   => 'parser_type',
                'value' => sanitize_key( wp_unslash( $_GET['candidate_parser'] ) ),
            );
        }
        if ( $meta_query ) {
            $query->set( 'meta_query', $meta_query );
        }
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_candidate_match_details',
            'ES-5 Eşleştirme ve Değişiklik Analizi',
            array( __CLASS__, 'render_match_meta_box' ),
            'event_candidate',
            'side',
            'high'
        );
    }

    public static function render_match_meta_box( $post ) {
        $status   = (string) get_post_meta( $post->ID, 'candidate_status', true );
        $event_id = absint( get_post_meta( $post->ID, 'matched_event_id', true ) );
        $score    = absint( get_post_meta( $post->ID, 'candidate_match_score', true ) );
        $changes  = get_post_meta( $post->ID, 'candidate_changes', true );

        echo '<p>' . self::status_badge( $status ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        if ( $score ) {
            echo '<p><strong>Eşleşme skoru:</strong> ' . esc_html( (string) $score ) . '/100</p>';
        }
        if ( $event_id && 'event' === get_post_type( $event_id ) ) {
            echo '<p><strong>Eşleşen:</strong><br><a href="' . esc_url( get_edit_post_link( $event_id, 'url' ) ) . '">' . esc_html( get_the_title( $event_id ) ) . '</a></p>';
        }
        if ( is_array( $changes ) && $changes ) {
            $labels = self::change_labels();
            echo '<p><strong>Değişen alanlar:</strong></p><ul style="list-style:disc;padding-left:18px;">';
            foreach ( $changes as $change ) {
                echo '<li>' . esc_html( isset( $labels[ $change ] ) ? $labels[ $change ] : $change ) . '</li>';
            }
            echo '</ul>';
        }
    }

    public static function handle_update_candidate() {
        self::require_admin_action( 'update' );
        $candidate_id = self::candidate_id_from_request();
        $event_id     = absint( get_post_meta( $candidate_id, 'matched_event_id', true ) );
        $status       = (string) get_post_meta( $candidate_id, 'candidate_status', true );

        if ( 'changed' !== $status || ! $event_id || 'event' !== get_post_type( $event_id ) ) {
            wp_die( 'Güncellenebilir bir etkinlik eşleşmesi bulunamadı.' );
        }

        $title   = self::clean_text( get_the_title( $candidate_id ) );
        $content = (string) get_post_field( 'post_content', $candidate_id );
        $postarr = array( 'ID' => $event_id );
        if ( $title ) {
            $postarr['post_title'] = $title;
        }
        if ( trim( $content ) ) {
            $postarr['post_content'] = $content;
        }
        $result = wp_update_post( $postarr, true );
        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }

        foreach ( array( 'start_date', 'end_date', 'location_type', 'venue', 'address', 'organizer', 'registration_link' ) as $key ) {
            $value = get_post_meta( $candidate_id, $key, true );
            if ( ! self::is_empty( $value ) ) {
                update_post_meta( $event_id, $key, $value );
            }
        }

        $event_url = (string) get_post_meta( $candidate_id, 'event_url', true );
        if ( $event_url ) {
            update_post_meta( $event_id, 'source_url', esc_url_raw( $event_url, array( 'http', 'https' ) ) );
        }

        update_post_meta( $candidate_id, 'candidate_status', 'imported' );
        update_post_meta( $candidate_id, 'candidate_resolution', 'updated' );
        update_post_meta( $candidate_id, 'imported_event_id', $event_id );
        update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );

        wp_safe_redirect( get_edit_post_link( $event_id, 'url' ) );
        exit;
    }

    public static function handle_ignore_candidate() {
        self::require_admin_action( 'ignore' );
        $candidate_id = self::candidate_id_from_request();
        update_post_meta( $candidate_id, 'candidate_status', 'ignored' );
        update_post_meta( $candidate_id, 'candidate_resolution', 'ignored' );
        update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
        wp_safe_redirect( admin_url( 'edit.php?post_type=event_candidate' ) );
        exit;
    }

    public static function handle_recheck_candidate() {
        self::require_admin_action( 'recheck' );
        $candidate_id = self::candidate_id_from_request();
        update_post_meta( $candidate_id, 'candidate_status', 'new' );
        delete_post_meta( $candidate_id, 'candidate_match_signature' );
        delete_post_meta( $candidate_id, 'candidate_resolution' );
        delete_post_meta( $candidate_id, 'candidate_resolved_at' );
        wp_safe_redirect( admin_url( 'edit.php?post_type=event_candidate' ) );
        exit;
    }

    private static function require_admin_action( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }
        $candidate_id = isset( $_GET['candidate_id'] ) ? absint( $_GET['candidate_id'] ) : 0;
        check_admin_referer( self::NONCE_ACTION . '_' . $action . '_' . $candidate_id );
    }

    private static function candidate_id_from_request() {
        $candidate_id = isset( $_GET['candidate_id'] ) ? absint( $_GET['candidate_id'] ) : 0;
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            wp_die( 'Geçersiz aday etkinlik.' );
        }
        return $candidate_id;
    }

    private static function status_badge( $status ) {
        $labels = self::status_labels();
        $label  = isset( $labels[ $status ] ) ? $labels[ $status ] : ( $status ? $status : 'Analiz bekliyor' );
        $colors = array(
            'new'        => array( '#e7f5ff', '#0a4b78' ),
            'existing'   => array( '#edfaef', '#116329' ),
            'changed'    => array( '#fff4ce', '#6b4f00' ),
            'incomplete' => array( '#f0f0f1', '#50575e' ),
            'ignored'    => array( '#f6f7f7', '#8c8f94' ),
            'imported'   => array( '#ede7f6', '#4527a0' ),
        );
        $color = isset( $colors[ $status ] ) ? $colors[ $status ] : array( '#f0f0f1', '#50575e' );
        return '<span style="display:inline-block;padding:3px 7px;border-radius:12px;background:' . esc_attr( $color[0] ) . ';color:' . esc_attr( $color[1] ) . ';font-size:11px;font-weight:700;">' . esc_html( $label ) . '</span>';
    }

    private static function status_labels() {
        return array(
            'new'        => 'Yeni',
            'existing'   => 'Zaten mevcut',
            'changed'    => 'Değişmiş',
            'incomplete' => 'Manuel kontrol',
            'ignored'    => 'Yok sayıldı',
            'imported'   => 'İşlendi',
        );
    }

    private static function change_labels() {
        return array(
            'start_date'        => 'Başlangıç tarihi',
            'end_date'          => 'Bitiş tarihi',
            'location_type'     => 'Lokasyon tipi',
            'venue'             => 'Mekan',
            'address'           => 'Adres',
            'organizer'         => 'Organizatör',
            'registration_link' => 'Kayıt linki',
        );
    }

    private static function title_similarity( $left, $right ) {
        if ( ! $left || ! $right ) {
            return 0;
        }
        if ( $left === $right ) {
            return 100;
        }
        $percent = 0.0;
        similar_text( $left, $right, $percent );
        return (int) round( $percent );
    }

    private static function date_gap_days( $left, $right ) {
        $left_date  = substr( (string) $left, 0, 10 );
        $right_date = substr( (string) $right, 0, 10 );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $left_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $right_date ) ) {
            return null;
        }
        try {
            $a = new DateTime( $left_date );
            $b = new DateTime( $right_date );
            return abs( (int) $a->diff( $b )->format( '%r%a' ) );
        } catch ( Exception $e ) {
            return null;
        }
    }

    private static function normalize_title( $value ) {
        $value = strtolower( remove_accents( self::clean_text( $value ) ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function text_identity( $value ) {
        $value = strtolower( remove_accents( self::clean_text( $value ) ) );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        return sanitize_text_field( preg_replace( '/\s+/u', ' ', trim( $value ) ) );
    }

    private static function url_identity( $url ) {
        $parts = wp_parse_url( trim( (string) $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }
        $scheme = ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
        $host   = strtolower( rtrim( $parts['host'], '.' ) );
        $path   = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '/';
        $path   = '/' === $path ? '/' : untrailingslashit( $path );
        return $scheme . '://' . $host . $path;
    }

    private static function url_host( $url ) {
        $host = wp_parse_url( trim( (string) $url ), PHP_URL_HOST );
        return $host ? strtolower( preg_replace( '/^www\./i', '', rtrim( $host, '.' ) ) ) : '';
    }

    private static function is_empty( $value ) {
        return '' === $value || null === $value || array() === $value;
    }
}
