<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-event-public-opportunity-trade-ministry.php';
require_once __DIR__ . '/class-event-public-opportunity-eximbank.php';
require_once __DIR__ . '/class-event-public-opportunity-tkdk-v2.php';
require_once __DIR__ . '/class-event-public-opportunity-digital-europe.php';

/**
 * Aggregates the proven 1.51 KOSGEB/İŞKUR live engine with additional
 * deterministic public-opportunity providers.
 *
 * The existing live stage remains the single owner of merge/upsert/batch
 * semantics. This class only extends prepare-time discovery so duplicate,
 * evidence and fallback behavior stay identical across all providers.
 */
class Sektorel_Event_Public_Opportunity_Extended_Stage {

    const NONCE_ACTION         = 'sektorel_public_opportunities';
    const QUEUE_TTL            = 2 * HOUR_IN_SECONDS;
    const OBSERVABILITY_OPTION = 'sektorel_public_opportunity_observability';

    public static function init() {
        remove_action( 'wp_ajax_sektorel_public_opportunities_prepare', array( 'Sektorel_Event_Public_Opportunity_Live_Stage', 'ajax_prepare' ) );
        remove_action( 'wp_ajax_sektorel_public_opportunities_batch', array( 'Sektorel_Event_Public_Opportunity_Live_Stage', 'ajax_batch' ) );

        add_action( 'wp_ajax_sektorel_public_opportunities_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_public_opportunities_batch', array( __CLASS__, 'ajax_batch' ) );
        add_action( 'wp_ajax_sektorel_public_opportunity_observability', array( __CLASS__, 'ajax_observability' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_observability_script' ), 130 );
        add_filter( 'sektorel_source_background_action_map', array( __CLASS__, 'override_background_action_map' ), 100 );
    }

    public static function override_background_action_map( $map ) {
        $map = is_array( $map ) ? $map : array();
        $map['sektorel_public_opportunities_prepare'] = array( __CLASS__, 'ajax_prepare' );
        $map['sektorel_public_opportunities_batch']   = array( __CLASS__, 'ajax_batch' );
        return $map;
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $year = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) current_time( 'Y' );
        if ( $year < 2026 || $year > ( (int) current_time( 'Y' ) + 1 ) ) {
            wp_send_json_error( array( 'message' => 'Kamu fırsatları yılı geçersiz.' ) );
        }

        $base = self::invoke_live_private( 'discover', array( $year ) );
        if ( is_wp_error( $base ) || ! is_array( $base ) ) {
            wp_send_json_error( array( 'message' => 'Mevcut kamu fırsatı canlı keşif motoru çalıştırılamadı.' ) );
        }

        $rows   = isset( $base['rows'] ) && is_array( $base['rows'] ) ? $base['rows'] : array();
        $errors = isset( $base['errors'] ) && is_array( $base['errors'] ) ? $base['errors'] : array();
        $stats  = isset( $base['stats'] ) && is_array( $base['stats'] ) ? $base['stats'] : array();

        $providers = array(
            'tubitak'              => array( 'Sektorel_Event_Public_Opportunity_Tubitak', 'discover' ),
            'development_agencies' => array( 'Sektorel_Event_Public_Opportunity_Development_Agencies', 'discover' ),
            'trade_ministry'       => array( 'Sektorel_Event_Public_Opportunity_Trade_Ministry', 'discover' ),
            'eximbank'             => array( 'Sektorel_Event_Public_Opportunity_Eximbank', 'discover' ),
            'tkdk'                 => array( 'Sektorel_Event_Public_Opportunity_TKDK', 'discover' ),
            'digital_europe'       => array( 'Sektorel_Event_Public_Opportunity_Digital_Europe', 'discover' ),
        );

        foreach ( $providers as $provider_key => $callback ) {
            if ( ! is_callable( $callback ) ) {
                $errors[] = strtoupper( $provider_key ) . ' provider sınıfı yüklenemedi.';
                continue;
            }

            try {
                $provider = call_user_func( $callback, $year );
            } catch ( Throwable $exception ) {
                $errors[] = strtoupper( $provider_key ) . ' canlı keşif hatası: ' . $exception->getMessage();
                continue;
            }

            if ( ! is_array( $provider ) ) {
                continue;
            }

            if ( ! empty( $provider['rows'] ) && is_array( $provider['rows'] ) ) {
                $rows = array_merge( $rows, $provider['rows'] );
            }
            if ( ! empty( $provider['errors'] ) && is_array( $provider['errors'] ) ) {
                $errors = array_merge( $errors, $provider['errors'] );
            }
            $stats[ $provider_key ] = isset( $provider['stats'] ) && is_array( $provider['stats'] )
                ? $provider['stats']
                : array( 'links' => 0, 'verified' => 0 );
        }

        $deduped = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['occurrence_key'] ) ) {
                continue;
            }
            $deduped[ sanitize_key( $row['occurrence_key'] ) ] = $row;
        }

        $merged = self::invoke_live_private( 'merge_with_verified_catalogue', array( $year, array_values( $deduped ) ) );
        if ( is_wp_error( $merged ) || ! is_array( $merged ) ) {
            wp_send_json_error( array( 'message' => 'Kamu fırsatları doğrulanmış fallback ile birleştirilemedi.' ) );
        }

        self::save_observability_snapshot( $year, $stats, $errors );

        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array(
                'year'            => $year,
                'rows'            => array_values( $merged ),
                'provider_errors' => array_values( array_unique( array_filter( $errors ) ) ),
                'provider_stats'  => $stats,
            ),
            self::QUEUE_TTL
        );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $merged ),
        ) );
    }

    public static function ajax_batch() {
        Sektorel_Event_Public_Opportunity_Live_Stage::ajax_batch();
    }

    public static function ajax_observability() {
        self::require_ajax();

        $snapshot = get_option( self::OBSERVABILITY_OPTION, array() );
        wp_send_json_success(
            array(
                'snapshot' => is_array( $snapshot ) ? $snapshot : array(),
            )
        );
    }

    public static function render_observability_script() {
        if ( ! self::is_source_center_page() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <style>
            .ssc-public-opportunity-observability{grid-column:2/-1;min-width:0;margin-top:0;padding:10px 12px;background:#f6f7f7;border-left:3px solid #2271b1;font-size:11px;line-height:1.55;color:#50575e}
            .ssc-public-opportunity-observability strong{color:#1d2327}
            .ssc-po-list{display:flex;flex-wrap:wrap;gap:5px 14px;margin-top:4px}
            .ssc-po-provider{display:inline-block;white-space:normal}
            .ssc-po-meta{display:block;margin-top:6px;color:#646970}
            #ssc-public-opportunity-summary{max-width:1000px;margin-top:10px;padding:12px 14px;background:#fff;border:1px solid #dcdcde;font-size:12px;line-height:1.6}
            @media(max-width:782px){.ssc-public-opportunity-observability{grid-column:2/-1}.ssc-po-list{gap:4px 10px}}
        </style>
        <script>
        jQuery(function($){
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var lastSignature = '';
            var pollTimer = null;

            function esc(text){ return $('<div>').text(String(text)).html(); }
            function providerHtml(provider){
                return '<span class="ssc-po-provider"><strong>'+esc(provider.label)+'</strong>: '
                    +Number(provider.links||0)+' bağlantı / '+Number(provider.verified||0)+' doğrulandı</span>';
            }
            function providerListHtml(providers){
                return '<div class="ssc-po-list">'+providers.join('')+'</div>';
            }
            function render(snapshot){
                if(!snapshot || !snapshot.providers){ return; }
                var signature=JSON.stringify(snapshot);
                if(signature===lastSignature){ return; }
                lastSignature=signature;

                var providers=[];
                Object.keys(snapshot.providers).forEach(function(key){ providers.push(providerHtml(snapshot.providers[key])); });
                var warningCount=Number(snapshot.warnings_total||0);
                var meta='Son canlı keşif: '+esc(snapshot.checked_at||'—')+' · '+Number(snapshot.year||0);
                if(warningCount){ meta+=' · Uyarı: '+warningCount; }

                var $stage=$('.ssc-stage[data-stage="public_opportunities"]');
                if($stage.length){
                    $stage.find('.ssc-public-opportunity-observability').remove();
                    $stage.append(
                        '<div class="ssc-public-opportunity-observability"><strong>Kaynak görünürlüğü</strong>'
                        +providerListHtml(providers)+'<span class="ssc-po-meta">'+meta+'</span></div>'
                    );
                }

                var $summary=$('#ssc-public-opportunity-summary');
                if(!$summary.length){
                    $summary=$('<div id="ssc-public-opportunity-summary"></div>');
                    $('#ssc-summary').after($summary);
                }
                $summary.html('<strong>Kamu fırsatı kaynakları</strong>'+providerListHtml(providers)+'<span class="ssc-po-meta">'+meta+'</span>');
            }
            function fetchSnapshot(){
                $.post(ajaxurl,{
                    action:'sektorel_public_opportunity_observability',
                    nonce:nonce
                }).done(function(response){
                    if(response && response.success && response.data){ render(response.data.snapshot||{}); }
                });
            }
            function syncPolling(){
                var text=$('.ssc-stage[data-stage="public_opportunities"] .ssc-status').text()||'';
                var active=/Hazırlanıyor|Çalışıyor|Bekliyor/i.test(text);
                if(active && !pollTimer){ pollTimer=window.setInterval(fetchSnapshot,5000); }
                if(!active && pollTimer){ window.clearInterval(pollTimer); pollTimer=null; fetchSnapshot(); }
            }

            fetchSnapshot();
            syncPolling();
            var target=document.querySelector('.ssc-stage[data-stage="public_opportunities"]');
            if(target && window.MutationObserver){
                new MutationObserver(syncPolling).observe(target,{childList:true,subtree:true,characterData:true});
            }
        });
        </script>
        <?php
    }

    private static function save_observability_snapshot( $year, $stats, $errors ) {
        $labels = array(
            'kosgeb'               => 'KOSGEB',
            'iskur'                => 'İŞKUR',
            'tubitak'              => 'TÜBİTAK',
            'development_agencies' => 'Kalkınma Ajansları',
            'trade_ministry'       => 'Ticaret Bakanlığı',
            'eximbank'             => 'Türk Eximbank',
            'tkdk'                 => 'TKDK / IPARD',
            'digital_europe'       => 'Dijital Avrupa',
        );

        $providers = array();
        foreach ( $labels as $key => $label ) {
            $stat = isset( $stats[ $key ] ) && is_array( $stats[ $key ] ) ? $stats[ $key ] : array();
            $providers[ $key ] = array(
                'label'    => $label,
                'links'    => absint( isset( $stat['links'] ) ? $stat['links'] : 0 ),
                'verified' => absint( isset( $stat['verified'] ) ? $stat['verified'] : 0 ),
            );
        }

        $warnings = array_values(
            array_unique(
                array_filter(
                    array_map( 'sanitize_text_field', (array) $errors )
                )
            )
        );

        update_option(
            self::OBSERVABILITY_OPTION,
            array(
                'version'          => 3,
                'year'             => absint( $year ),
                'checked_at'       => current_time( 'mysql' ),
                'providers'        => $providers,
                'warnings_total'   => count( $warnings ),
                'warning_messages' => array_slice( $warnings, 0, 12 ),
            ),
            false
        );
    }

    private static function invoke_live_private( $method_name, $arguments ) {
        try {
            $method = new ReflectionMethod( 'Sektorel_Event_Public_Opportunity_Live_Stage', $method_name );
            if ( method_exists( $method, 'setAccessible' ) ) {
                $method->setAccessible( true );
            }
            return $method->invokeArgs( null, (array) $arguments );
        } catch ( Throwable $exception ) {
            return new WP_Error( 'public_opportunity_extension_reflection_error', $exception->getMessage() );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_public_opportunity_live_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function is_source_center_page() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return 'event' === $post_type && 'sektorel-source-center' === $page;
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }
}
