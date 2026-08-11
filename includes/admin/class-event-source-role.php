<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Source-role policy for event discovery vs enrichment sources.
 *
 * Existing sources remain discovery sources unless explicitly assigned another
 * role, so upgrading does not change current production behaviour by default.
 */
class Sektorel_Event_Source_Role {

    const DEFAULT_ROLE = 'discovery';
    const NONCE_ACTION = 'sektorel_event_source_role_save';

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 25 );
        add_action( 'save_post_event_source', array( __CLASS__, 'save_source_role' ), 20, 2 );
        add_filter( 'manage_event_source_posts_columns', array( __CLASS__, 'add_role_column' ), 25 );
        add_action( 'manage_event_source_posts_custom_column', array( __CLASS__, 'render_role_column' ), 25, 2 );

        // The legacy JSON-LD single-candidate importer creates an event directly.
        // Stop that path before it runs when the source is enrichment-only.
        add_action( 'admin_post_sektorel_import_event_candidate', array( __CLASS__, 'guard_single_candidate_import' ), 1 );
    }

    public static function roles() {
        return array(
            'discovery'            => 'Genel keşif',
            'canonical_registry'   => 'Ana kayıt / Canonical',
            'venue_enrichment'     => 'Mekan zenginleştirme',
            'organizer_enrichment' => 'Organizatör zenginleştirme',
            'official_enrichment'  => 'Resmî site zenginleştirme',
        );
    }

    public static function role_descriptions() {
        return array(
            'discovery'            => 'Yeni etkinlik keşfedebilir ve uygun adaydan yeni event oluşturabilir.',
            'canonical_registry'   => 'Bu etkinlik ailesi için ana kayıt kaynağıdır; yeni occurrence oluşturabilir.',
            'venue_enrichment'     => 'Sadece mevcut event ile eşleşerek mekan/salon gibi bilgileri destekler.',
            'organizer_enrichment' => 'Sadece mevcut event ile eşleşerek organizatör kaynaklı bilgileri destekler.',
            'official_enrichment'  => 'Sadece mevcut event ile eşleşerek resmî site detaylarını destekler.',
        );
    }

    public static function add_meta_box() {
        add_meta_box(
            'sektorel_event_source_role',
            'Kaynak Rolü',
            array( __CLASS__, 'render_meta_box' ),
            'event_source',
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( self::NONCE_ACTION, 'sektorel_event_source_role_nonce' );

        $role         = self::role_for_source( $post->ID );
        $roles        = self::roles();
        $descriptions = self::role_descriptions();

        echo '<p><select name="source_role" style="width:100%;">';
        foreach ( $roles as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $role, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></p>';
        echo '<p class="description">' . esc_html( $descriptions[ $role ] ) . '</p>';
        echo '<p class="description"><strong>Not:</strong> Eski kaynaklarda alan boşsa sistem güvenli geriye uyumluluk için “Genel keşif” kabul eder.</p>';
    }

    public static function save_source_role( $post_id, $post ) {
        if ( ! isset( $_POST['sektorel_event_source_role_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sektorel_event_source_role_nonce'] ) ), self::NONCE_ACTION ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) || ! $post || 'event_source' !== $post->post_type ) {
            return;
        }

        $role  = isset( $_POST['source_role'] ) ? sanitize_key( wp_unslash( $_POST['source_role'] ) ) : self::DEFAULT_ROLE;
        $roles = self::roles();
        if ( ! isset( $roles[ $role ] ) ) {
            $role = self::DEFAULT_ROLE;
        }

        update_post_meta( $post_id, 'source_role', $role );
    }

    public static function add_role_column( $columns ) {
        $result = array();
        foreach ( $columns as $key => $label ) {
            $result[ $key ] = $label;
            if ( 'source_type' === $key ) {
                $result['source_role'] = 'Rol';
            }
        }

        if ( ! isset( $result['source_role'] ) ) {
            $result['source_role'] = 'Rol';
        }

        return $result;
    }

    public static function render_role_column( $column, $post_id ) {
        if ( 'source_role' !== $column ) {
            return;
        }

        $role  = self::role_for_source( $post_id );
        $label = self::role_label( $role );

        if ( self::can_create_event( $role ) ) {
            echo '<strong>' . esc_html( $label ) . '</strong>';
            return;
        }

        echo '<strong style="color:#996800;">' . esc_html( $label ) . '</strong><br><span style="font-size:11px;color:#646970;">Yeni event açmaz</span>';
    }

    public static function role_for_source( $source_id ) {
        $source_id = absint( $source_id );
        if ( ! $source_id || 'event_source' !== get_post_type( $source_id ) ) {
            return self::DEFAULT_ROLE;
        }

        $role  = sanitize_key( (string) get_post_meta( $source_id, 'source_role', true ) );
        $roles = self::roles();

        return isset( $roles[ $role ] ) ? $role : self::DEFAULT_ROLE;
    }

    public static function role_for_candidate( $candidate_id ) {
        $candidate_id = absint( $candidate_id );
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return self::DEFAULT_ROLE;
        }

        return self::role_for_source( absint( get_post_meta( $candidate_id, 'source_id', true ) ) );
    }

    public static function role_label( $role ) {
        $role  = sanitize_key( (string) $role );
        $roles = self::roles();
        return isset( $roles[ $role ] ) ? $roles[ $role ] : $roles[ self::DEFAULT_ROLE ];
    }

    public static function can_create_event( $role ) {
        return in_array( sanitize_key( (string) $role ), array( 'discovery', 'canonical_registry' ), true );
    }

    public static function can_candidate_create_event( $candidate_id ) {
        return self::can_create_event( self::role_for_candidate( $candidate_id ) );
    }

    public static function candidate_creation_guard( $candidate_id ) {
        $candidate_id = absint( $candidate_id );
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return new WP_Error( 'invalid_candidate', 'Geçersiz aday etkinlik.' );
        }

        $role = self::role_for_candidate( $candidate_id );
        if ( self::can_create_event( $role ) ) {
            return true;
        }

        $matched_event_id = absint( get_post_meta( $candidate_id, 'matched_event_id', true ) );
        $message = $matched_event_id && 'event' === get_post_type( $matched_event_id )
            ? 'Bu aday zenginleştirme kaynağından geliyor. Yeni event oluşturmak yerine eşleşen etkinlik üzerinden işlenmelidir.'
            : 'Bu aday zenginleştirme kaynağından geliyor ve eşleşen bir etkinlik bulunmuyor. Yeni event oluşturulmadı; manuel inceleme gerekir.';

        return new WP_Error( 'source_role_enrichment_only', $message );
    }

    public static function guard_single_candidate_import() {
        $candidate_id = isset( $_GET['candidate_id'] ) ? absint( $_GET['candidate_id'] ) : 0;
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return;
        }

        $guard = self::candidate_creation_guard( $candidate_id );
        if ( ! is_wp_error( $guard ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        check_admin_referer( 'sektorel_event_candidate_jsonld_import_' . $candidate_id );

        $matched_event_id = absint( get_post_meta( $candidate_id, 'matched_event_id', true ) );
        $back_url = $matched_event_id && 'event' === get_post_type( $matched_event_id )
            ? get_edit_post_link( $matched_event_id, 'url' )
            : admin_url( 'edit.php?post_type=event_candidate' );

        wp_die(
            esc_html( $guard->get_error_message() ) . '<br><br><a href="' . esc_url( $back_url ) . '">Geri dön</a>',
            'Zenginleştirme kaynağı',
            array( 'response' => 409 )
        );
    }
}
