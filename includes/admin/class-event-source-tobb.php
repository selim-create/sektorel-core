<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic adapter for the official TOBB fair calendar.
 *
 * TOBB is treated as the canonical national registry for fair occurrences.
 * This adapter reads the server-rendered official calendar table, normalizes
 * occurrence fields and writes event_candidate records only. It never
 * publishes events directly.
 */
class Sektorel_Event_Source_TOBB {

    const NONCE_ACTION = 'sektorel_tobb_fair_calendar';
    const BATCH_SIZE   = 40;
    const TIMEOUT      = 20;
    const MAX_BODY     = 4194304; // 4 MB.
    const SOURCE_URL   = 'https://fuarlar.tobb.org.tr/FuarTakvimi';
    const ADAPTER      = 'tobb_fair_calendar';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 45 );
        add_action( 'wp_ajax_sektorel_tobb_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_tobb_import_batch', array( __CLASS__, 'ajax_import_batch' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_candidate_meta_box' ), 95, 2 );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'TOBB Fuar Takvimi',
            'TOBB Fuarları',
            'manage_options',
            'sektorel-tobb-fairs',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        $current_year = (int) current_time( 'Y' );
        $nonce        = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <div class="wrap">
            <h1>TOBB Fuar Takvimi</h1>
            <p>TOBB resmî Fuar Takvimi, fuarlar için <strong>Ana kayıt / Canonical</strong> kaynaktır. Bu ekran yalnızca aday kayıt üretir; hiçbir etkinlik otomatik yayınlanmaz.</p>

            <div class="card" style="max-width:980px;padding:22px;margin-top:20px;">
                <h2 style="margin-top:0;">Resmî Takvimi Hazırla</h2>

                <div style="display:grid;grid-template-columns:minmax(140px,220px) 1fr;gap:18px;align-items:end;">
                    <div>
                        <label for="sektorel-tobb-year" style="display:block;font-weight:600;margin-bottom:6px;">Takvim yılı</label>
                        <select id="sektorel-tobb-year" style="width:100%;">
                            <?php for ( $year = $current_year + 1; $year >= max( 2005, $current_year - 3 ); $year-- ) : ?>
                                <option value="<?php echo esc_attr( (string) $year ); ?>" <?php selected( $year, $current_year ); ?>><?php echo esc_html( (string) $year ); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" id="sektorel-tobb-upcoming" checked />
                            Yalnızca devam eden ve gelecek fuarları hazırla
                        </label>
                        <p class="description" style="margin:0;">İstersen kutuyu kaldırarak seçilen yılın geçmiş kayıtlarını da adaylara alabilirsin.</p>
                    </div>
                </div>

                <p style="margin-top:20px;">
                    <button type="button" class="button button-primary button-hero" id="sektorel-tobb-prepare">TOBB Takvimini Kontrol Et</button>
                    <a href="<?php echo esc_url( self::SOURCE_URL ); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer" style="margin-left:8px;">Resmî Takvimi Aç</a>
                </p>

                <div id="sektorel-tobb-preview" style="display:none;margin-top:22px;padding:16px;background:#f6f7f7;border-left:4px solid #2271b1;"></div>

                <p id="sektorel-tobb-import-actions" style="display:none;margin-top:18px;">
                    <button type="button" class="button button-primary" id="sektorel-tobb-import">Hazırlanan Kayıtları Adaylara Aktar</button>
                </p>

                <div id="sektorel-tobb-progress" style="display:none;margin-top:20px;">
                    <div style="height:22px;background:#e2e4e7;overflow:hidden;">
                        <div id="sektorel-tobb-bar" style="width:0;height:100%;background:#2271b1;"></div>
                    </div>
                    <p><strong id="sektorel-tobb-count">0 / 0</strong></p>
                </div>

                <div id="sektorel-tobb-summary" style="display:none;margin-top:16px;padding:14px;background:#f6f7f7;border-left:4px solid #00a32a;"></div>
                <div id="sektorel-tobb-log" style="display:none;margin-top:16px;max-height:280px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace;"></div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var token = '';
            var total = 0;
            var offset = 0;
            var running = false;
            var totals = {created:0, updated:0, unchanged:0, failed:0};

            function log(message, isError) {
                var $log = $('#sektorel-tobb-log');
                $log.show().append('<div style="color:' + (isError ? '#ff8080' : '#f0f0f1') + '">' + $('<div>').text(message).html() + '</div>');
                $log.scrollTop($log[0].scrollHeight);
            }

            function progress() {
                var percent = total ? Math.min(100, Math.round((offset / total) * 100)) : 0;
                $('#sektorel-tobb-progress').show();
                $('#sektorel-tobb-bar').css('width', percent + '%');
                $('#sektorel-tobb-count').text(offset + ' / ' + total);
            }

            function fail(message) {
                running = false;
                $('#sektorel-tobb-prepare, #sektorel-tobb-import').prop('disabled', false);
                log(message, true);
            }

            function finish() {
                running = false;
                $('#sektorel-tobb-import').prop('disabled', false).text('Tekrar Aktar');
                $('#sektorel-tobb-prepare').prop('disabled', false);
                $('#sektorel-tobb-bar').css('width', '100%').css('background', '#00a32a');
                $('#sektorel-tobb-summary').show().html(
                    '<strong>TOBB aktarımı tamamlandı.</strong><br>' +
                    'Yeni aday: <strong>' + totals.created + '</strong> &nbsp; ' +
                    'Güncellendi: <strong>' + totals.updated + '</strong> &nbsp; ' +
                    'Değişmedi: <strong>' + totals.unchanged + '</strong> &nbsp; ' +
                    'Hata: <strong>' + totals.failed + '</strong><br><br>' +
                    '<a class="button" href="edit.php?post_type=event_candidate">Aday Etkinlikleri Gör</a>'
                );
            }

            function nextBatch() {
                if (!running) return;

                $.post(ajaxurl, {
                    action: 'sektorel_tobb_import_batch',
                    nonce: '<?php echo esc_js( $nonce ); ?>',
                    token: token,
                    offset: offset
                }).done(function(response) {
                    if (!response || !response.success) {
                        fail(response && response.data && response.data.message ? response.data.message : 'TOBB batch işlenemedi.');
                        return;
                    }

                    totals.created += Number(response.data.created || 0);
                    totals.updated += Number(response.data.updated || 0);
                    totals.unchanged += Number(response.data.unchanged || 0);
                    totals.failed += Number(response.data.failed || 0);
                    offset = Number(response.data.next_offset || total);
                    progress();

                    (response.data.messages || []).forEach(function(message) {
                        log(message, false);
                    });

                    if (response.data.done) {
                        finish();
                    } else {
                        setTimeout(nextBatch, 150);
                    }
                }).fail(function() {
                    fail('TOBB batch isteği başarısız oldu.');
                });
            }

            $('#sektorel-tobb-prepare').on('click', function() {
                if (running) return;

                token = '';
                total = 0;
                offset = 0;
                totals = {created:0, updated:0, unchanged:0, failed:0};

                $('#sektorel-tobb-preview, #sektorel-tobb-summary, #sektorel-tobb-import-actions, #sektorel-tobb-progress').hide();
                $('#sektorel-tobb-log').empty().show();
                $('#sektorel-tobb-bar').css('background', '#2271b1');
                $(this).prop('disabled', true).text('TOBB Kontrol Ediliyor...');
                log('Resmî TOBB takvimi indiriliyor ve tablo doğrulanıyor...');

                $.post(ajaxurl, {
                    action: 'sektorel_tobb_prepare',
                    nonce: '<?php echo esc_js( $nonce ); ?>',
                    year: $('#sektorel-tobb-year').val(),
                    upcoming_only: $('#sektorel-tobb-upcoming').is(':checked') ? 1 : 0
                }).done(function(response) {
                    $('#sektorel-tobb-prepare').prop('disabled', false).text('TOBB Takvimini Yeniden Kontrol Et');

                    if (!response || !response.success) {
                        fail(response && response.data && response.data.message ? response.data.message : 'TOBB takvimi hazırlanamadı.');
                        return;
                    }

                    token = response.data.token;
                    total = Number(response.data.total || 0);

                    var preview = '<strong>Takvim doğrulandı.</strong><br>' +
                        'TOBB tablosundaki geçerli kayıt: <strong>' + Number(response.data.parsed_total || 0) + '</strong><br>' +
                        'Aktarıma hazırlanmış kayıt: <strong>' + total + '</strong>';

                    if (Number(response.data.past_skipped || 0) > 0) {
                        preview += '<br>Geçmiş olduğu için atlanan: <strong>' + Number(response.data.past_skipped || 0) + '</strong>';
                    }

                    if (response.data.preview && response.data.preview.length) {
                        preview += '<hr style="margin:12px 0;"><strong>İlk kayıtlar:</strong><ul style="margin-bottom:0;">';
                        response.data.preview.forEach(function(item) {
                            preview += '<li>' + $('<div>').text(item).html() + '</li>';
                        });
                        preview += '</ul>';
                    }

                    $('#sektorel-tobb-preview').show().html(preview);

                    if (total > 0) {
                        $('#sektorel-tobb-import-actions').show();
                        log(total + ' TOBB occurrence kaydı aktarım için hazır.');
                    } else {
                        log('Aktarılacak kayıt bulunamadı.');
                    }
                }).fail(function() {
                    $('#sektorel-tobb-prepare').prop('disabled', false).text('TOBB Takvimini Kontrol Et');
                    fail('TOBB hazırlık isteği başarısız oldu.');
                });
            });

            $('#sektorel-tobb-import').on('click', function() {
                if (running || !token || total < 1) return;

                running = true;
                offset = 0;
                totals = {created:0, updated:0, unchanged:0, failed:0};
                $(this).prop('disabled', true).text('Adaylar Aktarılıyor...');
                $('#sektorel-tobb-prepare').prop('disabled', true);
                $('#sektorel-tobb-summary').hide().empty();
                progress();
                nextBatch();
            });
        });
        </script>
        <?php
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $current_year = (int) current_time( 'Y' );
        $year         = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : $current_year;
        $upcoming     = ! empty( $_POST['upcoming_only'] );

        if ( $year < 2005 || $year > $current_year + 1 ) {
            wp_send_json_error( array( 'message' => 'Geçersiz TOBB takvim yılı.' ) );
        }

        $source_id = self::ensure_source();
        if ( is_wp_error( $source_id ) ) {
            wp_send_json_error( array( 'message' => $source_id->get_error_message() ) );
        }

        $rows = self::fetch_calendar( $year );
        if ( is_wp_error( $rows ) ) {
            wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
        }

        $parsed_total = count( $rows );
        $past_skipped = 0;

        if ( $upcoming ) {
            $today = current_time( 'Y-m-d' );
            $rows = array_values(
                array_filter(
                    $rows,
                    static function( $row ) use ( $today, &$past_skipped ) {
                        $comparison = ! empty( $row['end_date'] ) ? $row['end_date'] : $row['start_date'];
                        if ( $comparison && substr( $comparison, 0, 10 ) < $today ) {
                            $past_skipped++;
                            return false;
                        }
                        return true;
                    }
                )
            );
        }

        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array(
                'source_id' => absint( $source_id ),
                'year'      => $year,
                'rows'      => $rows,
                'stats'     => array(
                    'created'   => 0,
                    'updated'   => 0,
                    'unchanged' => 0,
                    'failed'    => 0,
                ),
            ),
            2 * HOUR_IN_SECONDS
        );

        $preview = array();
        foreach ( array_slice( $rows, 0, 5 ) as $row ) {
            $preview[] = sprintf(
                '%s — %s / %s',
                $row['start_date'],
                $row['title'],
                $row['city']
            );
        }

        update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_result', sprintf( 'TOBB %d: %d geçerli fuar kaydı okundu.', $year, $parsed_total ) );
        delete_post_meta( $source_id, 'last_error' );

        wp_send_json_success(
            array(
                'token'        => $token,
                'total'        => count( $rows ),
                'parsed_total' => $parsed_total,
                'past_skipped' => $past_skipped,
                'source_id'    => absint( $source_id ),
                'preview'      => $preview,
            )
        );
    }

    public static function ajax_import_batch() {
        self::require_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $queue  = get_transient( self::queue_key( get_current_user_id(), $token ) );

        if ( ! is_array( $queue ) || empty( $queue['source_id'] ) || ! isset( $queue['rows'] ) || ! is_array( $queue['rows'] ) ) {
            wp_send_json_error( array( 'message' => 'TOBB aktarım kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $source_id = absint( $queue['source_id'] );
        $year      = isset( $queue['year'] ) ? absint( $queue['year'] ) : (int) current_time( 'Y' );
        $rows      = $queue['rows'];
        $batch     = array_slice( $rows, $offset, self::BATCH_SIZE );

        $counts = array(
            'created'   => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'failed'    => 0,
        );
        $messages = array();

        foreach ( $batch as $row ) {
            $result = self::upsert_candidate( $source_id, $year, $row );

            if ( is_wp_error( $result ) ) {
                $counts['failed']++;
                $messages[] = 'Hata: ' . $row['title'] . ' — ' . $result->get_error_message();
                continue;
            }

            if ( isset( $counts[ $result ] ) ) {
                $counts[ $result ]++;
            }
        }

        $stats = isset( $queue['stats'] ) && is_array( $queue['stats'] )
            ? wp_parse_args(
                $queue['stats'],
                array(
                    'created'   => 0,
                    'updated'   => 0,
                    'unchanged' => 0,
                    'failed'    => 0,
                )
            )
            : array(
                'created'   => 0,
                'updated'   => 0,
                'unchanged' => 0,
                'failed'    => 0,
            );

        foreach ( array_keys( $counts ) as $key ) {
            $stats[ $key ] = absint( $stats[ $key ] ) + absint( $counts[ $key ] );
        }

        $total       = count( $rows );
        $next_offset = min( $total, $offset + count( $batch ) );
        $done        = $next_offset >= $total;

        if ( $done ) {
            delete_transient( self::queue_key( get_current_user_id(), $token ) );
            update_post_meta(
                $source_id,
                'last_result',
                sprintf(
                    'TOBB %d aktarımı tamamlandı. Yeni: %d, güncel: %d, değişmedi: %d, hata: %d.',
                    $year,
                    $stats['created'],
                    $stats['updated'],
                    $stats['unchanged'],
                    $stats['failed']
                )
            );
        } else {
            $queue['stats'] = $stats;
            set_transient( self::queue_key( get_current_user_id(), $token ), $queue, 2 * HOUR_IN_SECONDS );
        }

        wp_send_json_success(
            array(
                'created'     => $counts['created'],
                'updated'     => $counts['updated'],
                'unchanged'   => $counts['unchanged'],
                'failed'      => $counts['failed'],
                'messages'    => array_slice( $messages, 0, 20 ),
                'next_offset' => $next_offset,
                'done'        => $done,
            )
        );
    }

    public static function add_candidate_meta_box( $post_type, $post ) {
        if ( 'event_candidate' !== $post_type || ! $post || self::ADAPTER !== (string) get_post_meta( $post->ID, 'source_adapter', true ) ) {
            return;
        }

        add_meta_box(
            'sektorel_tobb_candidate_source',
            'TOBB Kaynak Verisi',
            array( __CLASS__, 'render_candidate_meta_box' ),
            'event_candidate',
            'side',
            'default'
        );
    }

    public static function render_candidate_meta_box( $post ) {
        $fields = array(
            'tobb_row_no'         => 'Sıra No',
            'tobb_year'           => 'Takvim yılı',
            'tobb_city'           => 'Şehir',
            'tobb_fair_type'      => 'Fuar türü',
            'tobb_subject'        => 'Konu',
            'tobb_product_groups' => 'Ürün / hizmet grupları',
            'tobb_topic_1'        => 'Konu 1',
            'tobb_topic_2'        => 'Konu 2',
            'tobb_topic_3'        => 'Konu 3',
            'tobb_email'          => 'E-posta',
        );

        echo '<table style="width:100%;border-collapse:collapse;">';
        foreach ( $fields as $key => $label ) {
            $value = trim( (string) get_post_meta( $post->ID, $key, true ) );
            if ( '' === $value ) {
                continue;
            }
            echo '<tr>';
            echo '<th style="text-align:left;vertical-align:top;padding:4px 8px 4px 0;width:92px;">' . esc_html( $label ) . '</th>';
            echo '<td style="padding:4px 0;word-break:break-word;">' . esc_html( $value ) . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        $event_url = trim( (string) get_post_meta( $post->ID, 'event_url', true ) );
        if ( $event_url ) {
            echo '<p><a href="' . esc_url( $event_url ) . '" target="_blank" rel="noopener noreferrer">Fuar web sitesini aç</a></p>';
        }
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function ensure_source() {
        $source_id = 0;

        $by_adapter = get_posts(
            array(
                'post_type'      => 'event_source',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => 'source_adapter',
                'meta_value'     => self::ADAPTER,
                'no_found_rows'  => true,
            )
        );

        if ( ! empty( $by_adapter[0] ) ) {
            $source_id = absint( $by_adapter[0] );
        }

        if ( ! $source_id ) {
            $possible = get_posts(
                array(
                    'post_type'      => 'event_source',
                    'post_status'    => 'any',
                    'posts_per_page' => 20,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => 'source_url',
                            'value'   => 'fuarlar.tobb.org.tr/FuarTakvimi',
                            'compare' => 'LIKE',
                        ),
                    ),
                    'no_found_rows'  => true,
                )
            );

            if ( ! empty( $possible[0] ) ) {
                $source_id = absint( $possible[0] );
            }
        }

        if ( ! $source_id ) {
            $source_id = wp_insert_post(
                array(
                    'post_type'   => 'event_source',
                    'post_status' => 'publish',
                    'post_title'  => 'TOBB Fuar Takvimi',
                ),
                true
            );

            if ( is_wp_error( $source_id ) ) {
                return $source_id;
            }
        }

        if ( 'publish' !== get_post_status( $source_id ) ) {
            wp_update_post(
                array(
                    'ID'          => $source_id,
                    'post_status' => 'publish',
                )
            );
        }

        update_post_meta( $source_id, 'source_url', self::SOURCE_URL );
        update_post_meta( $source_id, 'source_type', 'Fuar' );
        update_post_meta( $source_id, 'parser_type', 'adapter' );
        update_post_meta( $source_id, 'source_status', 'active' );
        update_post_meta( $source_id, 'source_role', 'canonical_registry' );
        update_post_meta( $source_id, 'source_adapter', self::ADAPTER );

        return absint( $source_id );
    }

    private static function fetch_calendar( $year ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'tobb_dom_missing', 'Sunucuda DOMDocument desteği bulunmadığı için TOBB tablosu okunamıyor.' );
        }

        $url = self::calendar_url( $year );
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => self::TIMEOUT,
                'redirection'         => 3,
                'limit_response_size' => self::MAX_BODY,
                'user-agent'          => 'SektorelAjandaBot/1.0; +' . home_url( '/' ),
                'headers'             => array(
                    'Accept' => 'text/html,application/xhtml+xml',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'tobb_fetch_failed', 'TOBB isteği başarısız: ' . $response->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error( 'tobb_http_error', 'TOBB HTTP yanıtı: ' . $code );
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'tobb_empty_body', 'TOBB takvim sayfası boş döndü.' );
        }

        $rows = self::parse_calendar_html( $body );
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }

        if ( count( $rows ) < 20 ) {
            return new WP_Error( 'tobb_too_few_rows', 'TOBB tablosu beklenenden az kayıt döndürdü; güvenlik nedeniyle aktarım durduruldu.' );
        }

        return $rows;
    }

    private static function parse_calendar_html( $html ) {
        $dom      = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded ) {
            return new WP_Error( 'tobb_html_invalid', 'TOBB HTML verisi okunamadı.' );
        }

        $xpath   = new DOMXPath( $dom );
        $tables  = $xpath->query( '//table' );
        $records = array();

        if ( ! $tables || 0 === $tables->length ) {
            return new WP_Error( 'tobb_table_missing', 'TOBB sayfasında fuar tablosu bulunamadı.' );
        }

        foreach ( $tables as $table ) {
            $row_nodes = $xpath->query( './/tr', $table );
            if ( ! $row_nodes || 0 === $row_nodes->length ) {
                continue;
            }

            $header_map = null;

            foreach ( $row_nodes as $row_node ) {
                $cell_nodes = $xpath->query( './th | ./td', $row_node );
                if ( ! $cell_nodes || 0 === $cell_nodes->length ) {
                    continue;
                }

                $cells = array();
                foreach ( $cell_nodes as $cell_node ) {
                    $cells[] = self::clean_cell( $cell_node->textContent );
                }

                if ( null === $header_map ) {
                    $candidate_map = self::header_map( $cells );
                    if ( self::valid_header_map( $candidate_map ) ) {
                        $header_map = $candidate_map;
                    }
                    continue;
                }

                $record = self::record_from_cells( $cells, $header_map );
                if ( ! $record ) {
                    continue;
                }

                $records[] = $record;
            }

            if ( count( $records ) >= 20 ) {
                break;
            }
        }

        if ( ! $records ) {
            return new WP_Error( 'tobb_records_missing', 'TOBB fuar satırları bulunamadı veya tablo kolonları değişmiş olabilir.' );
        }

        return $records;
    }

    private static function header_map( $cells ) {
        $map = array();

        foreach ( $cells as $index => $value ) {
            $normalized = self::normalize_text( $value );

            $aliases = array(
                'row_no'         => array( 'sira no', 'sira' ),
                'start'          => array( 'baslangic tar', 'baslangic tarihi', 'baslangic' ),
                'end'            => array( 'bitis tar', 'bitis tarihi', 'bitis' ),
                'title'          => array( 'fuarin adi', 'fuar adi' ),
                'subject'        => array( 'konusu', 'fuar konusu' ),
                'products'       => array( 'baslica urun hizmet gruplari', 'urun hizmet gruplari' ),
                'fair_type'      => array( 'turu', 'fuar turu' ),
                'venue'          => array( 'fuar yeri', 'fuar alani' ),
                'city'           => array( 'sehir', 'il' ),
                'organizer'      => array( 'duzenleyici', 'organizer' ),
                'topic_1'        => array( 'konu 1' ),
                'topic_2'        => array( 'konu 2' ),
                'topic_3'        => array( 'konu 3' ),
                'web'            => array( 'web', 'web sitesi' ),
                'email'          => array( 'e mail', 'email' ),
            );

            foreach ( $aliases as $key => $values ) {
                if ( isset( $map[ $key ] ) ) {
                    continue;
                }
                if ( in_array( $normalized, $values, true ) ) {
                    $map[ $key ] = $index;
                }
            }
        }

        return $map;
    }

    private static function valid_header_map( $map ) {
        foreach ( array( 'start', 'end', 'title', 'venue', 'city', 'organizer' ) as $required ) {
            if ( ! isset( $map[ $required ] ) ) {
                return false;
            }
        }
        return true;
    }

    private static function record_from_cells( $cells, $map ) {
        $title = self::cell_by_key( $cells, $map, 'title' );
        $start = self::normalize_date( self::cell_by_key( $cells, $map, 'start' ) );
        $end   = self::normalize_date( self::cell_by_key( $cells, $map, 'end' ) );

        if ( ! $title || ! $start ) {
            return null;
        }

        $row_no = self::cell_by_key( $cells, $map, 'row_no' );
        if ( $row_no && ! preg_match( '/^\d+$/', preg_replace( '/\s+/', '', $row_no ) ) ) {
            return null;
        }

        return array(
            'row_no'         => $row_no,
            'start_date'     => $start,
            'end_date'       => $end,
            'title'          => $title,
            'subject'        => self::cell_by_key( $cells, $map, 'subject' ),
            'product_groups' => self::cell_by_key( $cells, $map, 'products' ),
            'fair_type'      => self::cell_by_key( $cells, $map, 'fair_type' ),
            'venue'          => self::cell_by_key( $cells, $map, 'venue' ),
            'city'           => self::cell_by_key( $cells, $map, 'city' ),
            'organizer'      => self::cell_by_key( $cells, $map, 'organizer' ),
            'topic_1'        => self::cell_by_key( $cells, $map, 'topic_1' ),
            'topic_2'        => self::cell_by_key( $cells, $map, 'topic_2' ),
            'topic_3'        => self::cell_by_key( $cells, $map, 'topic_3' ),
            'web'            => self::normalize_web_url( self::cell_by_key( $cells, $map, 'web' ) ),
            'email'          => self::cell_by_key( $cells, $map, 'email' ),
        );
    }

    private static function cell_by_key( $cells, $map, $key ) {
        if ( ! isset( $map[ $key ] ) ) {
            return '';
        }

        $index = $map[ $key ];
        return isset( $cells[ $index ] ) ? self::clean_cell( $cells[ $index ] ) : '';
    }

    private static function clean_cell( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = preg_replace( '/[\x{00A0}\s]+/u', ' ', $value );
        return trim( (string) $value );
    }

    private static function normalize_date( $value ) {
        $value = trim( (string) $value );

        if ( ! preg_match( '/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $value, $matches ) ) {
            return '';
        }

        $day   = (int) $matches[1];
        $month = (int) $matches[2];
        $year  = (int) $matches[3];

        if ( ! checkdate( $month, $day, $year ) ) {
            return '';
        }

        return sprintf( '%04d-%02d-%02dT00:00', $year, $month, $day );
    }

    private static function normalize_web_url( $value ) {
        $value = trim( (string) $value );
        if ( ! $value || in_array( $value, array( '-', '–', '—', '0' ), true ) ) {
            return '';
        }

        $parts = preg_split( '/[\s;,]+/u', $value );
        $value = ! empty( $parts[0] ) ? trim( $parts[0] ) : '';
        if ( ! $value ) {
            return '';
        }

        if ( ! preg_match( '#^https?://#i', $value ) ) {
            $value = 'https://' . ltrim( $value, '/' );
        }

        $url = esc_url_raw( $value, array( 'http', 'https' ) );
        return $url && wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
    }

    private static function upsert_candidate( $source_id, $year, $row ) {
        $title = sanitize_text_field( isset( $row['title'] ) ? $row['title'] : '' );
        $start = isset( $row['start_date'] ) ? trim( (string) $row['start_date'] ) : '';
        $city  = sanitize_text_field( isset( $row['city'] ) ? $row['city'] : '' );

        if ( ! $title || ! $start ) {
            return new WP_Error( 'tobb_candidate_required', 'Başlık veya başlangıç tarihi eksik.' );
        }

        $occurrence_key = sha1(
            self::normalize_text( $title ) . '|' .
            substr( $start, 0, 10 ) . '|' .
            self::normalize_text( $city )
        );

        $candidate_id = self::find_existing_candidate( $source_id, $occurrence_key, $title, $start );

        $record_hash = sha1(
            wp_json_encode(
                array(
                    'year' => absint( $year ),
                    'row'  => $row,
                ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );

        $existing_hash = $candidate_id ? (string) get_post_meta( $candidate_id, 'source_record_hash', true ) : '';
        $status        = $candidate_id ? 'updated' : 'created';

        $content = '';
        if ( ! empty( $row['subject'] ) ) {
            $content = wp_kses_post( wpautop( $row['subject'] ) );
        }

        $postarr = array(
            'post_type'    => 'event_candidate',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => $content,
        );

        if ( $candidate_id ) {
            $postarr['ID'] = $candidate_id;
            $result = wp_update_post( $postarr, true );
        } else {
            $result = wp_insert_post( $postarr, true );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $candidate_id = absint( $result );

        $current_candidate_status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
        if ( ! $current_candidate_status ) {
            update_post_meta( $candidate_id, 'candidate_status', 'new' );
        }

        $meta = array(
            'candidate_fingerprint' => $occurrence_key,
            'source_id'             => absint( $source_id ),
            'source_url'            => self::calendar_url( $year ),
            'event_url'             => ! empty( $row['web'] ) ? $row['web'] : '',
            'start_date'            => $start,
            'end_date'              => ! empty( $row['end_date'] ) ? $row['end_date'] : '',
            'location_type'         => 'physical',
            'venue'                 => ! empty( $row['venue'] ) ? $row['venue'] : '',
            'organizer'             => ! empty( $row['organizer'] ) ? $row['organizer'] : '',
            'registration_link'     => '',
            'parser_type'           => 'adapter',
            'source_adapter'        => self::ADAPTER,
            'source_record_hash'    => $record_hash,
            'tobb_occurrence_key'   => $occurrence_key,
            'tobb_year'             => absint( $year ),
            'tobb_row_no'           => ! empty( $row['row_no'] ) ? $row['row_no'] : '',
            'tobb_city'             => $city,
            'tobb_subject'          => ! empty( $row['subject'] ) ? $row['subject'] : '',
            'tobb_product_groups'   => ! empty( $row['product_groups'] ) ? $row['product_groups'] : '',
            'tobb_fair_type'        => ! empty( $row['fair_type'] ) ? $row['fair_type'] : '',
            'tobb_topic_1'          => ! empty( $row['topic_1'] ) ? $row['topic_1'] : '',
            'tobb_topic_2'          => ! empty( $row['topic_2'] ) ? $row['topic_2'] : '',
            'tobb_topic_3'          => ! empty( $row['topic_3'] ) ? $row['topic_3'] : '',
            'tobb_email'            => ! empty( $row['email'] ) ? $row['email'] : '',
            'source_last_seen_at'   => current_time( 'mysql' ),
        );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $candidate_id, $key, $value );
        }

        if ( $candidate_id && $existing_hash && hash_equals( $existing_hash, $record_hash ) ) {
            return 'unchanged';
        }

        return $status;
    }

    private static function find_existing_candidate( $source_id, $occurrence_key, $title, $start ) {
        $by_key = get_posts(
            array(
                'post_type'      => 'event_candidate',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'   => 'source_id',
                        'value' => absint( $source_id ),
                    ),
                    array(
                        'key'   => 'tobb_occurrence_key',
                        'value' => $occurrence_key,
                    ),
                ),
                'no_found_rows'  => true,
            )
        );

        if ( ! empty( $by_key[0] ) ) {
            return absint( $by_key[0] );
        }

        $same_start = get_posts(
            array(
                'post_type'      => 'event_candidate',
                'post_status'    => 'any',
                'posts_per_page' => 20,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'   => 'source_id',
                        'value' => absint( $source_id ),
                    ),
                    array(
                        'key'   => 'start_date',
                        'value' => $start,
                    ),
                ),
                'no_found_rows'  => true,
            )
        );

        $title_norm = self::normalize_text( $title );
        foreach ( $same_start as $candidate_id ) {
            if ( self::normalize_text( get_the_title( $candidate_id ) ) === $title_norm ) {
                return absint( $candidate_id );
            }
        }

        return 0;
    }

    private static function normalize_text( $value ) {
        $value = remove_accents( self::clean_cell( $value ) );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function calendar_url( $year ) {
        return self::SOURCE_URL . '/' . absint( $year );
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_tobb_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
