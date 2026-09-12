<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-source-policy.php';

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
        $scan_nonce = Sektorel_Content_Source_Scanner::nonce();
        $triage_nonce = Sektorel_Content_Candidate_Triage::nonce();
        ?>
        <div class="wrap">
            <h1>İçerik Kaynak Merkezi</h1>
            <p>RSS/XML kaynakları güvenli biçimde taranır, deterministic candidate kayıtları oluşturulur ve AI öncesi kalite/güncellik triage'ından geçirilir. Taslak üretimi ve otomatik yayın henüz kapalıdır.</p>

            <style>
                .sektorel-content-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:18px 0 24px;max-width:1100px}
                .sektorel-content-card{background:#fff;border:1px solid #dcdcde;padding:16px}
                .sektorel-content-card strong{display:block;font-size:24px;margin-top:5px}
                .sektorel-content-table{max-width:1200px}
                .sektorel-coverage-table{max-width:1450px}
                .sektorel-coverage-table td{vertical-align:top}
                .sektorel-coverage-table code{white-space:nowrap}
                .sektorel-coverage-muted{color:#646970}
                .sektorel-status-pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#f0f0f1;font-size:12px}
                .sektorel-status-ready{background:#d7f0db;color:#135e26}
                .sektorel-status-review{background:#fcf0c3;color:#7a4b00}
                .sektorel-status-duplicate{background:#f0d7d7;color:#8a2424}
                .sektorel-panel{max-width:1100px;background:#fff;border:1px solid #dcdcde;padding:18px;margin:18px 0 24px}
                .sektorel-console{display:none;margin-top:14px;max-height:280px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace}
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

            <div class="sektorel-panel">
                <h2 style="margin-top:0">Kaynak Tarama</h2>
                <p>TCMB ve TOBB gibi aktif kaynakları tarayıp yeni candidate kayıtlarını güvenli biçimde oluşturur.</p>
                <p>
                    <a class="button" href="<?php echo esc_url( Sektorel_Content_Source_Scanner::seed_url() ); ?>">Resmî Kaynakları Hazırla</a>
                    <button type="button" class="button button-primary" id="sektorel-content-scan-all">Tüm Aktif Kaynakları Tara</button>
                    <a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=content_source' ) ); ?>">Yeni İçerik Kaynağı</a>
                    <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=content_source' ) ); ?>">Kaynakları Yönet</a>
                </p>
                <div id="sektorel-content-scan-summary" style="display:none;margin-top:12px;padding:12px;background:#f6f7f7;border-left:4px solid #2271b1"></div>
                <div id="sektorel-content-scan-log" class="sektorel-console"></div>
            </div>

            <div class="sektorel-panel">
                <h2 style="margin-top:0">AI Öncesi Candidate Değerlendirmesi</h2>
                <p>Yalnız <strong>new</strong> durumundaki kayıtları deterministik olarak değerlendirir. Varsayılan güncellik penceresi 45 gündür. Güncel, URL'si ve kategorisi uygun kayıtlar <strong>ready</strong>; eski, tarihsiz veya eksik kayıtlar <strong>review</strong> olur. Bu işlem AI çağrısı yapmaz ve yazı oluşturmaz.</p>
                <p><button type="button" class="button button-primary" id="sektorel-content-triage-all">Yeni Candidate'ları Değerlendir</button></p>
                <div id="sektorel-content-triage-summary" style="display:none;margin-top:12px;padding:12px;background:#f6f7f7;border-left:4px solid #2271b1"></div>
                <div id="sektorel-content-triage-log" class="sektorel-console"></div>
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

            <h2 style="margin-top:28px">Source Coverage Registry</h2>
            <p class="description">Kaynakların efektif kapsama profili. Bu tablo candidate routing yapmaz; source planning ve provenance için görünürlük sağlar.</p>
            <table class="widefat striped sektorel-coverage-table">
                <thead>
                    <tr>
                        <th>Kaynak</th>
                        <th>Tier</th>
                        <th>Primary Desk</th>
                        <th>Topic Scope</th>
                        <th>Sector Scope</th>
                        <th>Geography</th>
                        <th>Detail</th>
                        <th>Image</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $sources ) ) : ?>
                    <tr><td colspan="8">Coverage profili gösterilecek kaynak bulunamadı.</td></tr>
                <?php else : foreach ( $sources as $source ) :
                    $source_key = sanitize_key( (string) get_post_meta( $source->ID, 'source_key', true ) );
                    $profile = Sektorel_Content_Source_Policy::coverage_profile( $source_key, $source->ID );
                    $topics = implode( ', ', (array) ( $profile['topic_scope'] ?? array() ) );
                    $sectors = implode( ', ', (array) ( $profile['sector_scope'] ?? array() ) );
                    ?>
                    <tr>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $source->ID ) ); ?>"><?php echo esc_html( get_the_title( $source ) ); ?></a><br><code><?php echo esc_html( $source_key ?: '—' ); ?></code></td>
                        <td><strong><?php echo esc_html( strtoupper( (string) ( $profile['source_tier'] ?? 'd' ) ) ); ?></strong></td>
                        <td><?php echo ! empty( $profile['primary_desk'] ) ? '<code>' . esc_html( $profile['primary_desk'] ) . '</code>' : '<span class="sektorel-coverage-muted">Broad / triage</span>'; ?></td>
                        <td><?php echo $topics ? esc_html( $topics ) : '—'; ?></td>
                        <td><?php echo $sectors ? esc_html( $sectors ) : '—'; ?></td>
                        <td><code><?php echo esc_html( $profile['geography'] ?? 'unspecified' ); ?></code></td>
                        <td><code><?php echo esc_html( $profile['detail_strategy'] ?? 'feed_only' ); ?></code></td>
                        <td><code><?php echo esc_html( $profile['image_policy'] ?? 'pexels_only' ); ?></code></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:28px">Son Candidate Kayıtları</h2>
            <table class="widefat striped sektorel-content-table">
                <thead><tr><th>ID</th><th>Kaynak</th><th>Başlık</th><th>Durum</th><th>Triage</th><th>Duplicate</th><th>AI</th><th>İlk Görülme</th></tr></thead>
                <tbody>
                <?php if ( empty( $recent ) ) : ?>
                    <tr><td colspan="8">Henüz candidate yok. Resmî kaynakları hazırlayıp taramayı başlatabilirsiniz.</td></tr>
                <?php else : foreach ( $recent as $candidate ) :
                    $evidence = ! empty( $candidate['evidence_json'] ) ? json_decode( (string) $candidate['evidence_json'], true ) : array();
                    $triage = is_array( $evidence ) && isset( $evidence['triage'] ) && is_array( $evidence['triage'] ) ? $evidence['triage'] : array();
                    $status_class = 'sektorel-status-' . sanitize_html_class( $candidate['status'] ); ?>
                    <tr>
                        <td><?php echo (int) $candidate['id']; ?></td>
                        <td><code><?php echo esc_html( $candidate['source_key'] ); ?></code></td>
                        <td><?php if ( ! empty( $candidate['source_url'] ) ) : ?><a href="<?php echo esc_url( $candidate['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $candidate['title'] ?: $candidate['source_url'] ); ?></a><?php else : echo esc_html( $candidate['title'] ?: '—' ); endif; ?></td>
                        <td><span class="sektorel-status-pill <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $candidate['status'] ); ?></span></td>
                        <td>
                            <?php if ( $triage ) : ?>
                                <?php echo esc_html( implode( ' · ', array_slice( (array) ( $triage['reasons'] ?? array() ), 0, 3 ) ) ); ?>
                                <?php if ( ! empty( $triage['suggested_categories'] ) ) : ?><br><small><?php echo esc_html( implode( ', ', (array) $triage['suggested_categories'] ) ); ?></small><?php endif; ?>
                            <?php else : ?>—<?php endif; ?>
                        </td>
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
            var token = '', total = 0, offset = 0, scanRunning = false;
            var sums = {created:0, existing:0, duplicates:0, errors:0};
            function logTo(selector,msg,error){
                var $log=$(selector).show();
                $log.append('<div style="color:'+(error?'#ff8080':'#f0f0f1')+'">'+$('<div>').text(msg).html()+'</div>');
                $log.scrollTop($log[0].scrollHeight);
            }
            function scanStop(message){scanRunning=false;$('#sektorel-content-scan-all').prop('disabled',false).text('Tüm Aktif Kaynakları Tara');if(message)logTo('#sektorel-content-scan-log',message,true);}
            function scanFinish(){scanRunning=false;$('#sektorel-content-scan-all').prop('disabled',false).text('Tüm Aktif Kaynakları Yeniden Tara');$('#sektorel-content-scan-summary').show().html('<strong>Tarama tamamlandı.</strong><br>Yeni: <strong>'+sums.created+'</strong> &nbsp; Mevcut: <strong>'+sums.existing+'</strong> &nbsp; Duplicate: <strong>'+sums.duplicates+'</strong> &nbsp; Hata: <strong>'+sums.errors+'</strong><br><br><button class="button" onclick="window.location.reload()">Sonuçları Yenile</button>');}
            function scanNext(){
                $.post(ajaxurl,{action:'sektorel_content_scan_batch',nonce:'<?php echo esc_js( $scan_nonce ); ?>',token:token,offset:offset}).done(function(r){
                    if(!r||!r.success){scanStop(r&&r.data&&r.data.message?r.data.message:'Tarama batch isteği başarısız.');return;}
                    sums.created+=Number(r.data.created||0);sums.existing+=Number(r.data.existing||0);sums.duplicates+=Number(r.data.duplicates||0);sums.errors+=Number(r.data.errors||0);offset=Number(r.data.next_offset||total);(r.data.messages||[]).forEach(function(m){logTo('#sektorel-content-scan-log',m,false);});if(r.data.done){scanFinish();}else{window.setTimeout(scanNext,250);}
                }).fail(function(){scanStop('Sunucu isteği başarısız oldu.');});
            }
            $('#sektorel-content-scan-all').on('click',function(){
                if(scanRunning)return;scanRunning=true;sums={created:0,existing:0,duplicates:0,errors:0};offset=0;$('#sektorel-content-scan-summary').hide();$('#sektorel-content-scan-log').empty().show();$(this).prop('disabled',true).text('Kuyruk Hazırlanıyor...');
                $.post(ajaxurl,{action:'sektorel_content_prepare_scans',nonce:'<?php echo esc_js( $scan_nonce ); ?>'}).done(function(r){if(!r||!r.success){scanStop(r&&r.data&&r.data.message?r.data.message:'Tarama kuyruğu hazırlanamadı.');return;}token=r.data.token;total=Number(r.data.total||0);$('#sektorel-content-scan-all').text('Kaynaklar Taranıyor...');logTo('#sektorel-content-scan-log',total+' aktif kaynak kuyruğa alındı.',false);scanNext();}).fail(function(){scanStop('Tarama kuyruğu isteği başarısız oldu.');});
            });

            var triageRunning=false,triageTotals={processed:0,ready:0,review:0};
            function triageFinish(){triageRunning=false;$('#sektorel-content-triage-all').prop('disabled',false).text('Yeni Candidate\'ları Yeniden Değerlendir');$('#sektorel-content-triage-summary').show().html('<strong>Değerlendirme tamamlandı.</strong><br>İşlenen: <strong>'+triageTotals.processed+'</strong> &nbsp; Hazır: <strong>'+triageTotals.ready+'</strong> &nbsp; İnceleme: <strong>'+triageTotals.review+'</strong><br><br><button class="button" onclick="window.location.reload()">Sonuçları Yenile</button>');}
            function triageNext(){
                $.post(ajaxurl,{action:'sektorel_content_triage_batch',nonce:'<?php echo esc_js( $triage_nonce ); ?>'}).done(function(r){
                    if(!r||!r.success){triageRunning=false;$('#sektorel-content-triage-all').prop('disabled',false);logTo('#sektorel-content-triage-log',r&&r.data&&r.data.message?r.data.message:'Triage isteği başarısız.',true);return;}
                    triageTotals.processed+=Number(r.data.processed||0);triageTotals.ready+=Number(r.data.ready||0);triageTotals.review+=Number(r.data.review||0);(r.data.messages||[]).forEach(function(m){logTo('#sektorel-content-triage-log',m,false);});if(r.data.done){triageFinish();}else{window.setTimeout(triageNext,200);}
                }).fail(function(){triageRunning=false;$('#sektorel-content-triage-all').prop('disabled',false);logTo('#sektorel-content-triage-log','Triage sunucu isteği başarısız oldu.',true);});
            }
            $('#sektorel-content-triage-all').on('click',function(){if(triageRunning)return;triageRunning=true;triageTotals={processed:0,ready:0,review:0};$('#sektorel-content-triage-summary').hide();$('#sektorel-content-triage-log').empty().show();$(this).prop('disabled',true).text('Candidate\'lar Değerlendiriliyor...');triageNext();});
        });
        </script>
        <?php
    }
}
