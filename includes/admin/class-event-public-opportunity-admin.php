<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only admin / GraphQL semantics for public support opportunities.
 */
class Sektorel_Event_Public_Opportunity_Admin {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 111 );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ), 36 );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_public_opportunity_scope',
            'Kamu Desteği / Başvuru Fırsatı',
            array( __CLASS__, 'render_meta_box' ),
            'event',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        if ( '1' !== (string) get_post_meta( $post->ID, 'is_public_opportunity', true ) ) {
            echo '<p class="description">Bu Event kamu desteği / başvuru fırsatı kaydı değil.</p>';
            return;
        }

        $managed  = '1' === (string) get_post_meta( $post->ID, 'opportunity_managed', true );
        $provider = sanitize_text_field( (string) get_post_meta( $post->ID, 'opportunity_provider_name', true ) );
        $status   = sanitize_key( (string) get_post_meta( $post->ID, 'opportunity_status', true ) );
        $kind     = sanitize_key( (string) get_post_meta( $post->ID, 'opportunity_kind', true ) );
        $start    = sanitize_text_field( (string) get_post_meta( $post->ID, 'opportunity_application_start', true ) );
        $deadline = sanitize_text_field( (string) get_post_meta( $post->ID, 'opportunity_application_deadline', true ) );
        $amount   = sanitize_text_field( (string) get_post_meta( $post->ID, 'opportunity_amount', true ) );
        $audience = get_post_meta( $post->ID, 'opportunity_audience', true );
        $audience = is_array( $audience ) ? array_values( array_filter( array_map( 'sanitize_key', $audience ) ) ) : array();
        $source   = esc_url( (string) get_post_meta( $post->ID, 'opportunity_source_url', true ) );
        $apply    = esc_url( (string) get_post_meta( $post->ID, 'opportunity_application_url', true ) );

        echo '<p><strong>' . esc_html( $managed ? 'Otomatik yönetilen fırsat' : 'Manuel fırsat kaydı' ) . '</strong></p>';
        echo '<p><strong>Son başvuru semantiği:</strong><br>Gün boyu deadline</p>';
        if ( $provider ) {
            echo '<p><strong>Kurum:</strong><br>' . esc_html( $provider ) . '</p>';
        }
        if ( $status ) {
            echo '<p><strong>Durum:</strong> ' . esc_html( self::status_label( $status ) ) . '</p>';
        }
        if ( $kind ) {
            echo '<p><strong>Fırsat türü:</strong><br>' . esc_html( self::kind_label( $kind ) ) . '</p>';
        }
        if ( $start ) {
            echo '<p><strong>Başvuru başlangıcı:</strong> ' . esc_html( self::format_date( $start ) ) . '</p>';
        }
        if ( $deadline ) {
            echo '<p><strong>Son başvuru:</strong> ' . esc_html( self::format_date( $deadline ) ) . '</p>';
        }
        if ( $amount ) {
            echo '<p><strong>Destek / finansman:</strong><br>' . esc_html( $amount ) . '</p>';
        }
        if ( $audience ) {
            echo '<p><strong>Kimleri ilgilendiriyor?</strong></p><ul style="list-style:disc;padding-left:18px;">';
            foreach ( $audience as $key ) {
                echo '<li>' . esc_html( self::audience_label( $key ) ) . '</li>';
            }
            echo '</ul>';
        }
        if ( $source ) {
            echo '<p><a href="' . $source . '" target="_blank" rel="noopener noreferrer">Resmî duyuruyu aç</a></p>';
        }
        if ( $apply ) {
            echo '<p><a href="' . $apply . '" target="_blank" rel="noopener noreferrer">Başvuru kanalını aç</a></p>';
        }
        echo '<p class="description">Bu alanlar Kaynak Merkezi → Kamu Destekleri ve Son Başvuruları Güncelle aşaması tarafından yönetilir.</p>';
    }

    public static function register_graphql_fields() {
        if ( ! function_exists( 'register_graphql_field' ) ) {
            return;
        }

        $fields = array(
            'isPublicOpportunity'            => array( 'type' => 'Boolean', 'meta' => 'is_public_opportunity', 'boolean' => true ),
            'opportunityProvider'             => array( 'type' => 'String', 'meta' => 'opportunity_provider' ),
            'opportunityProviderName'         => array( 'type' => 'String', 'meta' => 'opportunity_provider_name' ),
            'opportunityKind'                 => array( 'type' => 'String', 'meta' => 'opportunity_kind' ),
            'opportunityStatus'               => array( 'type' => 'String', 'meta' => 'opportunity_status' ),
            'opportunityApplicationStart'     => array( 'type' => 'String', 'meta' => 'opportunity_application_start' ),
            'opportunityApplicationDeadline'  => array( 'type' => 'String', 'meta' => 'opportunity_application_deadline' ),
            'opportunitySourceUrl'            => array( 'type' => 'String', 'meta' => 'opportunity_source_url' ),
            'opportunityApplicationUrl'       => array( 'type' => 'String', 'meta' => 'opportunity_application_url' ),
            'opportunityAmount'               => array( 'type' => 'String', 'meta' => 'opportunity_amount' ),
            'opportunityIsDeadline'           => array( 'type' => 'Boolean', 'meta' => 'opportunity_is_deadline', 'boolean' => true ),
        );

        foreach ( $fields as $graphql_name => $definition ) {
            register_graphql_field( 'Event', $graphql_name, array(
                'type'    => $definition['type'],
                'resolve' => static function ( $post ) use ( $definition ) {
                    $value = get_post_meta( $post->ID, $definition['meta'], true );
                    return ! empty( $definition['boolean'] ) ? '1' === (string) $value : (string) $value;
                },
            ) );
        }

        register_graphql_field( 'Event', 'opportunityAudience', array(
            'type'    => array( 'list_of' => 'String' ),
            'resolve' => static function ( $post ) {
                $value = get_post_meta( $post->ID, 'opportunity_audience', true );
                return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
            },
        ) );
    }

    private static function status_label( $status ) {
        $labels = array(
            'open'     => 'Başvuruya açık',
            'upcoming' => 'Yakında açılacak',
            'closed'   => 'Başvurusu kapandı',
        );
        return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
    }

    private static function kind_label( $kind ) {
        $labels = array(
            'credit_support' => 'Kredi / finansman desteği',
            'grant_call'     => 'Hibe / proje çağrısı',
        );
        return isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind;
    }

    private static function audience_label( $key ) {
        $labels = array(
            'technology_startup'           => 'Teknoloji girişimleri',
            'technogirisim_badge_holder'   => 'Geçerli Teknogirişim Rozeti sahipleri',
            'kosgeb_registered_sme'        => 'KOSGEB veri tabanında kayıtlı KOBİ’ler',
            'disabled_entrepreneur'         => 'Engelli girişimciler',
            'ex_convict_entrepreneur'       => 'Eski hükümlü girişimciler',
            'protected_workplace_project'   => 'Korumalı işyeri projeleri',
        );
        return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
    }

    private static function format_date( $date ) {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) ) {
            return $date;
        }
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
}
