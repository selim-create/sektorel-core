<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Canonical safe HTML scan queue + scan page renderer.
 *
 * Core 1.30.0 introduced the safe queue at AJAX prepare time, while the legacy
 * scan page continued to render the raw HTML source count. This class makes the
 * visible count and the actual queue use the same source_ids() helper.
 */
class Sektorel_Event_HTML_Safe_Queue {

    public static function init() {
        // Run before the older confidence prepare callback at priority 5.
        add_action( 'wp_ajax_sektorel_prepare_html_event_scan', array( __CLASS__, 'prepare_scan' ), 4 );

        // The legacy HTML class registers its submenu at priority 41. Replace
        // that page only after registration has actually happened, so there is
        // exactly one submenu row and one page-render callback.
        add_action( 'admin_menu', array( __CLASS__, 'replace_scan_page' ), 42 );
    }

    public static function source_ids() {
        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'source_status', 'value' => 'active' ),
                array( 'key' => 'check_state', 'value' => 'ok' ),
                array( 'key' => 'detected_parser', 'value' => 'html' ),
            ),
        ) );

        $safe = array();

        foreach ( $ids as $source_id ) {
            $source_id = absint( $source_id );
            $type      = self::normalize( get_post_meta( $source_id, 'source_type', true ) );
            $target    = (string) get_post_meta( $source_id, 'target_discovery_status', true );
            $url       = trim( (string) get_post_meta( $source_id, 'source_url', true ) );

            if ( false === strpos( $type, 'resmi kurum' ) ) {
                $safe[] = $source_id;
                continue;
            }

            if ( 'verified_listing' === $target || self::looks_like_listing_url( $url ) ) {
                $safe[] = $source_id;
            }
        }

        return array_values( array_unique( array_map( 'absint', $safe ) ) );
    }

    public static function prepare_scan() {
        check_ajax_referer( 'sektorel_event_candidate_html', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $ids = self::source_ids();
        if ( ! $ids ) {
            wp_send_json_error( array( 'message' => 'Güvenli HTML taramasına uygun kaynak bulunamadı.' ) );
        }

        $token = strtolower( wp_generate_password( 24, false, false ) );
        $key   = 'sektorel_html_' . absint( get_current_user_id() ) . '_' . sanitize_key( $token );
        set_transient( $key, $ids, 2 * HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'token'      => $token,
            'total'      => count( $ids ),
            'safe_queue' => true,
        ) );
    }

    public static function replace_scan_page() {
        $parent = 'edit.php?post_type=event';
        $slug   = 'sektorel-html-events';

        // At priority 42 the legacy submenu/page hook already exists. Remove
        // both the menu row and every renderer attached to that exact page
        // hook, then register the canonical safe renderer once.
        $legacy_hook = function_exists( 'get_plugin_page_hookname' )
            ? get_plugin_page_hookname( $slug, $parent )
            : '';

        if ( $legacy_hook ) {
            remove_all_actions( $legacy_hook );
        }

        remove_submenu_page( $parent, $slug );

        add_submenu_page(
            $parent,
            'HTML Etkinliklerini Tara',
            'HTML Tara',
            'manage_options',
            $slug,
            array( __CLASS__, 'render_scan_page' )
        );
    }

    public static function render_scan_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        $nonce = wp_create_nonce( 'sektorel_event_candidate_html' );
        $ids   = self::source_ids();
        ?>
        <div class="wrap">
            <h1>HTML Etkinliklerini Tara</h1>
            <p>Erişilebilir HTML kaynaklarından etkinlik adaylarını generic kurallarla keşfeder. Bulunan kayıtlar yalnızca Aday Etkinlikler havuzuna yazılır; otomatik yayın yapılmaz.</p>
            <div class="card" style="max-width:900px;padding:22px;">
                <p><strong><?php echo esc_html( count( $ids ) ); ?></strong> güvenli HTML kaynağı taramaya hazır.</p>
                <p>Yanlış pozitifleri azaltmak için yalnızca etkinlik adı, başlangıç tarihi ve etkinlik bağlamı birlikte tespit edilebilen kayıtlar aday olarak oluşturulur.</p>
                <p><button type="button" class="button button-primary button-hero" id="sektorel-html-start">HTML Kaynaklarını Tara</button></p>
                <div id="sektorel-html-progress" style="display:none;margin-top:20px;">
                    <div style="height:22px;background:#e2e4e7;overflow:hidden;"><div id="sektorel-html-bar" style="width:0;height:100%;background:#2271b1;"></div></div>
                    <p><strong id="sektorel-html-count">0 / 0</strong></p>
                </div>
                <div id="sektorel-html-summary" style="display:none;margin-top:16px;padding:14px;background:#f6f7f7;border-left:4px solid #2271b1;"></div>
                <div id="sektorel-html-log" style="display:none;margin-top:16px;max-height:300px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace;"></div>
            </div>
        </div>
        <script>
        jQuery(function($){
            var token='',total=0,offset=0,running=false,totals={created:0,updated:0,unchanged:0,skipped:0,error:0};
            function log(m,e){var l=$('#sektorel-html-log');l.show().append('<div style="color:'+(e?'#ff8080':'#f0f0f1')+'">'+$('<div>').text(m).html()+'</div>');l.scrollTop(l[0].scrollHeight);}
            function progress(){var p=total?Math.min(100,Math.round((offset/total)*100)):0;$('#sektorel-html-progress').show();$('#sektorel-html-bar').css('width',p+'%');$('#sektorel-html-count').text(offset+' / '+total);}
            function fail(m){running=false;$('#sektorel-html-start').prop('disabled',false).text('Tekrar Dene');log(m,true);}
            function finish(){running=false;$('#sektorel-html-start').prop('disabled',false).text('Yeniden Tara');$('#sektorel-html-bar').css('width','100%').css('background','#00a32a');$('#sektorel-html-summary').show().html('<strong>Tarama tamamlandı.</strong><br>Yeni aday: <strong>'+totals.created+'</strong> &nbsp; Güncellendi: <strong>'+totals.updated+'</strong> &nbsp; Değişmedi: <strong>'+totals.unchanged+'</strong> &nbsp; Atlandı: <strong>'+totals.skipped+'</strong> &nbsp; Hata: <strong>'+totals.error+'</strong><br><br><a class="button" href="edit.php?post_type=event_candidate">Aday Etkinlikleri Gör</a>');log('Tüm HTML kaynak kuyruğu işlendi.',false);}
            function next(){$.post(ajaxurl,{action:'sektorel_html_event_scan_batch',nonce:'<?php echo esc_js( $nonce ); ?>',token:token,offset:offset}).done(function(r){if(!r||!r.success){fail(r&&r.data&&r.data.message?r.data.message:'HTML batch başarısız.');return;}totals.created+=Number(r.data.created||0);totals.updated+=Number(r.data.updated||0);totals.unchanged+=Number(r.data.unchanged||0);totals.skipped+=Number(r.data.skipped||0);totals.error+=Number(r.data.error||0);offset=Number(r.data.next_offset||total);progress();(r.data.messages||[]).forEach(function(m){log(m,false);});if(r.data.done){finish();}else{window.setTimeout(next,250);}}).fail(function(){fail('Sunucu isteği başarısız.');});}
            $('#sektorel-html-start').on('click',function(){if(running)return;running=true;token='';total=0;offset=0;totals={created:0,updated:0,unchanged:0,skipped:0,error:0};$('#sektorel-html-summary').hide().empty();$('#sektorel-html-log').show().empty();$('#sektorel-html-bar').css('background','#2271b1');$(this).prop('disabled',true).text('Kuyruk Hazırlanıyor...');$.post(ajaxurl,{action:'sektorel_prepare_html_event_scan',nonce:'<?php echo esc_js( $nonce ); ?>'}).done(function(r){if(!r||!r.success){fail(r&&r.data&&r.data.message?r.data.message:'HTML kuyruğu hazırlanamadı.');return;}token=r.data.token;total=Number(r.data.total||0);progress();$('#sektorel-html-start').text('Taranıyor...');log(total+' HTML kaynağı kuyruğa alındı.',false);next();}).fail(function(){fail('Kuyruk isteği başarısız.');});});
        });
        </script>
        <?php
    }

    private static function looks_like_listing_url( $url ) {
        $path = self::normalize( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
        if ( ! $path || '/' === trim( $path ) ) {
            return false;
        }

        if ( preg_match( '/\b(about|hakkinda|kurallar|rules|support|haber|news|blog|press|basin bulten|iletisim|contact|site haritasi)\b/i', $path ) ) {
            return false;
        }

        return (bool) preg_match( '/\b(etkinlik|etkinlikler|event|events|takvim|calendar|agenda|fuarlar|webinar|seminar)\b/i', $path );
    }

    private static function normalize( $value ) {
        $value = strtolower( remove_accents( trim( (string) $value ) ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }
}
