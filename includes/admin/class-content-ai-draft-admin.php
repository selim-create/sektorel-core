<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Admin UI for manual, bounded Content AI draft generation. */
class Sektorel_Content_AI_Draft_Admin {

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_footer', array( __CLASS__, 'render_source_center_panel' ) );
    }

    public static function render_source_center_panel() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'sektorel-content-source-center' !== $page ) {
            return;
        }

        $enabled = Sektorel_Content_AI_Draft_Processor::is_enabled();
        $media_enabled = class_exists( 'Sektorel_Core_Settings' ) && '' !== Sektorel_Core_Settings::secret( 'pexels_api_key' );
        $nonce = Sektorel_Content_AI_Draft_Processor::nonce();
        ?>
        <script>
        jQuery(function($){
            var enabled=<?php echo $enabled ? 'true' : 'false'; ?>;
            var mediaEnabled=<?php echo $media_enabled ? 'true' : 'false'; ?>;
            var html=''+
                '<div class="sektorel-panel" id="sektorel-content-ai-draft-panel">'+
                '<h2 style="margin-top:0">AI Editoryal Taslak Üretimi</h2>'+
                '<p>Yalnız <strong>ready</strong> candidate kayıtlarını işler. Kaynak metnini zenginleştirir; AI ile editoryal haber taslağı, Rank Math SEO alanları ve görsel arama sorgusu üretir. Uygun Pexels anahtarı varsa landscape featured image da eklenir. WordPress\'e yalnız <strong>draft</strong> olarak kaydeder; otomatik yayın yapılmaz.</p>'+
                '<p><strong>Güvenlik:</strong> işlem manuel başlatılır, tek tıklamada en fazla 5 candidate işlenir ve günlük limit uygulanır.</p>'+
                (mediaEnabled
                    ? '<p><strong>Medya:</strong> Pexels featured image servisi hazır.</p>'
                    : '<p><strong>Medya uyarısı:</strong> Pexels API anahtarı tanımlı değil; taslak ve SEO üretilir ancak featured image eklenemez.</p>')+
                (enabled
                    ? '<p><button type="button" class="button button-primary" id="sektorel-content-ai-draft-run">5 Hazır Candidate\'dan Taslak Üret</button> <button type="button" class="button" id="sektorel-content-ai-draft-repair">Son 5 AI Taslağının SEO + Görselini Tamamla</button></p>'
                    : '<p><strong>AI kapalı.</strong> <code>SEKTOREL_OPENAI_API_KEY</code> tanımlandığında yeni taslak üretimi etkinleşir. Mevcut taslak onarımı AI tokenı kullanmaz.</p><p><button type="button" class="button" id="sektorel-content-ai-draft-repair">Son 5 AI Taslağının SEO + Görselini Tamamla</button></p>')+
                '<div id="sektorel-content-ai-draft-summary" style="display:none;margin-top:12px;padding:12px;background:#f6f7f7;border-left:4px solid #2271b1"></div>'+
                '<div id="sektorel-content-ai-draft-log" class="sektorel-console"></div>'+
                '</div>';

            var $triage=$('#sektorel-content-triage-all').closest('.sektorel-panel');
            if($triage.length){$triage.after(html);}else{$('.wrap h1').after(html);}

            function log(msg,error){
                var $log=$('#sektorel-content-ai-draft-log').show();
                $log.append('<div style="color:'+(error?'#ff8080':'#f0f0f1')+'">'+$('<div>').text(msg).html()+'</div>');
                $log.scrollTop($log[0].scrollHeight);
            }

            if(enabled){
                $('#sektorel-content-ai-draft-run').on('click',function(){
                    var $btn=$(this),$summary=$('#sektorel-content-ai-draft-summary');
                    $btn.prop('disabled',true).text('Taslaklar hazırlanıyor...');
                    $summary.hide();$('#sektorel-content-ai-draft-log').empty().show();
                    $.post(ajaxurl,{action:'sektorel_content_ai_draft_batch',nonce:'<?php echo esc_js( $nonce ); ?>'}).done(function(r){
                        if(!r||!r.success){
                            var msg=r&&r.data&&r.data.message?r.data.message:'AI draft isteği başarısız.';
                            log(msg,true);$btn.prop('disabled',false).text('5 Hazır Candidate\'dan Taslak Üret');return;
                        }
                        (r.data.messages||[]).forEach(function(m){log(m,m.indexOf('Hata')===0||m.indexOf('görsel eklenemedi')>-1);});
                        $summary.show().html(
                            '<strong>Batch tamamlandı.</strong><br>'+ 
                            'İşlenen: <strong>'+Number(r.data.processed||0)+'</strong> &nbsp; '+
                            'Draft: <strong>'+Number(r.data.drafted||0)+'</strong> &nbsp; '+
                            'Featured image: <strong>'+Number(r.data.images_added||0)+'</strong> &nbsp; '+
                            'Görsel hatası: <strong>'+Number(r.data.image_errors||0)+'</strong> &nbsp; '+
                            'Hata: <strong>'+Number(r.data.errors||0)+'</strong> &nbsp; '+
                            'Atlanan: <strong>'+Number(r.data.skipped||0)+'</strong> &nbsp; '+
                            'Kalan: <strong>'+Number(r.data.remaining||0)+'</strong>'+
                            (r.data.daily_limit?'<br>Günlük kullanım: <strong>'+Number(r.data.daily_used||0)+' / '+Number(r.data.daily_limit||0)+'</strong>':'')+
                            '<br><br><button class="button" onclick="window.location.reload()">Sonuçları Yenile</button>'
                        );
                        $btn.prop('disabled',false).text('5 Hazır Candidate\'dan Taslak Üret');
                    }).fail(function(){
                        log('Sunucu AI draft isteği başarısız oldu.',true);
                        $btn.prop('disabled',false).text('5 Hazır Candidate\'dan Taslak Üret');
                    });
                });
            }

            $('#sektorel-content-ai-draft-repair').on('click',function(){
                var $btn=$(this),$summary=$('#sektorel-content-ai-draft-summary');
                $btn.prop('disabled',true).text('Taslaklar tamamlanıyor...');
                $summary.hide();$('#sektorel-content-ai-draft-log').empty().show();
                $.post(ajaxurl,{action:'sektorel_content_ai_repair_drafts',nonce:'<?php echo esc_js( $nonce ); ?>'}).done(function(r){
                    if(!r||!r.success){
                        var msg=r&&r.data&&r.data.message?r.data.message:'Taslak tamamlama isteği başarısız.';
                        log(msg,true);$btn.prop('disabled',false).text('Son 5 AI Taslağının SEO + Görselini Tamamla');return;
                    }
                    (r.data.messages||[]).forEach(function(m){log(m,m.indexOf('görsel eklenemedi')>-1);});
                    $summary.show().html(
                        '<strong>Taslak tamamlama bitti.</strong><br>'+ 
                        'Tamamlanan: <strong>'+Number(r.data.repaired||0)+'</strong> &nbsp; '+
                        'Yeni featured image: <strong>'+Number(r.data.images_added||0)+'</strong> &nbsp; '+
                        'Görsel hatası: <strong>'+Number(r.data.image_errors||0)+'</strong>'+
                        '<br><br><button class="button" onclick="window.location.reload()">Sonuçları Yenile</button>'
                    );
                    $btn.prop('disabled',false).text('Son 5 AI Taslağının SEO + Görselini Tamamla');
                }).fail(function(){
                    log('Sunucu taslak tamamlama isteği başarısız oldu.',true);
                    $btn.prop('disabled',false).text('Son 5 AI Taslağının SEO + Görselini Tamamla');
                });
            });
        });
        </script>
        <?php
    }
}
