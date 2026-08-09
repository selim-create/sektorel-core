<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Source_Health {

    const NONCE_ACTION = 'sektorel_event_source_health';
    const BATCH_SIZE   = 5;
    const TIMEOUT      = 8;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 35 );
        add_filter( 'manage_event_source_posts_columns', array( __CLASS__, 'add_columns' ), 30 );
        add_action( 'manage_event_source_posts_custom_column', array( __CLASS__, 'render_column' ), 30, 2 );
        add_action( 'wp_ajax_sektorel_event_source_prepare_health', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_event_source_health_batch', array( __CLASS__, 'ajax_batch' ) );
        add_action( 'admin_post_sektorel_apply_source_suggestion', array( __CLASS__, 'apply_suggestion' ) );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'Kaynak Sağlık Analizi',
            'Kaynak Sağlığı',
            'manage_options',
            'sektorel-event-source-health',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function add_columns( $columns ) {
        $columns['health_issue'] = 'Sağlık';
        $columns['suggested_url'] = 'URL Önerisi';
        return $columns;
    }

    public static function render_column( $column, $post_id ) {
        if ( 'health_issue' === $column ) {
            $issue = (string) get_post_meta( $post_id, 'health_issue', true );
            echo $issue ? esc_html( self::issue_label( $issue ) ) : '—';
            return;
        }

        if ( 'suggested_url' === $column ) {
            $suggested = (string) get_post_meta( $post_id, 'suggested_source_url', true );
            if ( ! $suggested ) {
                echo '—';
                return;
            }

            echo '<a href="' . esc_url( $suggested ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_parse_url( $suggested, PHP_URL_HOST ) ?: $suggested ) . '</a>';
            if ( current_user_can( 'manage_options' ) ) {
                $apply_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=sektorel_apply_source_suggestion&source_id=' . absint( $post_id ) ),
                    self::NONCE_ACTION . '_apply_' . absint( $post_id )
                );
                echo '<br><a href="' . esc_url( $apply_url ) . '">Öneriyi Uygula</a>';
            }
        }
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'sektorel-core' ) );
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        $counts = self::counts();
        ?>
        <div class="wrap">
            <h1>Kaynak Sağlık Analizi</h1>
            <p>Hatalı kaynakları sınıflandırır ve güvenliği gevşetmeden çalışan URL varyantı bulmaya çalışır. Önerilen URL'ler otomatik uygulanmaz.</p>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:20px 0;">
                <?php self::stat_card( 'Hatalı', $counts['error'] ); ?>
                <?php self::stat_card( 'URL Önerisi', $counts['suggested'] ); ?>
                <?php self::stat_card( 'SSL', $counts['ssl'] ); ?>
                <?php self::stat_card( '403', $counts['forbidden'] ); ?>
                <?php self::stat_card( 'DNS / Private', $counts['unsafe_dns'] ); ?>
            </div>

            <div class="card" style="max-width:900px;padding:22px;">
                <h2 style="margin-top:0;">Hatalı Kaynakları Analiz Et</h2>
                <p>Her istekte en fazla <?php echo esc_html( self::BATCH_SIZE ); ?> hata kaydı işlenir. Sertifika doğrulaması kapatılmaz ve private ağ hedeflerine istek gönderilmez.</p>
                <p><button type="button" class="button button-primary button-hero" id="sektorel-health-run">Hatalı Kaynakları Analiz Et</button></p>

                <div id="sektorel-health-progress" style="display:none;margin-top:22px;">
                    <div style="height:24px;background:#e2e4e7;border-radius:3px;overflow:hidden;">
                        <div id="sektorel-health-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:8px;font-weight:600;">
                        <span id="sektorel-health-percent">%0</span>
                        <span id="sektorel-health-count">0 / 0</span>
                    </div>
                </div>

                <div id="sektorel-health-summary" style="display:none;margin-top:18px;padding:15px;background:#f6f7f7;border-left:4px solid #2271b1;"></div>
                <div id="sektorel-health-log" style="display:none;margin-top:16px;max-height:280px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace;"></div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var token = '';
            var total = 0;
            var offset = 0;
            var totals = { suggested: 0, classified: 0 };
            var running = false;

            function log(message) {
                var $log = $('#sektorel-health-log');
                $log.show().append('<div>' + $('<div>').text(message).html() + '</div>');
                $log.scrollTop($log[0].scrollHeight);
            }

            function progress(processed) {
                var percent = total ? Math.min(100, Math.round((processed / total) * 100)) : 0;
                $('#sektorel-health-progress').show();
                $('#sektorel-health-bar').css('width', percent + '%');
                $('#sektorel-health-percent').text('%' + percent);
                $('#sektorel-health-count').text(processed + ' / ' + total);
            }

            function fail(message) {
                running = false;
                $('#sektorel-health-run').prop('disabled', false).text('Tekrar Dene');
                log('Hata: ' + message);
            }

            function finish() {
                running = false;
                $('#sektorel-health-run').prop('disabled', false).text('Analizi Yeniden Çalıştır');
                $('#sektorel-health-bar').css('width', '100%').css('background', '#00a32a');
                $('#sektorel-health-percent').text('%100');
                $('#sektorel-health-summary').show().html(
                    '<strong>Sağlık analizi tamamlandı.</strong><br>' +
                    'Sınıflandırılan: <strong>' + totals.classified + '</strong> &nbsp; ' +
                    'Çalışan alternatif URL bulundu: <strong>' + totals.suggested + '</strong><br><br>' +
                    '<a class="button" href="edit.php?post_type=event_source">Kaynak Listesini Görüntüle</a>'
                );
                log('Analiz kuyruğu tamamlandı.');
            }

            function nextBatch() {
                if (!running) return;
                $.post(ajaxurl, {
                    action: 'sektorel_event_source_health_batch',
                    nonce: '<?php echo esc_js( $nonce ); ?>',
                    token: token,
                    offset: offset
                }).done(function(response) {
                    if (!response || !response.success) {
                        fail((response && response.data && response.data.message) ? response.data.message : 'Batch başarısız.');
                        return;
                    }
                    totals.suggested += Number(response.data.suggested || 0);
                    totals.classified += Number(response.data.classified || 0);
                    offset = Number(response.data.next_offset || total);
                    progress(offset);
                    (response.data.messages || []).forEach(log);
                    if (response.data.done) finish(); else window.setTimeout(nextBatch, 200);
                }).fail(function() { fail('Sunucu isteği başarısız.'); });
            }

            $('#sektorel-health-run').on('click', function() {
                if (running) return;
                running = true;
                token = '';
                total = 0;
                offset = 0;
                totals = { suggested: 0, classified: 0 };
                $('#sektorel-health-summary').hide().empty();
                $('#sektorel-health-log').show().empty();
                $('#sektorel-health-bar').css('background', '#2271b1');
                $(this).prop('disabled', true).text('Kuyruk Hazırlanıyor...');
                log('Hatalı kaynaklar kuyruğa alınıyor...');

                $.post(ajaxurl, {
                    action: 'sektorel_event_source_prepare_health',
                    nonce: '<?php echo esc_js( $nonce ); ?>'
                }).done(function(response) {
                    if (!response || !response.success) {
                        fail((response && response.data && response.data.message) ? response.data.message : 'Kuyruk hazırlanamadı.');
                        return;
                    }
                    token = response.data.token;
                    total = Number(response.data.total || 0);
                    progress(0);
                    $('#sektorel-health-run').text('Analiz Ediliyor...');
                    log(total + ' hatalı kaynak kuyruğa alındı.');
                    nextBatch();
                }).fail(function() { fail('Kuyruk hazırlama isteği başarısız.'); });
            });
        });
        </script>
        <?php
    }

    public static function ajax_prepare() {
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
                    'key'     => 'check_state',
                    'value'   => 'error',
                    'compare' => '=',
                ),
            ),
        ) );

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Analiz edilecek hatalı kaynak bulunamadı.' ) );
        }

        $ids = array_values( array_map( 'absint', $ids ) );
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), $ids, 2 * HOUR_IN_SECONDS );

        wp_send_json_success( array( 'token' => $token, 'total' => count( $ids ) ) );
    }

    public static function ajax_batch() {
        self::require_admin_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Kuyruk anahtarı eksik.' ) );
        }

        $key = self::queue_key( get_current_user_id(), $token );
        $ids = get_transient( $key );
        if ( ! is_array( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Sağlık kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $batch      = array_slice( $ids, $offset, self::BATCH_SIZE );
        $suggested  = 0;
        $classified = 0;
        $messages   = array();

        foreach ( $batch as $source_id ) {
            $source_id = absint( $source_id );
            $error     = (string) get_post_meta( $source_id, 'last_error', true );
            $issue     = self::classify_error( $error );
            $title     = get_the_title( $source_id );

            update_post_meta( $source_id, 'health_issue', $issue );
            update_post_meta( $source_id, 'health_checked_at', current_time( 'mysql' ) );
            delete_post_meta( $source_id, 'suggested_source_url' );
            $classified++;

            $current_url = (string) get_post_meta( $source_id, 'source_url', true );
            $suggestion  = self::find_working_variant( $current_url );
            if ( $suggestion && $suggestion !== $current_url ) {
                update_post_meta( $source_id, 'suggested_source_url', $suggestion );
                $suggested++;
                $messages[] = 'Öneri: ' . $title . ' → ' . $suggestion;
            } else {
                $messages[] = self::issue_label( $issue ) . ': ' . $title;
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
            'suggested'   => $suggested,
            'classified'  => $classified,
            'messages'    => $messages,
            'next_offset' => $next_offset,
            'done'        => $done,
        ) );
    }

    public static function apply_suggestion() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Yetkisiz işlem.', 'sektorel-core' ) );
        }

        $source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
        check_admin_referer( self::NONCE_ACTION . '_apply_' . $source_id );

        if ( ! $source_id || 'event_source' !== get_post_type( $source_id ) ) {
            wp_safe_redirect( admin_url( 'edit.php?post_type=event_source' ) );
            exit;
        }

        $suggested = (string) get_post_meta( $source_id, 'suggested_source_url', true );
        if ( $suggested && self::is_safe_public_url( $suggested ) ) {
            update_post_meta( $source_id, 'source_url', esc_url_raw( $suggested ) );
            update_post_meta( $source_id, 'source_status', 'active' );
            delete_post_meta( $source_id, 'suggested_source_url' );
            update_post_meta( $source_id, 'check_state', 'queued' );
            update_post_meta( $source_id, 'last_result', 'Kaynak URL sağlık analizi önerisiyle güncellendi; yeniden kontrol edilmeli.' );
            update_post_meta( $source_id, 'last_error', '' );
        }

        wp_safe_redirect( admin_url( 'edit.php?post_type=event_source' ) );
        exit;
    }

    private static function classify_error( $error ) {
        $value = strtolower( remove_accents( (string) $error ) );

        if ( false !== strpos( $value, 'private ag' ) || false !== strpos( $value, 'guvenli olmayan' ) ) return 'unsafe_dns';
        if ( false !== strpos( $value, 'ssl') || false !== strpos( $value, 'certificate' ) ) return 'ssl';
        if ( false !== strpos( $value, 'http 403' ) ) return 'forbidden';
        if ( false !== strpos( $value, 'timeout' ) || false !== strpos( $value, 'timed out' ) ) return 'timeout';
        if ( false !== strpos( $value, 'tls' ) || false !== strpos( $value, 'wrong version' ) ) return 'tls';
        if ( false !== strpos( $value, 'failed to connect' ) || false !== strpos( $value, 'could not connect' ) ) return 'connect';
        if ( false !== strpos( $value, 'too many redirects' ) ) return 'redirect';
        if ( false !== strpos( $value, 'http 404' ) ) return 'not_found';
        return 'other';
    }

    private static function issue_label( $issue ) {
        $labels = array(
            'unsafe_dns' => 'DNS / Private Hedef',
            'ssl'        => 'SSL Sertifika',
            'forbidden'  => 'HTTP 403',
            'timeout'    => 'Timeout',
            'tls'        => 'TLS',
            'connect'    => 'Bağlantı',
            'redirect'   => 'Yönlendirme Döngüsü',
            'not_found'  => 'HTTP 404',
            'other'      => 'Diğer',
        );
        return isset( $labels[ $issue ] ) ? $labels[ $issue ] : 'Diğer';
    }

    private static function find_working_variant( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) return '';

        $host = strtolower( (string) $parts['host'] );
        $path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
        $query = isset( $parts['query'] ) && '' !== (string) $parts['query'] ? '?' . (string) $parts['query'] : '';
        $base_host = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;

        $candidates = array(
            'https://' . $base_host . $path . $query,
            'https://www.' . $base_host . $path . $query,
            'http://' . $base_host . $path . $query,
            'http://www.' . $base_host . $path . $query,
        );
        $candidates = array_values( array_unique( array_filter( $candidates ) ) );

        foreach ( $candidates as $candidate ) {
            if ( $candidate === $url || ! self::is_safe_public_url( $candidate ) ) continue;

            $response = wp_safe_remote_get( $candidate, array(
                'timeout'             => self::TIMEOUT,
                'redirection'         => 3,
                'limit_response_size' => 65536,
                'user-agent'          => 'SektorelAjandaBot/1.0; +' . home_url( '/' ),
                'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5' ),
            ) );

            if ( is_wp_error( $response ) ) continue;
            $status = (int) wp_remote_retrieve_response_code( $response );
            if ( $status >= 200 && $status < 400 ) return esc_url_raw( $candidate );
        }

        return '';
    }

    private static function is_safe_public_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return false;
        if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) return false;

        $host = strtolower( rtrim( $parts['host'], '.' ) );
        if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) || preg_match( '/\.local$/i', $host ) ) return false;

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) return self::is_public_ip( $host );

        $ips = gethostbynamel( $host );
        if ( ! is_array( $ips ) || empty( $ips ) ) return false;
        foreach ( $ips as $ip ) {
            if ( ! self::is_public_ip( $ip ) ) return false;
        }
        return true;
    }

    private static function is_public_ip( $ip ) {
        return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    private static function require_admin_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_src_hlt_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function counts() {
        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        $counts = array( 'error' => 0, 'suggested' => 0, 'ssl' => 0, 'forbidden' => 0, 'unsafe_dns' => 0 );
        foreach ( $ids as $source_id ) {
            if ( 'error' === (string) get_post_meta( $source_id, 'check_state', true ) ) $counts['error']++;
            if ( (string) get_post_meta( $source_id, 'suggested_source_url', true ) ) $counts['suggested']++;
            $issue = (string) get_post_meta( $source_id, 'health_issue', true );
            if ( isset( $counts[ $issue ] ) ) $counts[ $issue ]++;
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
