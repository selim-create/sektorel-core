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

    public static function init() {
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
