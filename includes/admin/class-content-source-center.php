<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Content_Source_Center {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 45 );
    }

    public static function add_menu() {
        add_submenu_page(
            'edit.php',
            'İçerik Kaynak Merkezi',
            'Kaynak Merkezi',
            'manage_options',
            'sektorel-content-source-center',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'sektorel-core' ) );
        }

        $stats = Sektorel_Content_Candidates::stats();
        $sources = get_posts( array(
            'post_type'      => 'content_source',
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        $recent = Sektorel_Content_Candidates::recent( 20 );
        $nonce  = Sektorel_Content_Source_Scanner::nonce();
        ?>
        <div class="wrap">
            <h1>İçerik Kaynak Merkezi</h1>
            <p>RSS/XML kaynakları güvenli biçimde taranır ve deterministic candidate kayıtları oluşturulur. AI işleme, taslak üretimi ve otomatik yayın bu fazda kapalıdır.</p>

            <style>
                .sektorel-content-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:18px 0 24px;max-width:1100px}
                .sektorel-content-card{background:#fff;border:1px solid #dcdcde;padding:16px}
                .sektorel-content-card strong{display:block;font-size:24px;margin-top:5px}
                .sektorel-content-table{max-width:1200px}
                .sektorel-status-pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#f0f0f1;font-size:12px}
                .sektorel-scan-panel{max-width:1100px;background:#fff;border:1px solid #dcdcde;padding:18px;margin:18px 0 24px}
                #sektorel-content-scan-log{display:none;margin-top:14px;max-height:260px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace}
            </style>

            <div class="sektorel-content-cards">
                <?php
                $cards = array(
                    'Kaynak' => count( $sources ), 'Candidate' => $stats['total'], 'Yeni' => $stats['new'],
                    'Duplicate' => $stats['duplicate'], 'İnceleme' => $stats['review'], 'Hazır' => $stats['ready'],
                    'İşlendi' => $stats['processed'], 'Hata' => $stats['error'],
                );
                foreach ( $cards as $label => $value ) : ?>
                    <div class="sektorel-content-card"><span><?php echo esc_html( $label ); ?></span><strong><?php echo (int) $value; ?></strong></div>
                <?php endforeach; ?>
            </div>

            <div class="sektorel-scan-panel">
                <h2 style="margin-top:0">Kaynak Tarama</h2>
                <p>İlk kurulumda TCMB Basın Duyuruları ile TOBB Haberler/Duyurular kaynaklarını ekleyebilir, ardından tüm aktif kaynakları sırayla tarayabilirsiniz.</p>
                <p>
                    <a class="button" href="<?php echo esc_url( Sektorel_Content_Source_Scanner::seed_url() ); ?>">Resmî Kaynakları Hazırla</a>
                    <button type="button" class="button button-primary" id="sektorel-content-scan-all">Tüm Aktif Kaynakları Tara</button>
                    <a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=content_source' ) ); ?>">Yeni İçerik Kaynağı</a>
                    <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=content_source' ) ); ?>">Kaynakları Yönet</a>
                </p>
                <div id="sektorel-content-scan-summary" style="display:none;margin-top:12px;padding:12px;background:#f6f7f7;border-left:4px solid #2271b1"></div>
                <div id="sektorel-content-scan-log"></div>
            </div>

            <h2>Kaynaklar</h2>
            <table class="widefat striped sektorel-content-table">
                <thead><tr><th>Kaynak</th><th>Key</th><th>Tip</th><th>Durum</th><th>Son Tarama</th><th>Son Başarı</th><th>Son Sonuç</th></tr></thead>
                <tbody>
                <?php if ( empty( $sources ) ) : ?>
                    <tr><td colspan="7">Henüz içerik kaynağı tanımlanmadı.</td></tr>
                <?php else : foreach ( $sources as $source ) :
                    $result = json_decode( (string) get_post_meta( $source->ID, 'last_scan_result', true ), true ); ?>
                    <tr>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $source->ID ) ); ?>"><?php echo esc_html( get_the_title( $source ) ); ?></a></td>
                        <td><code><?php echo esc_html( get_post_meta( $source->ID, 'source_key', true ) ?: '—' ); ?></code></td>
                        <td><?php echo esc_html( get_post_meta( $source->ID, 'source_type', true ) ?: '—' ); ?></td>
                        <td><?php echo '1' === (string) get_post_meta( $source->ID, 'enabled', true ) ? 'Aktif' : 'Pasif'; ?></td>
                        <td><?php echo esc_html( get_post_meta( $source->ID, 'last_scan', true ) ?: '—' ); ?></td>
                        <td><?php echo esc_html( get_post_meta( $source->ID, 'last_success', true ) ?: '—' ); ?></td>
                        <td>
                            <?php if ( is_array( $result ) ) : ?>
                                <?php echo esc_html( sprintf( '%d öğe / %d yeni / %d mevcut', (int) ( $result['parsed'] ?? 0 ), (int) ( $result['created'] ?? 0 ), (int) ( $result['existing'] ?? 0 ) ) ); ?>
                            <?php elseif ( get_post_meta( $source->ID, 'last_error', true ) ) : ?>
                                <span style="color:#b32d2e"><?php echo esc_html( get_post_meta( $source->ID, 'last_error', true ) ); ?></span>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:28px">Son Candidate Kayıtları</h2>
            <table class="widefat striped sektorel-content-table">
                <thead><tr><th>ID</th><th>Kaynak</th><th>Başlık</th><th>Durum</th><th>Duplicate</th><th>AI</th><th>İlk Görülme</th></tr></thead>
                <tbody>
                <?php if ( empty( $recent ) ) : ?>
                    <tr><td colspan="7">Henüz candidate yok. Resmî kaynakları hazırlayıp taramayı başlatabilirsiniz.</td></tr>
                <?php else : foreach ( $recent as $candidate ) : ?>
                    <tr>
                        <td><?php echo (int) $candidate['id']; ?></td>
                        <td><code><?php echo esc_html( $candidate['source_key'] ); ?></code></td>
                        <td><?php if ( ! empty( $candidate['source_url'] ) ) : ?><a href="<?php echo esc_url( $candidate['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $candidate['title'] ?: $candidate['source_url'] ); ?></a><?php else : echo esc_html( $candidate['title'] ?: '—' ); endif; ?></td>
                        <td><span class="sektorel-status-pill"><?php echo esc_html( $candidate['status'] ); ?></span></td>
                        <td><?php echo ! empty( $candidate['duplicate_method'] ) ? esc_html( $candidate['duplicate_method'] . ' #' . (int) $candidate['duplicate_candidate_id'] ) : '—'; ?></td>
                        <td><?php echo esc_html( $candidate['ai_status'] ?: 'pending' ); ?></td>
                        <td><?php echo esc_html( $candidate['first_seen_at'] ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <script>
        jQuery(function($){
            var token = '', total = 0, offset = 0, running = false;
            var sums = {created:0, existing:0, duplicates:0, errors:0};
            function log(msg, error){
                var $log = $('#sektorel-content-scan-log').show();
                $log.append('<div style="color:' + (error ? '#ff8080' : '#f0f0f1') + '">' + $('<div>').text(msg).html() + '</div>');
                $log.scrollTop($log[0].scrollHeight);
            }
            function stop(message){ running=false; $('#sektorel-content-scan-all').prop('disabled',false).text('Tüm Aktif Kaynakları Tara'); if(message) log(message,true); }
            function finish(){
                running=false; $('#sektorel-content-scan-all').prop('disabled',false).text('Tüm Aktif Kaynakları Yeniden Tara');
                $('#sektorel-content-scan-summary').show().html('<strong>Tarama tamamlandı.</strong><br>Yeni: <strong>'+sums.created+'</strong> &nbsp; Mevcut: <strong>'+sums.existing+'</strong> &nbsp; Duplicate: <strong>'+sums.duplicates+'</strong> &nbsp; Hata: <strong>'+sums.errors+'</strong><br><br><button class="button" onclick="window.location.reload()">Sonuçları Yenile</button>');
            }
            function next(){
                $.post(ajaxurl,{action:'sektorel_content_scan_batch',nonce:'<?php echo esc_js( $nonce ); ?>',token:token,offset:offset}).done(function(r){
                    if(!r || !r.success){ stop(r && r.data && r.data.message ? r.data.message : 'Tarama batch isteği başarısız.'); return; }
                    sums.created += Number(r.data.created||0); sums.existing += Number(r.data.existing||0); sums.duplicates += Number(r.data.duplicates||0); sums.errors += Number(r.data.errors||0);
                    offset = Number(r.data.next_offset||total); (r.data.messages||[]).forEach(function(m){log(m,false);});
                    if(r.data.done){ finish(); } else { window.setTimeout(next,250); }
                }).fail(function(){ stop('Sunucu isteği başarısız oldu.'); });
            }
            $('#sektorel-content-scan-all').on('click',function(){
                if(running) return; running=true; sums={created:0,existing:0,duplicates:0,errors:0}; offset=0;
                $('#sektorel-content-scan-summary').hide(); $('#sektorel-content-scan-log').empty().show(); $(this).prop('disabled',true).text('Kuyruk Hazırlanıyor...');
                $.post(ajaxurl,{action:'sektorel_content_prepare_scans',nonce:'<?php echo esc_js( $nonce ); ?>'}).done(function(r){
                    if(!r || !r.success){ stop(r && r.data && r.data.message ? r.data.message : 'Tarama kuyruğu hazırlanamadı.'); return; }
                    token=r.data.token; total=Number(r.data.total||0); $('#sektorel-content-scan-all').text('Kaynaklar Taranıyor...'); log(total+' aktif kaynak kuyruğa alındı.',false); next();
                }).fail(function(){stop('Tarama kuyruğu isteği başarısız oldu.');});
            });
        });
        </script>
        <?php
    }
}
