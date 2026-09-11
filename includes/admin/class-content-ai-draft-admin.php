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
        $nonce = Sektorel_Content_AI_Draft_Processor::nonce();
        ?>
        <script>
        jQuery(function($){
            var enabled=<?php echo $enabled ? 'true' : 'false'; ?>;
            var html=''+
                '<div class="sektorel-panel" id="sektorel-content-ai-draft-panel">'+
                '<h2 style="margin-top:0">AI Editoryal Taslak Üretimi</h2>'+
                '<p>Yalnız <strong>ready</strong> candidate kayıtlarını işler. Kaynak metnini zenginleştirir, AI ile editoryal haber taslağı üretir ve WordPress\'e <strong>draft</strong> olarak kaydeder. Otomatik yayın yapılmaz.</p>'+
                '<p><strong>Güvenlik:</strong> işlem manuel başlatılır, tek tıklamada en fazla 5 candidate işlenir ve günlük limit uygulanır.</p>'+
                (enabled
                    ? '<p><button type="button" class="button button-primary" id="sektorel-content-ai-draft-run">5 Hazır Candidate\'dan Taslak Üret</button></p>'
                    : '<p><strong>AI kapalı.</strong> <code>SEKTOREL_OPENAI_API_KEY</code> tanımlandığında bu işlem etkinleşir.</p>')+
                '<div id="sektorel-content-ai-draft-summary" style="display:none;margin-top:12px;padding:12px;background:#f6f7f7;border-left:4px solid #2271b1"></div>'+
                '<div id="sektorel-content-ai-draft-log" class="sektorel-console"></div>'+
                '</div>';

            var $triage=$('#sektorel-content-triage-all').closest('.sektorel-panel');
            if($triage.length){$triage.after(html);}else{$('.wrap h1').after(html);}
            if(!enabled)return;

            function log(msg,error){
                var $log=$('#sektorel-content-ai-draft-log').show();
                $log.append('<div style="color:'+(error?'#ff8080':'#f0f0f1')+'">'+$('<div>').text(msg).html()+'</div>');
                $log.scrollTop($log[0].scrollHeight);
            }

            $('#sektorel-content-ai-draft-run').on('click',function(){
                var $btn=$(this),$summary=$('#sektorel-content-ai-draft-summary');
                $btn.prop('disabled',true).text('Taslaklar hazırlanıyor...');
                $summary.hide();$('#sektorel-content-ai-draft-log').empty().show();
                $.post(ajaxurl,{action:'sektorel_content_ai_draft_batch',nonce:'<?php echo esc_js( $nonce ); ?>'}).done(function(r){
                    if(!r||!r.success){
                        var msg=r&&r.data&&r.data.message?r.data.message:'AI draft isteği başarısız.';
                        log(msg,true);$btn.prop('disabled',false).text('5 Hazır Candidate\'dan Taslak Üret');return;
                    }
                    (r.data.messages||[]).forEach(function(m){log(m,m.indexOf('Hata')===0);});
                    $summary.show().html(
                        '<strong>Batch tamamlandı.</strong><br>'+ 
                        'İşlenen: <strong>'+Number(r.data.processed||0)+'</strong> &nbsp; '+
                        'Draft: <strong>'+Number(r.data.drafted||0)+'</strong> &nbsp; '+
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
        });
        </script>
        <?php
    }
}
