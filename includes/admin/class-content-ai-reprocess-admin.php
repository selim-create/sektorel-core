<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Admin control for bounded, in-place TOBB draft reprocessing. */
class Sektorel_Content_AI_Reprocess_Admin {

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_footer', array( __CLASS__, 'render_control' ) );
    }

    public static function render_control() {
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
            var $panel=$('#sektorel-content-ai-draft-panel');
            if(!$panel.length){return;}

            var button='';
            if(enabled){
                button='<button type="button" class="button" id="sektorel-content-ai-reprocess-tobb">TOBB Taslaklarını Tam Kaynaktan Yeniden İşle</button>';
            }else{
                button='<button type="button" class="button" disabled>TOBB Taslaklarını Tam Kaynaktan Yeniden İşle</button>';
            }

            var box=''+
                '<div id="sektorel-content-ai-reprocess-box" style="margin-top:14px;padding-top:14px;border-top:1px solid #dcdcde">'+
                '<p><strong>TOBB tam-kaynak onarımı:</strong> Daha önce kısa RSS özetiyle üretilen TOBB taslaklarını resmi detay sayfasından tekrar zenginleştirir ve aynı WordPress post ID üzerinde yeniden yazar. Yalnız <strong>draft</strong> yazılara dokunur; yeni post oluşturmaz ve otomatik yayın yapmaz.</p>'+
                '<p><strong>Maliyet:</strong> Bu işlem OpenAI tokenı kullanır. Tek çalıştırmada en fazla 5 taslak işlenir; günlük limit, aylık bütçe ve aynı kaynak sürümünü tekrar işlememe koruması uygulanır.</p>'+
                '<p>'+button+'</p>'+
                '</div>';
            $panel.append(box);

            if(!enabled){return;}

            $('#sektorel-content-ai-reprocess-tobb').on('click',function(){
                if(!window.confirm('TOBB draftları tam resmi kaynak metninden yeniden AI ile işlenecek. Aynı post ID korunur ve işlem OpenAI tokenı kullanır. Devam edilsin mi?')){
                    return;
                }

                var $btn=$(this),$summary=$('#sektorel-content-ai-draft-summary'),$log=$('#sektorel-content-ai-draft-log');
                $btn.prop('disabled',true).text('TOBB taslakları yeniden işleniyor...');
                $summary.hide();$log.empty().show();

                function log(msg,error){
                    $log.append('<div style="color:'+(error?'#ff8080':'#f0f0f1')+'">'+$('<div>').text(msg).html()+'</div>');
                    $log.scrollTop($log[0].scrollHeight);
                }

                $.post(ajaxurl,{
                    action:'sektorel_content_ai_reprocess_tobb_drafts',
                    nonce:'<?php echo esc_js( $nonce ); ?>'
                }).done(function(r){
                    if(!r||!r.success){
                        var msg=r&&r.data&&r.data.message?r.data.message:'TOBB taslak yeniden işleme isteği başarısız.';
                        log(msg,true);
                        $btn.prop('disabled',false).text('TOBB Taslaklarını Tam Kaynaktan Yeniden İşle');
                        return;
                    }

                    (r.data.messages||[]).forEach(function(m){
                        log(m,m.indexOf('hatası')>-1||m.indexOf('güncellenemedi')>-1||m.indexOf('görsel hatası')>-1);
                    });
                    $summary.show().html(
                        '<strong>TOBB yeniden işleme tamamlandı.</strong><br>'+ 
                        'AI isteği: <strong>'+Number(r.data.attempted||0)+'</strong> &nbsp; '+
                        'Güncellenen draft: <strong>'+Number(r.data.reprocessed||0)+'</strong> &nbsp; '+
                        'Atlanan: <strong>'+Number(r.data.skipped||0)+'</strong> &nbsp; '+
                        'Hata: <strong>'+Number(r.data.errors||0)+'</strong> &nbsp; '+
                        'Yeni kaynak görseli: <strong>'+Number(r.data.images_added||0)+'</strong> &nbsp; '+
                        'Görsel hatası: <strong>'+Number(r.data.image_errors||0)+'</strong><br>'+ 
                        'Bu işlem tahmini maliyet: <strong>$'+Number(r.data.batch_cost||0).toFixed(6)+'</strong><br>'+ 
                        'Günlük AI kullanımı: <strong>'+Number(r.data.daily_used||0)+' / '+Number(r.data.daily_limit||0)+'</strong>'+ 
                        '<br><br><button class="button" onclick="window.location.reload()">Sonuçları Yenile</button>'
                    );
                    $btn.prop('disabled',false).text('TOBB Taslaklarını Tam Kaynaktan Yeniden İşle');
                }).fail(function(){
                    log('Sunucu TOBB taslak yeniden işleme isteği başarısız oldu.',true);
                    $btn.prop('disabled',false).text('TOBB Taslaklarını Tam Kaynaktan Yeniden İşle');
                });
            });
        });
        </script>
        <?php
    }
}
