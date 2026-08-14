<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only operational metadata for automatically managed official-calendar
 * Events. Keeps applicability/rule provenance visible without adding another
 * editable workflow to Event admin.
 */
class Sektorel_Event_Official_Calendar_Admin {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 110 );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ), 30 );
        add_action( 'admin_footer', array( __CLASS__, 'admin_footer' ), 130 );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_official_calendar_scope',
            'Resmî Takvim Kapsamı',
            array( __CLASS__, 'render_meta_box' ),
            'event',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        if ( '1' !== (string) get_post_meta( $post->ID, 'is_official', true ) ) {
            echo '<p class="description">Bu Event resmî takvim kaydı değil.</p>';
            return;
        }

        $managed = '1' === (string) get_post_meta( $post->ID, 'official_calendar_managed', true );
        $rule    = sanitize_key( (string) get_post_meta( $post->ID, 'official_rule_key', true ) );
        $period  = sanitize_text_field( (string) get_post_meta( $post->ID, 'official_period', true ) );
        $basis   = sanitize_key( (string) get_post_meta( $post->ID, 'official_date_basis', true ) );
        $scope   = get_post_meta( $post->ID, 'official_applicability', true );
        $scope   = is_array( $scope ) ? array_values( array_filter( array_map( 'sanitize_key', $scope ) ) ) : array();
        $source  = esc_url( (string) get_post_meta( $post->ID, 'official_source_url', true ) );

        echo '<p><strong>' . esc_html( $managed ? 'Otomatik yönetilen kayıt' : 'Manuel resmî kayıt' ) . '</strong></p>';
        if ( $managed ) {
            echo '<p><strong>Takvim semantiği:</strong><br>Gün boyu son tarih / deadline</p>';
        }

        if ( $scope ) {
            echo '<p><strong>Kimleri ilgilendiriyor?</strong></p><ul style="list-style:disc;padding-left:18px;">';
            foreach ( $scope as $key ) {
                echo '<li>' . esc_html( self::applicability_label( $key ) ) . '</li>';
            }
            echo '</ul>';
        }

        if ( $rule ) {
            echo '<p><strong>Kural:</strong><br><code>' . esc_html( $rule ) . '</code></p>';
        }
        if ( $period ) {
            echo '<p><strong>Dönem:</strong> ' . esc_html( $period ) . '</p>';
        }
        if ( $basis ) {
            echo '<p><strong>Tarih dayanağı:</strong><br>' . esc_html( self::basis_label( $basis ) ) . '</p>';
        }
        if ( $source ) {
            echo '<p><a href="' . $source . '" target="_blank" rel="noopener noreferrer">Resmî kaynağı aç</a></p>';
        }

        echo '<p class="description">Bu alanlar Kaynak Merkezi → Resmî Takvimi Güncelle aşaması tarafından yönetilir.</p>';
    }

    public static function admin_footer() {
        global $post;
        if ( ! $post || 'event' !== get_post_type( $post ) ) {
            return;
        }
        ?>
        <script>
        jQuery(function($){
            var $official = $('#is_official');
            if ($official.length) {
                var $label = $official.closest('label');
                $label.contents().filter(function(){ return this.nodeType === 3; }).each(function(){
                    if (this.nodeValue.indexOf('Resmî Takvim Etkinliği') !== -1) {
                        this.nodeValue = ' Resmî Takvim Etkinliği';
                    }
                });
            }
        });
        </script>
        <?php
    }

    public static function register_graphql_fields() {
        register_graphql_field( 'Event', 'officialApplicability', array(
            'type'    => array( 'list_of' => 'String' ),
            'resolve' => static function ( $post ) {
                $value = get_post_meta( $post->ID, 'official_applicability', true );
                return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
            },
        ) );
        register_graphql_field( 'Event', 'officialRuleKey', array(
            'type'    => 'String',
            'resolve' => static function ( $post ) { return (string) get_post_meta( $post->ID, 'official_rule_key', true ); },
        ) );
        register_graphql_field( 'Event', 'officialPeriod', array(
            'type'    => 'String',
            'resolve' => static function ( $post ) { return (string) get_post_meta( $post->ID, 'official_period', true ); },
        ) );
        register_graphql_field( 'Event', 'officialDateBasis', array(
            'type'    => 'String',
            'resolve' => static function ( $post ) { return (string) get_post_meta( $post->ID, 'official_date_basis', true ); },
        ) );
        register_graphql_field( 'Event', 'officialIsAllDay', array(
            'type'    => 'Boolean',
            'resolve' => static function ( $post ) {
                return '1' === (string) get_post_meta( $post->ID, 'is_official', true )
                    && '1' === (string) get_post_meta( $post->ID, 'official_calendar_managed', true );
            },
        ) );
        register_graphql_field( 'Event', 'officialIsDeadline', array(
            'type'    => 'Boolean',
            'resolve' => static function ( $post ) {
                return '1' === (string) get_post_meta( $post->ID, 'is_official', true )
                    && '1' === (string) get_post_meta( $post->ID, 'official_calendar_managed', true );
            },
        ) );
    }

    private static function applicability_label( $key ) {
        $labels = array(
            'all_companies'              => 'Tüm şirketler',
            'corporate_taxpayer'         => 'Kurumlar vergisi mükellefleri',
            'vat_taxpayer'               => 'KDV mükellefleri',
            'withholding_taxpayer'       => 'Tevkifat / stopaj mükellefleri',
            'employer'                   => 'Çalışanı olan işverenler',
            'e_ledger_user'              => 'e-Defter kullanıcıları',
            'joint_stock_company'        => 'Anonim şirketler',
            'limited_company'            => 'Limited şirketler',
            'physical_commercial_books'  => 'Fiziki ticari defter kullananlar',
        );
        return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
    }

    private static function basis_label( $basis ) {
        $labels = array(
            'verified_gib_2026_calendar' => 'GİB 2026 Vergi Takvimi — doğrulanmış yıllık tarih',
            'sgk_statutory_rule'         => 'SGK resmî ödeme kuralından üretilen tarih',
            'trade_statutory_rule'       => 'Ticaret Bakanlığı / TTK kuralından üretilen tarih',
        );
        return isset( $labels[ $basis ] ) ? $labels[ $basis ] : $basis;
    }
}
