<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ES-4B field-quality guard for generic HTML candidates.
 *
 * Generic page containers can contain footer/contact/privacy text or unrelated
 * clock values. This layer post-processes candidate meta conservatively: when
 * a field is not trustworthy it is cleared instead of publishing wrong data.
 */
class Sektorel_Event_Candidate_Field_Quality {

    const QUALITY_VERSION = '1282';
    const BATCH_SIZE      = 200;

    private static $lock = false;

    public static function init() {
        add_action( 'load-edit.php', array( __CLASS__, 'cleanup_existing_candidates' ), 65 );
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_normalize_candidate' ), 85, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_normalize_candidate' ), 85, 4 );

        add_filter( 'manage_event_candidate_posts_columns', array( __CLASS__, 'columns' ), 80 );
        add_action( 'manage_event_candidate_posts_custom_column', array( __CLASS__, 'render_column' ), 80, 2 );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'quality_filter' ), 80 );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_quality_filter' ), 80 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 80 );
    }

    public static function cleanup_existing_candidates() {
        global $typenow;

        if ( 'event_candidate' !== $typenow || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => self::BATCH_SIZE,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => 'candidate_field_quality_version',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => 'candidate_field_quality_version',
                    'value'   => self::QUALITY_VERSION,
                    'compare' => '!=',
                ),
            ),
        ) );

        foreach ( $ids as $candidate_id ) {
            self::normalize_candidate( absint( $candidate_id ) );
        }
    }

    public static function maybe_normalize_candidate( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$lock || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        if ( ! in_array( $meta_key, array( 'parser_type', 'address', 'venue', 'organizer', 'start_date', 'end_date' ), true ) ) {
            return;
        }

        // parser_type is written at the end of the generic HTML upsert, so this
        // hook also normalizes all fields after the candidate is complete.
        if ( 'parser_type' === $meta_key || 'html' === (string) get_post_meta( $object_id, 'parser_type', true ) ) {
            self::normalize_candidate( absint( $object_id ) );
        }
    }

    private static function normalize_candidate( $candidate_id ) {
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return;
        }

        $parser = (string) get_post_meta( $candidate_id, 'parser_type', true );
        $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );

        if ( 'html' !== $parser || in_array( $status, array( 'imported', 'ignored' ), true ) ) {
            self::write_meta( $candidate_id, 'candidate_field_quality_version', self::QUALITY_VERSION );
            return;
        }

        self::$lock = true;

        $flags   = array();
        $changed = false;

        $venue = (string) get_post_meta( $candidate_id, 'venue', true );
        $clean_venue = self::clean_short_field( $venue, 160, 'venue', $flags );
        if ( $clean_venue !== $venue ) {
            update_post_meta( $candidate_id, 'venue', $clean_venue );
            $changed = true;
        }

        $organizer = (string) get_post_meta( $candidate_id, 'organizer', true );
        $clean_organizer = self::clean_short_field( $organizer, 160, 'organizer', $flags );
        if ( $clean_organizer !== $organizer ) {
            update_post_meta( $candidate_id, 'organizer', $clean_organizer );
            $changed = true;
        }

        $address = (string) get_post_meta( $candidate_id, 'address', true );
        $clean_address = self::clean_address( $address, $flags );
        if ( $clean_address !== $address ) {
            update_post_meta( $candidate_id, 'address', $clean_address );
            $changed = true;
        }

        foreach ( array( 'start_date', 'end_date' ) as $date_key ) {
            $value = (string) get_post_meta( $candidate_id, $date_key, true );
            $clean = self::normalize_unverified_time( $candidate_id, $value, $date_key, $flags );
            if ( $clean !== $value ) {
                update_post_meta( $candidate_id, $date_key, $clean );
                $changed = true;
            }
        }

        $flags = array_values( array_unique( $flags ) );
        update_post_meta( $candidate_id, 'candidate_quality_flags', $flags );
        update_post_meta( $candidate_id, 'candidate_quality_needs_review', $flags ? '1' : '0' );
        update_post_meta( $candidate_id, 'candidate_field_quality_version', self::QUALITY_VERSION );
        update_post_meta( $candidate_id, 'candidate_field_quality_at', current_time( 'mysql' ) );

        if ( $changed ) {
            delete_post_meta( $candidate_id, 'candidate_match_signature' );
        }

        self::$lock = false;
    }

    private static function clean_short_field( $value, $max_chars, $prefix, &$flags ) {
        $clean = self::clean_text( $value );
        if ( '' === $clean ) {
            return '';
        }

        if ( self::contains_policy_or_footer_text( $clean ) || self::text_length( $clean ) > $max_chars ) {
            $flags[] = $prefix . '_contaminated';
            return '';
        }

        return sanitize_text_field( $clean );
    }

    private static function clean_address( $value, &$flags ) {
        $clean = self::clean_text( $value );
        if ( '' === $clean ) {
            return '';
        }

        // Privacy/footer/contact blocks are not event addresses. Do not retain
        // the text before the marker either: it is commonly the organizer's
        // office address (EV Charge Show is a real-world example).
        if ( self::contains_policy_or_footer_text( $clean ) ) {
            $flags[] = 'address_footer_contamination';
            return '';
        }

        if ( self::text_length( $clean ) > 220 || self::word_count( $clean ) > 32 ) {
            $flags[] = 'address_too_long';
            return '';
        }

        if ( false !== strpos( $clean, '@' ) || preg_match( '/\+?\d[\d\s().-]{8,}\d/u', $clean ) ) {
            $flags[] = 'address_contact_block';
            return '';
        }

        return sanitize_text_field( $clean );
    }

    private static function contains_policy_or_footer_text( $value ) {
        $key = self::normalize_key( $value );
        if ( ! $key ) {
            return false;
        }

        $needles = array(
            'privacy policy',
            'personal data',
            'data processing',
            'kvkk',
            'kisisel verilerin',
            'aydinlatma metni',
            'gdpr',
            'cookie policy',
            'cerez politikasi',
            'copyright',
            'all rights reserved',
            'terms of use',
            'terms conditions',
            'quick links',
            'social media',
            'newsletter',
        );

        foreach ( $needles as $needle ) {
            if ( false !== strpos( $key, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    private static function normalize_unverified_time( $candidate_id, $value, $field, &$flags ) {
        if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/', trim( (string) $value ), $matches ) ) {
            return $value;
        }

        $hour   = (int) $matches[2];
        $minute = (int) $matches[3];

        if ( 0 === $hour && 0 === $minute ) {
            return $value;
        }

        // Round times (09:00, 10:30, etc.) are common event times. Preserve
        // them unless we have stronger evidence. Odd minute values such as
        // 09:11 are typically clocks/timestamps captured from a broad body.
        if ( in_array( $minute, array( 0, 15, 30, 45 ), true ) ) {
            return $value;
        }

        $time_colon = sprintf( '%02d:%02d', $hour, $minute );
        $time_dot   = sprintf( '%02d.%02d', $hour, $minute );
        $evidence   = self::clean_text(
            get_the_title( $candidate_id ) . ' ' .
            (string) get_post_field( 'post_content', $candidate_id )
        );

        if ( false !== strpos( $evidence, $time_colon ) || false !== strpos( $evidence, $time_dot ) ) {
            return $value;
        }

        $flags[] = $field . '_time_unverified';
        return $matches[1] . 'T00:00';
    }

    public static function columns( $columns ) {
        $result = array();
        foreach ( $columns as $key => $label ) {
            $result[ $key ] = $label;
            if ( 'match_changes' === $key ) {
                $result['field_quality'] = 'Alan Kalitesi';
            }
        }
        if ( ! isset( $result['field_quality'] ) ) {
            $result['field_quality'] = 'Alan Kalitesi';
        }
        return $result;
    }

    public static function render_column( $column, $post_id ) {
        if ( 'field_quality' !== $column ) {
            return;
        }

        $flags = get_post_meta( $post_id, 'candidate_quality_flags', true );
        if ( ! is_array( $flags ) || ! $flags ) {
            echo '<span style="color:#116329;font-weight:600;">Temiz</span>';
            return;
        }

        $labels = self::flag_labels();
        $items  = array();
        foreach ( $flags as $flag ) {
            $items[] = isset( $labels[ $flag ] ) ? $labels[ $flag ] : $flag;
        }
        echo '<span style="color:#996800;font-weight:700;">Kontrol</span><br><span style="font-size:11px;color:#646970;">' . esc_html( implode( ', ', $items ) ) . '</span>';
    }

    public static function quality_filter() {
        global $typenow;
        if ( 'event_candidate' !== $typenow ) {
            return;
        }

        $selected = isset( $_GET['candidate_quality'] ) ? sanitize_key( wp_unslash( $_GET['candidate_quality'] ) ) : '';
        echo '<select name="candidate_quality">';
        echo '<option value="">Tüm alan kaliteleri</option>';
        echo '<option value="review" ' . selected( $selected, 'review', false ) . '>Kalite uyarısı olanlar</option>';
        echo '<option value="clean" ' . selected( $selected, 'clean', false ) . '>Temiz alanlar</option>';
        echo '</select>';
    }

    public static function apply_quality_filter( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || 'event_candidate' !== $query->get( 'post_type' ) ) {
            return;
        }

        $filter = isset( $_GET['candidate_quality'] ) ? sanitize_key( wp_unslash( $_GET['candidate_quality'] ) ) : '';
        if ( ! in_array( $filter, array( 'review', 'clean' ), true ) ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );
        $meta_query[] = array(
            'key'   => 'candidate_quality_needs_review',
            'value' => 'review' === $filter ? '1' : '0',
        );
        $query->set( 'meta_query', $meta_query );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_candidate_field_quality',
            'Alan Kalitesi',
            array( __CLASS__, 'render_meta_box' ),
            'event_candidate',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        $flags = get_post_meta( $post->ID, 'candidate_quality_flags', true );
        if ( ! is_array( $flags ) || ! $flags ) {
            echo '<p><strong style="color:#116329;">Alan kalite uyarısı yok.</strong></p>';
            return;
        }

        $labels = self::flag_labels();
        echo '<p><strong style="color:#996800;">Manuel göz kontrolü önerilir.</strong></p><ul style="list-style:disc;padding-left:18px;">';
        foreach ( $flags as $flag ) {
            echo '<li>' . esc_html( isset( $labels[ $flag ] ) ? $labels[ $flag ] : $flag ) . '</li>';
        }
        echo '</ul><p class="description">Güvenilmeyen değerler yanlış veri taşımak yerine boş bırakılmış olabilir.</p>';
    }

    private static function flag_labels() {
        return array(
            'venue_contaminated'          => 'Mekan metni temizlendi',
            'organizer_contaminated'      => 'Organizatör metni temizlendi',
            'address_footer_contamination'=> 'Adres footer/KVKK metni içeriyordu',
            'address_too_long'            => 'Adres aşırı uzundu',
            'address_contact_block'       => 'Adres iletişim bloğuydu',
            'start_date_time_unverified'  => 'Başlangıç saati kanıtlanamadı',
            'end_date_time_unverified'    => 'Bitiş saati kanıtlanamadı',
        );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = str_replace( "\xC2\xA0", ' ', $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return trim( (string) $value );
    }

    private static function normalize_key( $value ) {
        $value = strtolower( remove_accents( self::clean_text( $value ) ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function text_length( $value ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
    }

    private static function word_count( $value ) {
        $parts = preg_split( '/\s+/u', trim( (string) $value ) );
        return is_array( $parts ) ? count( array_filter( $parts, 'strlen' ) ) : 0;
    }

    private static function write_meta( $post_id, $key, $value ) {
        self::$lock = true;
        update_post_meta( $post_id, $key, $value );
        self::$lock = false;
    }
}
