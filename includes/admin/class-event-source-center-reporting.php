<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Accurate run reporting for Source Center v1.
 *
 * The underlying legacy batch endpoints report transport errors and candidate
 * writes with different semantics. This layer snapshots candidates before a
 * run, classifies genuinely new candidates after all stages complete, and
 * separates source reachability problems from parser/scan failures in the UI.
 */
class Sektorel_Event_Source_Center_Reporting {

    const NONCE_ACTION = 'sektorel_source_center_reporting';
    const TRANSIENT_TTL = 2 * HOUR_IN_SECONDS;

    public static function init() {
        add_action( 'wp_ajax_sektorel_source_center_report_begin', array( __CLASS__, 'ajax_begin' ) );
        add_action( 'wp_ajax_sektorel_source_center_report_finish', array( __CLASS__, 'ajax_finish' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_footer_script' ), 99 );
    }

    public static function ajax_begin() {
        self::require_ajax();

        $token = strtolower( wp_generate_password( 24, false, false ) );
        $ids   = self::candidate_ids();

        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array(
                'candidate_ids' => $ids,
                'started_at'    => current_time( 'mysql' ),
            ),
            self::TRANSIENT_TTL
        );

        wp_send_json_success(
            array(
                'token'              => $token,
                'candidate_baseline' => count( $ids ),
            )
        );
    }

    public static function ajax_finish() {
        self::require_ajax();

        $token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Rapor anahtarı eksik.' ) );
        }

        $key      = self::queue_key( get_current_user_id(), $token );
        $snapshot = get_transient( $key );

        if ( ! is_array( $snapshot ) || ! isset( $snapshot['candidate_ids'] ) || ! is_array( $snapshot['candidate_ids'] ) ) {
            wp_send_json_error( array( 'message' => 'Run snapshot bulunamadı veya süresi doldu.' ) );
        }

        $before_ids = array_values( array_unique( array_map( 'absint', $snapshot['candidate_ids'] ) ) );
        $after_ids  = self::candidate_ids();
        $new_ids    = array_values( array_diff( $after_ids, $before_ids ) );

        $candidate_stats = self::classify_new_candidates( $new_ids );
        $source_stats    = self::source_check_stats();

        delete_transient( $key );

        wp_send_json_success(
            array(
                'new_total'          => count( $new_ids ),
                'new_reviewable'     => $candidate_stats['reviewable'],
                'new_ignored'        => $candidate_stats['ignored'],
                'new_imported'       => $candidate_stats['imported'],
                'new_status_counts'  => $candidate_stats['statuses'],
                'source_ok'          => $source_stats['ok'],
                'source_issues'      => $source_stats['issues'],
                'source_skipped'     => $source_stats['skipped'],
                'source_issue_types' => $source_stats['types'],
            )
        );
    }

