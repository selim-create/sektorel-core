<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Source_Checker {

    const NONCE_ACTION  = 'sektorel_event_source_check';
    const BATCH_SIZE    = 5;
    const TIMEOUT       = 12;
    const MAX_BODY_SIZE = 1048576; // 1 MB.

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 30 );
        add_filter( 'manage_event_source_posts_columns', array( __CLASS__, 'add_columns' ), 20 );
        add_action( 'manage_event_source_posts_custom_column', array( __CLASS__, 'render_column' ), 20, 2 );
        add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );

        add_action( 'admin_post_sektorel_check_event_source', array( __CLASS__, 'handle_single_check' ) );
        add_action( 'wp_ajax_sektorel_event_source_prepare_checks', array( __CLASS__, 'ajax_prepare_checks' ) );
        add_action( 'wp_ajax_sektorel_event_source_check_batch', array( __CLASS__, 'ajax_check_batch' ) );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'Etkinlik Kaynaklarını Kontrol Et',
            'Kaynak Kontrolü',
            'manage_options',
            'sektorel-event-source-check',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function add_columns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'source_status' === $key ) {
                $new['check_state'] = 'Kontrol';
            }
        }
        if ( ! isset( $new['check_state'] ) ) {
            $new['check_state'] = 'Kontrol';
        }
        return $new;
    }

    public static function render_column( $column, $post_id ) {
        if ( 'check_state' !== $column ) {
            return;
        }

        $state       = (string) get_post_meta( $post_id, 'check_state', true );
        $http_status = (string) get_post_meta( $post_id, 'last_http_status', true );
        $detected    = (string) get_post_meta( $post_id, 'detected_parser', true );

        $labels = array(
            'queued'  => 'Bekliyor',
            'running' => 'Kontrol ediliyor',
            'ok'      => 'Erişilebilir',
            'error'   => 'Hata',
            'skipped' => 'Atlandı',
        );

        $label = isset( $labels[ $state ] ) ? $labels[ $state ] : 'Henüz yok';
        echo '<strong>' . esc_html( $label ) . '</strong>';
        if ( $http_status ) {
            echo '<br><span style="color:#646970;">HTTP ' . esc_html( $http_status ) . '</span>';
        }
        if ( $detected ) {
            echo '<br><span style="color:#646970;">' . esc_html( strtoupper( $detected ) ) . '</span>';
        }
    }

    public static function row_actions( $actions, $post ) {
        if ( ! $post || 'event_source' !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=sektorel_check_event_source&source_id=' . absint( $post->ID ) ),
            self::NONCE_ACTION . '_' . absint( $post->ID )
        );
        $actions['sektorel_check'] = '<a href="' . esc_url( $url ) . '">Şimdi Kontrol Et</a>';
        return $actions;
    }

    public static function handle_single_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Yetkisiz işlem.', 'sektorel-core' ) );
        }

        $source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
        check_admin_referer( self::NONCE_ACTION . '_' . $source_id );

        if ( ! $source_id || 'event_source' !== get_post_type( $source_id ) ) {
            wp_safe_redirect( admin_url( 'edit.php?post_type=event_source&sektorel_check=invalid' ) );
            exit;
        }

        $result = self::check_source( $source_id );
        $status = is_wp_error( $result ) ? 'error' : 'ok';

        wp_safe_redirect( admin_url( 'edit.php?post_type=event_source&sektorel_check=' . $status ) );
        exit;
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'sektorel-core' ) );
        }

        $counts = self::source_counts();
        $nonce  = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <div class="wrap">
            <h1>Etkinlik Kaynaklarını Kontrol Et</h1>
            <p>Aktif kaynakları güvenli batch kuyruğuna alır ve erişilebilirlik / içerik tipi kontrolü yapar. Bu aşama henüz etkinlikleri içe aktarmaz.</p>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:20px 0;">
                <?php self::stat_card( 'Toplam', $counts['total'] ); ?>
                <?php self::stat_card( 'Aktif', $counts['active'] ); ?>
                <?php self::stat_card( 'URL Eksik', $counts['missing_url'] ); ?>
                <?php self::stat_card( 'Erişilebilir', $counts['ok'] ); ?>
                <?php self::stat_card( 'Hata', $counts['error'] ); ?>
            </div>

            <div class="card" style="max-width:900px;padding:22px;">
                <h2 style="margin-top:0;">Toplu Kaynak Kontrolü</h2>
                <p>Her istekte en fazla <?php echo esc_html( self::BATCH_SIZE ); ?> kaynak kontrol edilir. Tarayıcı açık kaldığı sürece batch'ler sırayla devam eder.</p>
                <p><button type="button" class="button button-primary button-hero" id="sektorel-check-all">Tüm Aktif Kaynakları Kontrol Et</button></p>

                <div id="sektorel-check-progress" style="display:none;margin-top:22px;">
                    <div style="height:24px;background:#e2e4e7;border-radius:3px;overflow:hidden;">
                        <div id="sektorel-check-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:8px;font-weight:600;">
                        <span id="sektorel-check-percent">%0</span>
                        <span id="sektorel-check-count">0 / 0</span>
                    </div>
                </div>

                <div id="sektorel-check-summary" style="display:none;margin-top:18px;padding:15px;background:#f6f7f7;border-left:4px solid #2271b1;"></div>
                <div id="sektorel-check-log" style="display:none;margin-top:16px;max-height:260px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace;"></div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var token = '';
            var total = 0;
            var offset = 0;
            var totals = { ok: 0, error: 0, skipped: 0 };
            var running = false;

            function log(message, isError) {
                var $log = $('#sektorel-check-log');
                $log.show().append('<div style="color:' + (isError ? '#ff8080' : '#f0f0f1') + '">' + $('<div>').text(message).html() + '</div>');
                $log.scrollTop($log[0].scrollHeight);
            }

            function progress(processed) {
                var percent = total ? Math.min(100, Math.round((processed / total) * 100)) : 0;
                $('#sektorel-check-progress').show();
                $('#sektorel-check-bar').css('width', percent + '%');
                $('#sektorel-check-percent').text('%' + percent);
                $('#sektorel-check-count').text(processed + ' / ' + total);
            }

            function fail(message) {
                running = false;
                $('#sektorel-check-all').prop('disabled', false).text('Tekrar Dene');
                log(message, true);
            }

            function finish() {
                running = false;
                $('#sektorel-check-all').prop('disabled', false).text('Tüm Aktif Kaynakları Yeniden Kontrol Et');
                $('#sektorel-check-bar').css('width', '100%').css('background', '#00a32a');
                $('#sektorel-check-percent').text('%100');
                $('#sektorel-check-summary').show().html(
                    '<strong>Kontrol tamamlandı.</strong><br>' +
                    'Erişilebilir: <strong>' + totals.ok + '</strong> &nbsp; ' +
                    'Hata: <strong>' + totals.error + '</strong> &nbsp; ' +
                    'Atlandı: <strong>' + totals.skipped + '</strong><br><br>' +
                    '<a class="button" href="edit.php?post_type=event_source">Kaynak Listesini Görüntüle</a>'
                );
                log('Tüm kuyruk işlendi.');
            }

            function nextBatch() {
                if (!running) return;

                $.post(ajaxurl, {
                    action: 'sektorel_event_source_check_batch',
                    nonce: '<?php echo esc_js( $nonce ); ?>',
                    token: token,
                    offset: offset
                }).done(function(response) {
                    if (!response || !response.success) {
                        fail((response && response.data && response.data.message) ? response.data.message : 'Kontrol batch isteği başarısız.');
                        return;
                    }

                    totals.ok += Number(response.data.ok || 0);
                    totals.error += Number(response.data.error || 0);
                    totals.skipped += Number(response.data.skipped || 0);
                    offset = Number(response.data.next_offset || total);
                    progress(offset);

                    if (response.data.messages && response.data.messages.length) {
                        response.data.messages.forEach(function(message) { log(message, false); });
                    }

                    if (response.data.done) {
                        finish();
                    } else {
                        window.setTimeout(nextBatch, 250);
                    }
                }).fail(function() {
                    fail('Sunucu isteği başarısız oldu. Kuyruk durduruldu.');
                });
            }

            $('#sektorel-check-all').on('click', function() {
                if (running) return;

                running = true;
                token = '';
                total = 0;
                offset = 0;
                totals = { ok: 0, error: 0, skipped: 0 };
                $('#sektorel-check-summary').hide().empty();
                $('#sektorel-check-log').show().empty();
                $('#sektorel-check-bar').css('background', '#2271b1');
                $(this).prop('disabled', true).text('Kuyruk Hazırlanıyor...');
                log('Aktif kaynaklar kuyruğa alınıyor...');

                $.post(ajaxurl, {
                    action: 'sektorel_event_source_prepare_checks',
                    nonce: '<?php echo esc_js( $nonce ); ?>'
                }).done(function(response) {
                    if (!response || !response.success) {
                        fail((response && response.data && response.data.message) ? response.data.message : 'Kuyruk hazırlanamadı.');
                        return;
                    }

                    token = response.data.token;
                    total = Number(response.data.total || 0);
                    progress(0);
                    $('#sektorel-check-all').text('Kaynaklar Kontrol Ediliyor...');
                    log(total + ' aktif kaynak kuyruğa alındı.');
                    nextBatch();
                }).fail(function() {
                    fail('Kuyruk hazırlama isteği başarısız oldu.');
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_prepare_checks() {
        self::require_admin_ajax();

        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => 'source_status',
                    'value'   => 'active',
                    'compare' => '=',
                ),
            ),
        ) );

        $ids = array_values( array_map( 'absint', $ids ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Kontrol edilecek aktif kaynak bulunamadı.' ) );
        }

        foreach ( $ids as $source_id ) {
            update_post_meta( $source_id, 'check_state', 'queued' );
        }

        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), $ids, 2 * HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $ids ),
        ) );
    }

    public static function ajax_check_batch() {
        self::require_admin_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Kuyruk anahtarı eksik.' ) );
        }

        $key = self::queue_key( get_current_user_id(), $token );
        $ids = get_transient( $key );
        if ( ! is_array( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Kontrol kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $batch    = array_slice( $ids, $offset, self::BATCH_SIZE );
        $ok       = 0;
        $error    = 0;
        $skipped  = 0;
        $messages = array();

        foreach ( $batch as $source_id ) {
            $result = self::check_source( absint( $source_id ) );
            $title  = get_the_title( $source_id );

            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Hata: ' . $title . ' — ' . $result->get_error_message();
            } elseif ( isset( $result['skipped'] ) && $result['skipped'] ) {
                $skipped++;
                $messages[] = 'Atlandı: ' . $title;
            } else {
                $ok++;
                $message = 'OK: ' . $title . ' — HTTP ' . $result['http_status'];
                if ( ! empty( $result['detected_parser'] ) ) {
                    $message .= ' / ' . strtoupper( $result['detected_parser'] );
                }
                $messages[] = $message;
            }
        }

        $total       = count( $ids );
        $next_offset = min( $total, $offset + count( $batch ) );
        $done        = $next_offset >= $total;

        if ( $done ) {
            delete_transient( $key );
        } else {
            set_transient( $key, $ids, 2 * HOUR_IN_SECONDS );
        }

        wp_send_json_success( array(
            'ok'          => $ok,
            'error'       => $error,
            'skipped'     => $skipped,
            'messages'    => $messages,
            'next_offset' => $next_offset,
            'done'        => $done,
        ) );
    }

    public static function check_source( $source_id ) {
        $source_id = absint( $source_id );
        if ( ! $source_id || 'event_source' !== get_post_type( $source_id ) ) {
            return new WP_Error( 'invalid_source', 'Geçersiz kaynak kaydı.' );
        }

        $url = trim( (string) get_post_meta( $source_id, 'source_url', true ) );
        if ( ! $url ) {
            self::record_error( $source_id, 'Kaynak URL eksik.', 0, 'skipped' );
            return array( 'skipped' => true );
        }

        if ( ! self::is_safe_public_url( $url ) ) {
            self::record_error( $source_id, 'Güvenli olmayan veya private ağ hedefi.', 0, 'error' );
            return new WP_Error( 'unsafe_url', 'Güvenli olmayan veya private ağ hedefi.' );
        }

        update_post_meta( $source_id, 'check_state', 'running' );

        $response = wp_safe_remote_get( $url, array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_BODY_SIZE,
            'user-agent'          => 'SektorelAjandaBot/1.0; +' . home_url( '/' ),
            'headers'             => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml,text/calendar,application/rss+xml;q=0.9,*/*;q=0.5',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            self::record_error( $source_id, $response->get_error_message(), 0, 'error' );
            return $response;
        }

        $http_status  = (int) wp_remote_retrieve_response_code( $response );
        $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        $body         = (string) wp_remote_retrieve_body( $response );
        $final_url    = $url;

        if ( $http_status < 200 || $http_status >= 400 ) {
            $message = 'HTTP ' . $http_status . ' döndü.';
            self::record_error( $source_id, $message, $http_status, 'error', $content_type );
            return new WP_Error( 'http_error', $message );
        }

        $detected = self::detect_parser_hint( $body, $content_type, $url );
        $now      = current_time( 'mysql' );
        $summary  = 'Erişilebilir. HTTP ' . $http_status . ( $detected ? ' / ' . strtoupper( $detected ) . ' sinyali' : '' );

        update_post_meta( $source_id, 'check_state', 'ok' );
        update_post_meta( $source_id, 'last_checked_at', $now );
        update_post_meta( $source_id, 'last_http_status', $http_status );
        update_post_meta( $source_id, 'last_content_type', sanitize_text_field( $content_type ) );
        update_post_meta( $source_id, 'last_final_url', esc_url_raw( $final_url ) );
        update_post_meta( $source_id, 'last_result', $summary );
        update_post_meta( $source_id, 'last_error', '' );
        update_post_meta( $source_id, 'detected_parser', $detected );

        return array(
            'http_status'     => $http_status,
            'content_type'    => $content_type,
            'detected_parser' => $detected,
            'skipped'         => false,
        );
    }

    private static function detect_parser_hint( $body, $content_type, $url ) {
        $sample = substr( $body, 0, self::MAX_BODY_SIZE );
        $lower  = strtolower( $sample );
        $path   = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );

        if ( false !== strpos( $content_type, 'text/calendar' ) || preg_match( '/\.ics(?:$|\?)/i', $path ) || false !== strpos( $sample, 'BEGIN:VEVENT' ) ) {
            return 'ics';
        }

        if ( false !== strpos( $content_type, 'application/rss+xml' ) || false !== strpos( $content_type, 'application/atom+xml' ) || false !== strpos( $lower, '<rss' ) || false !== strpos( $lower, '<feed' ) ) {
            return 'rss';
        }

        if ( false !== strpos( $lower, 'application/ld+json' ) && ( false !== strpos( $lower, '"@type":"event"' ) || false !== strpos( $lower, '"@type": "event"' ) || false !== strpos( $lower, 'schema.org/event' ) ) ) {
            return 'jsonld';
        }

        if ( false !== strpos( $content_type, 'text/html' ) || false !== strpos( $lower, '<html' ) ) {
            return 'html';
        }

        if ( false !== strpos( $content_type, 'xml' ) ) {
            return 'rss';
        }

        return 'unknown';
    }

    private static function is_safe_public_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }

        $host = strtolower( rtrim( $parts['host'], '.' ) );
        if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) || preg_match( '/\.local$/i', $host ) ) {
            return false;
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return self::is_public_ip( $host );
        }

        $ips = gethostbynamel( $host );
        if ( ! is_array( $ips ) || empty( $ips ) ) {
            return false;
        }

        foreach ( $ips as $ip ) {
            if ( ! self::is_public_ip( $ip ) ) {
                return false;
            }
        }

        return true;
    }

    private static function is_public_ip( $ip ) {
        return false !== filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private static function record_error( $source_id, $message, $http_status = 0, $state = 'error', $content_type = '' ) {
        update_post_meta( $source_id, 'check_state', sanitize_key( $state ) );
        update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_http_status', absint( $http_status ) );
        update_post_meta( $source_id, 'last_content_type', sanitize_text_field( $content_type ) );
        update_post_meta( $source_id, 'last_result', '' );
        update_post_meta( $source_id, 'last_error', sanitize_text_field( $message ) );
    }

    private static function require_admin_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_src_chk_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function source_counts() {
        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        $counts = array(
            'total'       => count( $ids ),
            'active'      => 0,
            'missing_url' => 0,
            'ok'          => 0,
            'error'       => 0,
        );

        foreach ( $ids as $source_id ) {
            $status = (string) get_post_meta( $source_id, 'source_status', true );
            $state  = (string) get_post_meta( $source_id, 'check_state', true );
            if ( 'active' === $status ) {
                $counts['active']++;
            }
            if ( 'missing_url' === $status ) {
                $counts['missing_url']++;
            }
            if ( 'ok' === $state ) {
                $counts['ok']++;
            }
            if ( 'error' === $state ) {
                $counts['error']++;
            }
        }

        return $counts;
    }

    private static function stat_card( $label, $value ) {
        echo '<div style="min-width:130px;background:#fff;border:1px solid #dcdcde;padding:15px 18px;box-shadow:0 1px 1px rgba(0,0,0,.04);">';
        echo '<div style="font-size:12px;color:#646970;text-transform:uppercase;font-weight:700;">' . esc_html( $label ) . '</div>';
        echo '<div style="font-size:26px;font-weight:700;margin-top:4px;">' . esc_html( (string) $value ) . '</div>';
        echo '</div>';
    }
}
