<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unified operational surface for event-source ingestion.
 *
 * Source-specific screens remain addressable for diagnostics/settings, but the
 * daily workflow is intentionally one page + one primary action. Adapters can
 * join the pipeline through `sektorel_source_center_stages` without creating a
 * new WordPress submenu.
 *
 * Stage contract:
 * - prepare AJAX returns { token, total }
 * - batch AJAX accepts { token, offset } and returns { next_offset, done }
 * - batch may also return any of: created, updated, unchanged, skipped,
 *   ok, error, failed, changed and messages[]
 */
class Sektorel_Event_Source_Center {

    const MENU_SLUG = 'sektorel-source-center';

    private static $parser_guard = false;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 18 );
        add_action( 'admin_menu', array( __CLASS__, 'hide_legacy_operational_menus' ), 999 );

        // Adapter sources must never fall back into generic JSON-LD / HTML scans.
        add_action( 'added_post_meta', array( __CLASS__, 'guard_adapter_parser_hint' ), 200, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'guard_adapter_parser_hint' ), 200, 4 );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'Kaynak Merkezi',
            'Kaynak Merkezi',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function hide_legacy_operational_menus() {
        $parent = 'edit.php?post_type=event';

        foreach ( array(
            'sektorel-event-source-check',
            'sektorel-event-source-health',
            'sektorel-source-target-discovery',
            'sektorel-jsonld-events',
            'sektorel-html-events',
            'sektorel-tobb-fairs',
            'sektorel-tobb-taxonomy-mapping',
        ) as $slug ) {
            remove_submenu_page( $parent, $slug );
        }

        // Older importer revisions used more than one slug. Remove their menu
        // rows by visible label while leaving the registered page callbacks
        // reachable from advanced links when needed.
        global $submenu;
        if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
            return;
        }

        $hidden_labels = array(
            'Kaynak İçeri Aktar',
            'Kaynak Kontrolü',
            'Kaynak Sağlığı',
            'Kaynak Hedeflerini Keşfet',
            'JSON-LD Tara',
            'HTML Tara',
            'TOBB Fuarları',
            'TOBB Eşlemeleri',
        );

        foreach ( $submenu[ $parent ] as $index => $item ) {
            $label = isset( $item[0] ) ? trim( wp_strip_all_tags( (string) $item[0] ) ) : '';
            if ( in_array( $label, $hidden_labels, true ) ) {
                unset( $submenu[ $parent ][ $index ] );
            }
        }
    }

    public static function guard_adapter_parser_hint( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$parser_guard || 'detected_parser' !== $meta_key || 'event_source' !== get_post_type( $object_id ) ) {
            return;
        }

        if ( 'adapter' !== (string) get_post_meta( $object_id, 'parser_type', true ) || 'adapter' === (string) $meta_value ) {
            return;
        }

        self::$parser_guard = true;
        update_post_meta( absint( $object_id ), 'detected_parser', 'adapter' );
        self::$parser_guard = false;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        self::normalize_existing_adapter_hints();

        $counts = self::counts();
        $stages = self::stages();
        ?>
        <div class="wrap sektorel-source-center">
            <h1>Kaynak Merkezi</h1>
            <p style="max-width:980px;">Günlük etkinlik toplama operasyonunun tek ekranıdır. Aktif kaynaklar doğrulanır, kaynağa özel adapterlar ve güvenli generic parserlar sırayla çalışır. Sonuçlar <strong>Aday Etkinlikler</strong> havuzuna yazılır; hiçbir kayıt otomatik yayınlanmaz.</p>

            <style>
                .sektorel-source-center .ssc-stats{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:12px;max-width:1000px;margin:20px 0}
                .sektorel-source-center .ssc-stat{background:#fff;border:1px solid #dcdcde;padding:16px 18px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
                .sektorel-source-center .ssc-stat-label{font-size:12px;text-transform:uppercase;color:#646970;font-weight:700}
                .sektorel-source-center .ssc-stat-value{font-size:28px;font-weight:700;margin-top:3px}
                .sektorel-source-center .ssc-main{max-width:1000px;padding:24px}
                .sektorel-source-center .ssc-stage{display:grid;grid-template-columns:32px minmax(180px,1fr) 120px minmax(180px,1fr);gap:12px;align-items:center;padding:12px 0;border-top:1px solid #e2e4e7}
                .sektorel-source-center .ssc-stage:first-child{border-top:0}
                .sektorel-source-center .ssc-step{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#f0f0f1;font-weight:700}
                .sektorel-source-center .ssc-status{font-weight:600;color:#646970}
                .sektorel-source-center .ssc-status.running{color:#2271b1}.sektorel-source-center .ssc-status.done{color:#008a20}.sektorel-source-center .ssc-status.failed{color:#b32d2e}.sektorel-source-center .ssc-status.skipped{color:#996800}
                .sektorel-source-center .ssc-result{color:#646970;font-size:12px}
                .sektorel-source-center .ssc-progress{height:24px;background:#e2e4e7;overflow:hidden;margin-top:20px;display:none}
                .sektorel-source-center .ssc-progress>div{height:100%;width:0;background:#2271b1;transition:width .2s}
                .sektorel-source-center .ssc-log{display:none;margin-top:18px;max-height:320px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:14px;font:12px/1.6 monospace}
                .sektorel-source-center .ssc-summary{display:none;margin-top:18px;padding:16px;background:#f6f7f7;border-left:4px solid #00a32a}
                .sektorel-source-center .ssc-advanced{max-width:1000px;margin-top:18px}
                @media(max-width:782px){.sektorel-source-center .ssc-stats{grid-template-columns:1fr 1fr}.sektorel-source-center .ssc-stage{grid-template-columns:32px 1fr}.sektorel-source-center .ssc-stage .ssc-status,.sektorel-source-center .ssc-stage .ssc-result{grid-column:2}}
            </style>

            <div class="ssc-stats">
                <?php self::stat( 'Toplam Kaynak', $counts['sources'] ); ?>
                <?php self::stat( 'Aktif Kaynak', $counts['active_sources'] ); ?>
                <?php self::stat( 'Aday Etkinlik', $counts['candidates'] ); ?>
                <?php self::stat( 'Adapter', $counts['adapters'] ); ?>
            </div>

            <div class="card ssc-main">
                <h2 style="margin-top:0;">Tüm Kaynakları Tara</h2>
                <p>Tek buton mevcut pipeline aşamalarını sırayla çalıştırır. Bu sürüm mevcut güvenli AJAX batch motorlarını orkestre eder; işlem tamamlanana kadar bu sekmenin açık kalması gerekir.</p>

                <p style="margin:20px 0;">
                    <button type="button" class="button button-primary button-hero" id="ssc-start">Tüm Kaynakları Tara</button>
                </p>

                <div id="ssc-stages">
                    <?php foreach ( $stages as $index => $stage ) : ?>
                        <div class="ssc-stage" data-stage="<?php echo esc_attr( $stage['key'] ); ?>">
                            <div class="ssc-step"><?php echo esc_html( (string) ( $index + 1 ) ); ?></div>
                            <div><strong><?php echo esc_html( $stage['label'] ); ?></strong><br><span class="description"><?php echo esc_html( $stage['description'] ); ?></span></div>
                            <div class="ssc-status">Bekliyor</div>
                            <div class="ssc-result">—</div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="ssc-progress" id="ssc-progress"><div id="ssc-progress-bar"></div></div>
                <div class="ssc-summary" id="ssc-summary"></div>
                <div class="ssc-log" id="ssc-log"></div>
            </div>

            <details class="card ssc-advanced" style="padding:18px;">
                <summary style="cursor:pointer;font-weight:700;">Gelişmiş kaynak ayarları</summary>
                <p style="margin-bottom:0;">Günlük kullanımda bu ekranlara girmen gerekmez. Kaynak ekleme, mapping veya hata ayıklama gerektiğinde kullanılır.</p>
                <p>
                    <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=event_source' ) ); ?>">Etkinlik Kaynakları</a>
                    <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=event&page=sektorel-tobb-taxonomy-mapping' ) ); ?>">TOBB Sektör / Şehir Eşlemeleri</a>
                    <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=event_candidate' ) ); ?>">Aday Etkinlikler</a>
                </p>
            </details>
        </div>

        <script>
        jQuery(function($){
            var stages = <?php echo wp_json_encode( $stages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); ?>;
            var running = false;
            var overall = {created:0,updated:0,unchanged:0,skipped:0,error:0,failed:0,ok:0,changed:0};
            var stageResults = {};

            function esc(text){ return $('<div>').text(String(text)).html(); }
            function log(message,isError){
                var $log=$('#ssc-log');
                $log.show().append('<div style="color:'+(isError?'#ff8080':'#f0f0f1')+'">'+esc(message)+'</div>');
                $log.scrollTop($log[0].scrollHeight);
            }
            function setStatus(stage,status,label,result){
                var $row=$('.ssc-stage[data-stage="'+stage.key+'"]');
                $row.find('.ssc-status').removeClass('running done failed skipped').addClass(status).text(label);
                if(result!==undefined){$row.find('.ssc-result').text(result);}
            }
            function updateProgress(completed){
                var total=stages.length||1;
                $('#ssc-progress').show();
                $('#ssc-progress-bar').css('width',Math.round((completed/total)*100)+'%');
            }
            function addStats(target,data){
                ['created','updated','unchanged','skipped','error','failed','ok','changed'].forEach(function(key){
                    var value=Number((data&&data[key])||0);
                    target[key]=(target[key]||0)+value;
                    overall[key]=(overall[key]||0)+value;
                });
            }
            function resultText(stats,total){
                var parts=[];
                if(total!==undefined){parts.push('Kayıt: '+Number(total||0));}
                if(stats.created){parts.push('Yeni: '+stats.created);}
                if(stats.updated){parts.push('Güncel: '+stats.updated);}
                if(stats.unchanged){parts.push('Değişmedi: '+stats.unchanged);}
                if(stats.changed){parts.push('Değişti: '+stats.changed);}
                if(stats.ok){parts.push('OK: '+stats.ok);}
                if(stats.skipped){parts.push('Atlandı: '+stats.skipped);}
                if(stats.error){parts.push('Hata: '+stats.error);}
                if(stats.failed){parts.push('Başarısız: '+stats.failed);}
                return parts.length?parts.join(' · '):'Tamamlandı';
            }
            function finishAll(){
                running=false;
                $('#ssc-start').prop('disabled',false).text('Tüm Kaynakları Yeniden Tara');
                $('#ssc-progress-bar').css('width','100%').css('background','#00a32a');
                var failures=Number(overall.error||0)+Number(overall.failed||0);
                $('#ssc-summary').show().html(
                    '<strong>Kaynak taraması tamamlandı.</strong><br>'+
                    'Yeni aday: <strong>'+overall.created+'</strong> &nbsp; '+
                    'Güncellendi: <strong>'+overall.updated+'</strong> &nbsp; '+
                    'Değişmedi/atlandı: <strong>'+(overall.unchanged+overall.skipped)+'</strong> &nbsp; '+
                    'Hata: <strong>'+failures+'</strong><br><br>'+
                    '<a class="button" href="edit.php?post_type=event_candidate">Aday Etkinlikleri Gör</a>'
                );
                log('Tüm kaynak pipeline aşamaları tamamlandı.',false);
            }
            function runBatch(stage,token,total,offset,stats,doneCallback){
                var payload={action:stage.batch_action,nonce:stage.nonce,token:token,offset:offset};
                $.post(ajaxurl,payload).done(function(r){
                    if(!r||!r.success){
                        var msg=r&&r.data&&r.data.message?r.data.message:'Batch isteği başarısız.';
                        setStatus(stage,'failed','Hata',msg); log(stage.label+': '+msg,true); doneCallback(false); return;
                    }
                    addStats(stats,r.data||{});
                    (r.data.messages||[]).forEach(function(m){log(stage.label+': '+m,false);});
                    var next=Number(r.data.next_offset||total);
                    setStatus(stage,'running','Çalışıyor',next+' / '+total);
                    if(r.data.done){
                        stageResults[stage.key]=stats;
                        setStatus(stage,'done','Tamamlandı',resultText(stats,total));
                        doneCallback(true);
                    }else{
                        window.setTimeout(function(){runBatch(stage,token,total,next,stats,doneCallback);},180);
                    }
                }).fail(function(){
                    setStatus(stage,'failed','Hata','Sunucu isteği başarısız');
                    log(stage.label+': sunucu isteği başarısız.',true); doneCallback(false);
                });
            }
            function runStage(index){
                if(index>=stages.length){finishAll();return;}
                var stage=stages[index],stats={created:0,updated:0,unchanged:0,skipped:0,error:0,failed:0,ok:0,changed:0};
                setStatus(stage,'running','Hazırlanıyor','Kuyruk oluşturuluyor…');
                log(stage.label+' hazırlanıyor…',false);

                var payload=$.extend({},stage.prepare_payload||{}, {action:stage.prepare_action,nonce:stage.nonce});
                $.post(ajaxurl,payload).done(function(r){
                    if(!r||!r.success){
                        var msg=r&&r.data&&r.data.message?r.data.message:'Kuyruk hazırlanamadı.';
                        setStatus(stage,'skipped','Atlandı',msg);
                        log(stage.label+' atlandı: '+msg,false);
                        updateProgress(index+1);
                        window.setTimeout(function(){runStage(index+1);},120);
                        return;
                    }
                    var token=String(r.data.token||''),total=Number(r.data.total||0);
                    if(!token||total<1){
                        setStatus(stage,'skipped','Atlandı','İşlenecek kayıt yok');
                        updateProgress(index+1);
                        window.setTimeout(function(){runStage(index+1);},120);
                        return;
                    }
                    setStatus(stage,'running','Çalışıyor','0 / '+total);
                    runBatch(stage,token,total,0,stats,function(){
                        updateProgress(index+1);
                        window.setTimeout(function(){runStage(index+1);},150);
                    });
                }).fail(function(){
                    setStatus(stage,'failed','Hata','Hazırlık isteği başarısız');
                    log(stage.label+': hazırlık isteği başarısız.',true);
                    updateProgress(index+1);
                    window.setTimeout(function(){runStage(index+1);},150);
                });
            }

            $('#ssc-start').on('click',function(){
                if(running)return;
                running=true; overall={created:0,updated:0,unchanged:0,skipped:0,error:0,failed:0,ok:0,changed:0};stageResults={};
                $('#ssc-summary').hide().empty();$('#ssc-log').empty().show();$('#ssc-progress-bar').css({width:'0',background:'#2271b1'});$('#ssc-progress').show();
                $('.ssc-stage .ssc-status').removeClass('running done failed skipped').text('Bekliyor');$('.ssc-stage .ssc-result').text('—');
                $(this).prop('disabled',true).text('Tüm Kaynaklar Taranıyor…');
                log('Tek tuş kaynak pipeline başlatıldı.',false);runStage(0);
            });
        });
        </script>
        <?php
    }

    private static function stages() {
        $year = (int) current_time( 'Y' );

        $stages = array(
            array(
                'key'             => 'source_check',
                'label'           => 'Kaynakları Doğrula',
                'description'     => 'Aktif kaynakların erişilebilirlik ve parser sinyalini kontrol eder.',
                'prepare_action'  => 'sektorel_event_source_prepare_checks',
                'batch_action'    => 'sektorel_event_source_check_batch',
                'nonce'           => wp_create_nonce( 'sektorel_event_source_check' ),
                'prepare_payload' => array(),
            ),
            array(
                'key'             => 'tobb',
                'label'           => 'TOBB Fuar Takvimi',
                'description'     => 'Canonical fuar occurrence kayıtlarını aday havuzuna günceller.',
                'prepare_action'  => 'sektorel_tobb_prepare',
                'batch_action'    => 'sektorel_tobb_import_batch',
                'nonce'           => wp_create_nonce( 'sektorel_tobb_fair_calendar' ),
                'prepare_payload' => array( 'year' => $year, 'upcoming_only' => 1 ),
            ),
            array(
                'key'             => 'jsonld',
                'label'           => 'JSON-LD Kaynakları',
                'description'     => 'Adapter olmayan yapılandırılmış Event verilerini adaylara işler.',
                'prepare_action'  => 'sektorel_prepare_jsonld_scan',
                'batch_action'    => 'sektorel_jsonld_scan_batch',
                'nonce'           => wp_create_nonce( 'sektorel_event_candidate_jsonld' ),
                'prepare_payload' => array(),
            ),
            array(
                'key'             => 'html',
                'label'           => 'HTML Kaynakları',
                'description'     => 'Güvenli generic HTML kaynaklarında aday etkinlik keşfi yapar.',
                'prepare_action'  => 'sektorel_prepare_html_event_scan',
                'batch_action'    => 'sektorel_html_event_scan_batch',
                'nonce'           => wp_create_nonce( 'sektorel_event_candidate_html' ),
                'prepare_payload' => array(),
            ),
        );

        $stages = apply_filters( 'sektorel_source_center_stages', $stages );

        $clean = array();
        foreach ( (array) $stages as $stage ) {
            if ( empty( $stage['key'] ) || empty( $stage['label'] ) || empty( $stage['prepare_action'] ) || empty( $stage['batch_action'] ) || empty( $stage['nonce'] ) ) {
                continue;
            }
            $stage['key']             = sanitize_key( $stage['key'] );
            $stage['label']           = sanitize_text_field( $stage['label'] );
            $stage['description']     = isset( $stage['description'] ) ? sanitize_text_field( $stage['description'] ) : '';
            $stage['prepare_action']  = sanitize_key( $stage['prepare_action'] );
            $stage['batch_action']    = sanitize_key( $stage['batch_action'] );
            $stage['nonce']           = sanitize_text_field( $stage['nonce'] );
            $stage['prepare_payload'] = isset( $stage['prepare_payload'] ) && is_array( $stage['prepare_payload'] ) ? $stage['prepare_payload'] : array();
            $clean[] = $stage;
        }

        return $clean;
    }

    private static function normalize_existing_adapter_hints() {
        $ids = get_posts(
            array(
                'post_type'      => 'event_source',
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'fields'         => 'ids',
                'meta_key'       => 'parser_type',
                'meta_value'     => 'adapter',
                'no_found_rows'  => true,
            )
        );

        foreach ( $ids as $source_id ) {
            if ( 'adapter' !== (string) get_post_meta( $source_id, 'detected_parser', true ) ) {
                update_post_meta( absint( $source_id ), 'detected_parser', 'adapter' );
            }
        }
    }

    private static function counts() {
        $source_ids = get_posts(
            array(
                'post_type'      => 'event_source',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            )
        );

        $active = 0;
        $adapters = 0;
        foreach ( $source_ids as $source_id ) {
            if ( 'active' === (string) get_post_meta( $source_id, 'source_status', true ) ) {
                $active++;
            }
            if ( 'adapter' === (string) get_post_meta( $source_id, 'parser_type', true ) ) {
                $adapters++;
            }
        }

        $candidate_counts = wp_count_posts( 'event_candidate' );
        $candidates = 0;
        if ( $candidate_counts ) {
            foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
                $candidates += isset( $candidate_counts->{$status} ) ? absint( $candidate_counts->{$status} ) : 0;
            }
        }

        return array(
            'sources'        => count( $source_ids ),
            'active_sources' => $active,
            'adapters'       => $adapters,
            'candidates'     => $candidates,
        );
    }

    private static function stat( $label, $value ) {
        echo '<div class="ssc-stat"><div class="ssc-stat-label">' . esc_html( $label ) . '</div><div class="ssc-stat-value">' . esc_html( (string) $value ) . '</div></div>';
    }
}