    public static function render_footer_script() {
        if ( ! self::is_source_center_page() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <script>
        jQuery(function($){
            var reportNonce = '<?php echo esc_js( $nonce ); ?>';
            var reportToken = '';
            var bypassStartCapture = false;
            var finishRequested = false;

            function numericMetric(text,label){
                var escaped = label.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
                var match = String(text||'').match(new RegExp(escaped+'\\s*:\\s*(\\d+)','i'));
                return match ? Number(match[1]||0) : 0;
            }

            function stageText(key){
                return $('.ssc-stage[data-stage="'+key+'"]').find('.ssc-result').text() || '';
            }

            function parserStageKeys(){
                var keys=[];
                $('.ssc-stage').each(function(){
                    var key=String($(this).data('stage')||'');
                    if(key && key!=='source_check'){ keys.push(key); }
                });
                return keys;
            }

            function parserMetrics(){
                var metrics={updated:0,unchangedSkipped:0,errors:0};
                parserStageKeys().forEach(function(key){
                    var text=stageText(key);
                    metrics.updated += numericMetric(text,'Güncel');
                    metrics.updated += numericMetric(text,'Güncellendi');
                    metrics.unchangedSkipped += numericMetric(text,'Değişmedi');
                    metrics.unchangedSkipped += numericMetric(text,'Atlandı');
                    metrics.errors += numericMetric(text,'Hata');
                    metrics.errors += numericMetric(text,'Başarısız');
                });
                return metrics;
            }

            function issueBreakdown(types){
                types=types||{};
                var labels={
                    unsafe:'Güvenlik/private',
                    ssl_tls:'SSL/TLS',
                    forbidden:'HTTP 403',
                    timeout:'Timeout',
                    http:'Diğer HTTP',
                    connection:'Bağlantı',
                    other:'Diğer'
                };
                var parts=[];
                Object.keys(labels).forEach(function(key){
                    var value=Number(types[key]||0);
                    if(value>0){ parts.push(labels[key]+': <strong>'+value+'</strong>'); }
                });
                return parts.join(' &nbsp; ');
            }

            function renderAccurateSummary(data){
                var metrics=parserMetrics();
                var breakdown=issueBreakdown(data.source_issue_types);
                var html='';
                html += '<strong>Kaynak taraması tamamlandı.</strong><br>';
                html += 'Yeni inceleme adayı: <strong>'+Number(data.new_reviewable||0)+'</strong> &nbsp; ';
                html += 'Ignored / gürültü: <strong>'+Number(data.new_ignored||0)+'</strong> &nbsp; ';
                html += 'Güncellendi: <strong>'+metrics.updated+'</strong> &nbsp; ';
                html += 'Değişmedi / atlandı: <strong>'+metrics.unchangedSkipped+'</strong><br>';
                html += 'Erişilebilir kaynak: <strong>'+Number(data.source_ok||0)+'</strong> &nbsp; ';
                html += 'Kaynak erişim sorunu: <strong>'+Number(data.source_issues||0)+'</strong> &nbsp; ';
                html += 'Parser / tarama hatası: <strong>'+metrics.errors+'</strong>';
                if(breakdown){ html += '<br><span style="color:#646970;">Kaynak sorun dağılımı: '+breakdown+'</span>'; }
                html += '<br><br><a class="button" href="edit.php?post_type=event_candidate">Aday Etkinlikleri Gör</a>';
                $('#ssc-summary').show().html(html);
            }

            function finishReport(){
                if(finishRequested || !reportToken){ return; }
                finishRequested=true;

                $.post(ajaxurl,{
                    action:'sektorel_source_center_report_finish',
                    nonce:reportNonce,
                    token:reportToken
                }).done(function(response){
                    if(response && response.success && response.data){
                        renderAccurateSummary(response.data);
                    }
                });
            }

            // Snapshot must complete before the existing Source Center click
            // handler starts its first AJAX stage. Capture phase temporarily
            // blocks the click, then replays it once the snapshot exists.
            document.addEventListener('click',function(event){
                var target=event.target && event.target.closest ? event.target.closest('#ssc-start') : null;
                if(!target || bypassStartCapture || target.disabled){ return; }

                event.preventDefault();
                event.stopPropagation();
                if(event.stopImmediatePropagation){ event.stopImmediatePropagation(); }

                var originalText=target.textContent;
                target.disabled=true;
                target.textContent='Run raporu hazırlanıyor…';
                finishRequested=false;
                reportToken='';

                $.post(ajaxurl,{
                    action:'sektorel_source_center_report_begin',
                    nonce:reportNonce
                }).always(function(response){
                    if(response && response.success && response.data){
                        reportToken=String(response.data.token||'');
                    }
                    target.disabled=false;
                    target.textContent=originalText;
                    bypassStartCapture=true;
                    target.click();
                    bypassStartCapture=false;
                });
            },true);

            var summary=document.getElementById('ssc-summary');
            if(summary && window.MutationObserver){
                var observer=new MutationObserver(function(){
                    var text=summary.textContent||'';
                    if(text.indexOf('Kaynak taraması tamamlandı.')!==-1){
                        finishReport();
                    }
                });
                observer.observe(summary,{childList:true,subtree:true});
            }
        });
        </script>
        <?php
    }

    private static function classify_new_candidates( $ids ) {
        $stats = array(
            'reviewable' => 0,
            'ignored'    => 0,
            'imported'   => 0,
            'statuses'   => array(),
        );

        if ( ! $ids ) {
            return $stats;
        }

        update_meta_cache( 'post', $ids );

        foreach ( $ids as $candidate_id ) {
            $status = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
            if ( ! $status ) {
                $status = 'unclassified';
            }

            if ( ! isset( $stats['statuses'][ $status ] ) ) {
                $stats['statuses'][ $status ] = 0;
            }
            $stats['statuses'][ $status ]++;

            if ( in_array( $status, array( 'ignored', 'rejected' ), true ) ) {
                $stats['ignored']++;
                continue;
            }

            if ( 'imported' === $status ) {
                $stats['imported']++;
                continue;
            }

            $stats['reviewable']++;
        }

        return $stats;
    }

    private static function source_check_stats() {
        $ids = get_posts(
            array(
                'post_type'      => 'event_source',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'   => 'source_status',
                        'value' => 'active',
                    ),
                ),
            )
        );

        $stats = array(
            'ok'      => 0,
            'issues'  => 0,
            'skipped' => 0,
            'types'   => array(
                'unsafe'     => 0,
                'ssl_tls'    => 0,
                'forbidden'  => 0,
                'timeout'    => 0,
                'http'       => 0,
                'connection' => 0,
                'other'      => 0,
            ),
        );

        if ( ! $ids ) {
            return $stats;
        }

        update_meta_cache( 'post', $ids );

        foreach ( $ids as $source_id ) {
            $state = sanitize_key( (string) get_post_meta( $source_id, 'check_state', true ) );
            if ( 'ok' === $state ) {
                $stats['ok']++;
                continue;
            }

            if ( 'skipped' === $state ) {
                $stats['skipped']++;
            }

            if ( in_array( $state, array( 'error', 'skipped' ), true ) ) {
                $stats['issues']++;
                $type = self::issue_type( (string) get_post_meta( $source_id, 'last_error', true ) );
                $stats['types'][ $type ]++;
            }
        }

        return $stats;
    }

    private static function issue_type( $message ) {
        $message = strtolower( remove_accents( (string) $message ) );

        if ( false !== strpos( $message, 'private ag' ) || false !== strpos( $message, 'guvenli olmayan' ) ) {
            return 'unsafe';
        }
        if ( false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'tls' ) || false !== strpos( $message, 'certificate' ) ) {
            return 'ssl_tls';
        }
        if ( false !== strpos( $message, 'http 403' ) ) {
            return 'forbidden';
        }
        if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
            return 'timeout';
        }
        if ( preg_match( '/http\s+\d{3}/', $message ) ) {
            return 'http';
        }
        if ( false !== strpos( $message, 'connect' ) || false !== strpos( $message, 'connection' ) || false !== strpos( $message, 'curl error 7' ) ) {
            return 'connection';
        }

        return 'other';
    }

    private static function candidate_ids() {
        return array_values(
            array_map(
                'absint',
                get_posts(
                    array(
                        'post_type'      => 'event_candidate',
                        'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'orderby'        => 'ID',
                        'order'          => 'ASC',
                        'no_found_rows'  => true,
                    )
                )
            )
        );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_src_report_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function is_source_center_page() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        return 'event' === $post_type && 'sektorel-source-center' === $page;
    }
}
