<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Source_Importer {

    const NONCE_ACTION = 'sektorel_event_source_import';
    const BATCH_SIZE   = 25;
    const MAX_FILE_SIZE = 5242880; // 5 MB.

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 20 );
        add_action( 'wp_ajax_sektorel_event_source_prepare_import', array( __CLASS__, 'ajax_prepare_import' ) );
        add_action( 'wp_ajax_sektorel_event_source_import_batch', array( __CLASS__, 'ajax_import_batch' ) );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'Etkinlik Kaynaklarını İçe Aktar',
            'Kaynak İçe Aktar',
            'manage_options',
            'sektorel-event-source-import',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'sektorel-core' ) );
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <div class="wrap">
            <h1>Etkinlik Kaynaklarını İçe Aktar</h1>
            <p>Excel veya CSV kaynak listenizi yükleyin. Sistem dosyayı önce doğrular, ardından kayıtları <?php echo esc_html( self::BATCH_SIZE ); ?>'erli paketlerle işler.</p>

            <div class="card" style="max-width:900px;padding:22px;margin-top:20px;">
                <h2 style="margin-top:0;">Kaynak Listesi</h2>
                <p class="description">Beklenen kolonlar: <strong>NO</strong>, <strong>Etkinlik İsmi</strong>, <strong>Web Sitesi</strong>, <strong>Türü</strong>. XLSX ve CSV desteklenir.</p>

                <input type="file" id="sektorel-source-file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display:block;margin:18px 0;width:100%;" />

                <p>
                    <button type="button" class="button button-primary button-hero" id="sektorel-source-import-start">Dosyayı Kontrol Et ve İçe Aktar</button>
                </p>

                <div id="sektorel-source-import-progress" style="display:none;margin-top:24px;">
                    <div style="height:24px;background:#e2e4e7;border-radius:3px;overflow:hidden;">
                        <div id="sektorel-source-import-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s;"></div>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:8px;font-weight:600;">
                        <span id="sektorel-source-import-percent">%0</span>
                        <span id="sektorel-source-import-count">0 / 0</span>
                    </div>
                </div>

                <div id="sektorel-source-import-summary" style="display:none;margin-top:20px;padding:16px;background:#f6f7f7;border-left:4px solid #2271b1;"></div>
                <div id="sektorel-source-import-log" style="display:none;margin-top:16px;max-height:220px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace;"></div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var importToken = '';
            var total = 0;
            var offset = 0;
            var totals = { created: 0, skipped: 0, failed: 0 };
            var running = false;

            function log(message, isError) {
                var $log = $('#sektorel-source-import-log');
                $log.show().append('<div style="color:' + (isError ? '#ff8080' : '#f0f0f1') + '">' + $('<div>').text(message).html() + '</div>');
                $log.scrollTop($log[0].scrollHeight);
            }

            function updateProgress(processed) {
                var percent = total ? Math.min(100, Math.round((processed / total) * 100)) : 0;
                $('#sektorel-source-import-progress').show();
                $('#sektorel-source-import-bar').css('width', percent + '%');
                $('#sektorel-source-import-percent').text('%' + percent);
                $('#sektorel-source-import-count').text(processed + ' / ' + total);
            }

            function finish() {
                running = false;
                $('#sektorel-source-import-start').prop('disabled', false).text('Yeni Dosya İçe Aktar');
                $('#sektorel-source-import-bar').css('width', '100%').css('background', '#00a32a');
                $('#sektorel-source-import-percent').text('%100');
                $('#sektorel-source-import-summary').show().html(
                    '<strong>İçe aktarma tamamlandı.</strong><br>' +
                    'Yeni kaynak: <strong>' + totals.created + '</strong> &nbsp; ' +
                    'Zaten mevcut: <strong>' + totals.skipped + '</strong> &nbsp; ' +
                    'Hata: <strong>' + totals.failed + '</strong><br><br>' +
                    '<a class="button" href="edit.php?post_type=event_source">Etkinlik Kaynaklarını Görüntüle</a>'
                );
                log('İşlem tamamlandı.');
            }

            function importNextBatch() {
                if (!running) return;

                $.post(ajaxurl, {
                    action: 'sektorel_event_source_import_batch',
                    nonce: '<?php echo esc_js( $nonce ); ?>',
                    token: importToken,
                    offset: offset
                }).done(function(response) {
                    if (!response || !response.success) {
                        running = false;
                        $('#sektorel-source-import-start').prop('disabled', false).text('Tekrar Dene');
                        log((response && response.data && response.data.message) ? response.data.message : 'Batch işlenemedi.', true);
                        return;
                    }

                    totals.created += Number(response.data.created || 0);
                    totals.skipped += Number(response.data.skipped || 0);
                    totals.failed += Number(response.data.failed || 0);
                    offset = Number(response.data.next_offset || total);
                    updateProgress(offset);

                    if (response.data.messages && response.data.messages.length) {
                        response.data.messages.forEach(function(message) { log(message, false); });
                    }

                    if (response.data.done) {
                        finish();
                    } else {
                        importNextBatch();
                    }
                }).fail(function() {
                    running = false;
                    $('#sektorel-source-import-start').prop('disabled', false).text('Tekrar Dene');
                    log('Sunucu isteği başarısız oldu. İşlem durduruldu.', true);
                });
            }

            $('#sektorel-source-import-start').on('click', function() {
                if (running) return;

                var input = document.getElementById('sektorel-source-file');
                if (!input.files || !input.files.length) {
                    alert('Lütfen XLSX veya CSV dosyası seçin.');
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'sektorel_event_source_prepare_import');
                formData.append('nonce', '<?php echo esc_js( $nonce ); ?>');
                formData.append('file', input.files[0]);

                running = true;
                importToken = '';
                total = 0;
                offset = 0;
                totals = { created: 0, skipped: 0, failed: 0 };
                $('#sektorel-source-import-summary').hide().empty();
                $('#sektorel-source-import-log').show().empty();
                $('#sektorel-source-import-bar').css('background', '#2271b1');
                $(this).prop('disabled', true).text('Dosya Okunuyor...');
                log('Dosya yükleniyor ve doğrulanıyor...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                }).done(function(response) {
                    if (!response || !response.success) {
                        running = false;
                        $('#sektorel-source-import-start').prop('disabled', false).text('Tekrar Dene');
                        log((response && response.data && response.data.message) ? response.data.message : 'Dosya hazırlanamadı.', true);
                        return;
                    }

                    importToken = response.data.token;
                    total = Number(response.data.total || 0);
                    offset = 0;
                    updateProgress(0);
                    $('#sektorel-source-import-start').text('İçe Aktarılıyor...');
                    log(total + ' kaynak satırı doğrulandı. Batch aktarımı başlıyor.');
                    importNextBatch();
                }).fail(function() {
                    running = false;
                    $('#sektorel-source-import-start').prop('disabled', false).text('Tekrar Dene');
                    log('Dosya yükleme isteği başarısız oldu.', true);
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_prepare_import() {
        self::require_admin_request();

        if ( empty( $_FILES['file'] ) || ! isset( $_FILES['file']['tmp_name'], $_FILES['file']['name'] ) ) {
            wp_send_json_error( array( 'message' => 'Dosya alınamadı.' ) );
        }

        $file = $_FILES['file'];
        $error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ( UPLOAD_ERR_OK !== $error ) {
            wp_send_json_error( array( 'message' => 'Dosya yükleme hatası: ' . $error ) );
        }

        $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
        if ( $size <= 0 || $size > self::MAX_FILE_SIZE ) {
            wp_send_json_error( array( 'message' => 'Dosya boş veya 5 MB sınırını aşıyor.' ) );
        }

        $name = sanitize_file_name( wp_unslash( $file['name'] ) );
        $extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Yalnızca .xlsx ve .csv dosyaları desteklenir.' ) );
        }

        $tmp_name = (string) $file['tmp_name'];
        $rows = 'xlsx' === $extension ? self::parse_xlsx( $tmp_name ) : self::parse_csv( $tmp_name );

        if ( is_wp_error( $rows ) ) {
            wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
        }

        $normalized = self::normalize_rows( $rows );
        if ( empty( $normalized ) ) {
            wp_send_json_error( array( 'message' => 'İçe aktarılabilir etkinlik kaynağı bulunamadı. Kolon başlıklarını kontrol edin.' ) );
        }

        $user_id = get_current_user_id();
        $token = wp_generate_password( 20, false, false );
        set_transient( self::transient_key( $user_id, $token ), $normalized, HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $normalized ),
        ) );
    }

    public static function ajax_import_batch() {
        self::require_admin_request();

        $token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'İçe aktarma anahtarı eksik.' ) );
        }

        $user_id = get_current_user_id();
        $key = self::transient_key( $user_id, $token );
        $rows = get_transient( $key );
        if ( ! is_array( $rows ) ) {
            wp_send_json_error( array( 'message' => 'İçe aktarma oturumu bulunamadı veya süresi doldu.' ) );
        }

        $total = count( $rows );
        $batch = array_slice( $rows, $offset, self::BATCH_SIZE );
        $created = 0;
        $skipped = 0;
        $failed = 0;
        $messages = array();

        foreach ( $batch as $row ) {
            $result = self::import_row( $row );
            if ( is_wp_error( $result ) ) {
                $failed++;
                $messages[] = 'Hata: ' . $row['title'] . ' — ' . $result->get_error_message();
            } elseif ( 'skipped' === $result ) {
                $skipped++;
            } else {
                $created++;
            }
        }

        $next_offset = min( $total, $offset + count( $batch ) );
        $done = $next_offset >= $total;
        if ( $done ) {
            delete_transient( $key );
        } else {
            set_transient( $key, $rows, HOUR_IN_SECONDS );
        }

        wp_send_json_success( array(
            'created'     => $created,
            'skipped'     => $skipped,
            'failed'      => $failed,
            'messages'    => array_slice( $messages, 0, 20 ),
            'next_offset' => $next_offset,
            'done'        => $done,
        ) );
    }

    private static function require_admin_request() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function parse_csv( $path ) {
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'csv_open_failed', 'CSV dosyası açılamadı.' );
        }

        $first_line = fgets( $handle );
        if ( false === $first_line ) {
            fclose( $handle );
            return new WP_Error( 'csv_empty', 'CSV dosyası boş.' );
        }

        $delimiter = self::detect_delimiter( $first_line );
        rewind( $handle );
        $rows = array();
        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            $rows[] = array_map( static function( $value ) {
                return is_string( $value ) ? trim( $value ) : $value;
            }, $row );
        }
        fclose( $handle );

        return $rows;
    }

    private static function detect_delimiter( $line ) {
        $candidates = array( ',', ';', "\t" );
        $best = ',';
        $best_count = -1;
        foreach ( $candidates as $candidate ) {
            $count = substr_count( $line, $candidate );
            if ( $count > $best_count ) {
                $best = $candidate;
                $best_count = $count;
            }
        }
        return $best;
    }

    private static function parse_xlsx( $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'xlsx_zip_missing', 'Sunucuda ZIP desteği bulunmadığı için XLSX okunamıyor. Dosyayı CSV olarak kaydedip tekrar yükleyin.' );
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $path ) ) {
            return new WP_Error( 'xlsx_open_failed', 'XLSX dosyası açılamadı.' );
        }

        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        if ( false === $sheet_xml ) {
            $zip->close();
            return new WP_Error( 'xlsx_sheet_missing', 'XLSX içindeki ilk çalışma sayfası bulunamadı.' );
        }

        $shared_strings = array();
        $shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( false !== $shared_xml ) {
            $shared_strings = self::parse_shared_strings( $shared_xml );
        }
        $zip->close();

        if ( is_wp_error( $shared_strings ) ) {
            return $shared_strings;
        }

        $xml = self::load_xml( $sheet_xml );
        if ( is_wp_error( $xml ) ) {
            return $xml;
        }

        $rows = array();
        $row_nodes = $xml->xpath( '//*[local-name()="sheetData"]/*[local-name()="row"]' );
        if ( ! is_array( $row_nodes ) ) {
            return new WP_Error( 'xlsx_rows_missing', 'XLSX satırları okunamadı.' );
        }

        foreach ( $row_nodes as $row_node ) {
            $values = array();
            $cells = $row_node->xpath( './*[local-name()="c"]' );
            if ( ! is_array( $cells ) ) {
                continue;
            }

            foreach ( $cells as $cell ) {
                $attributes = $cell->attributes();
                $reference = isset( $attributes['r'] ) ? (string) $attributes['r'] : '';
                $cell_type = isset( $attributes['t'] ) ? (string) $attributes['t'] : '';
                $column = self::column_index_from_reference( $reference );
                if ( $column < 0 ) {
                    continue;
                }

                $value = '';
                if ( 'inlineStr' === $cell_type ) {
                    $text_nodes = $cell->xpath( './/*[local-name()="t"]' );
                    if ( is_array( $text_nodes ) ) {
                        foreach ( $text_nodes as $text_node ) {
                            $value .= (string) $text_node;
                        }
                    }
                } else {
                    $value_nodes = $cell->xpath( './*[local-name()="v"]' );
                    $raw = ( is_array( $value_nodes ) && isset( $value_nodes[0] ) ) ? (string) $value_nodes[0] : '';
                    if ( 's' === $cell_type && '' !== $raw ) {
                        $index = (int) $raw;
                        $value = isset( $shared_strings[ $index ] ) ? $shared_strings[ $index ] : '';
                    } else {
                        $value = $raw;
                    }
                }

                $values[ $column ] = trim( (string) $value );
            }

            if ( $values ) {
                $max_column = max( array_keys( $values ) );
                $dense = array_fill( 0, $max_column + 1, '' );
                foreach ( $values as $column => $value ) {
                    $dense[ $column ] = $value;
                }
                $rows[] = $dense;
            }
        }

        return $rows;
    }

    private static function parse_shared_strings( $xml_string ) {
        $xml = self::load_xml( $xml_string );
        if ( is_wp_error( $xml ) ) {
            return $xml;
        }

        $strings = array();
        $items = $xml->xpath( '//*[local-name()="si"]' );
        if ( ! is_array( $items ) ) {
            return $strings;
        }

        foreach ( $items as $item ) {
            $text = '';
            $nodes = $item->xpath( './/*[local-name()="t"]' );
            if ( is_array( $nodes ) ) {
                foreach ( $nodes as $node ) {
                    $text .= (string) $node;
                }
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private static function load_xml( $xml_string ) {
        $previous = libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $xml_string, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( false === $xml ) {
            return new WP_Error( 'xlsx_xml_invalid', 'XLSX XML verisi okunamadı.' );
        }
        return $xml;
    }

    private static function column_index_from_reference( $reference ) {
        if ( ! preg_match( '/^([A-Z]+)/i', $reference, $matches ) ) {
            return -1;
        }

        $letters = strtoupper( $matches[1] );
        $index = 0;
        $length = strlen( $letters );
        for ( $i = 0; $i < $length; $i++ ) {
            $index = ( $index * 26 ) + ( ord( $letters[ $i ] ) - 64 );
        }
        return $index - 1;
    }

    private static function normalize_rows( $rows ) {
        if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
            return array();
        }

        $headers = array_map( array( __CLASS__, 'normalize_header' ), array_values( $rows[0] ) );
        $title_index = self::find_header_index( $headers, array( 'etkinlik ismi', 'etkinlik adi', 'etkinlik adı', 'kaynak', 'baslik', 'başlık' ) );
        $url_index = self::find_header_index( $headers, array( 'web sitesi', 'website', 'url', 'kaynak url' ) );
        $type_index = self::find_header_index( $headers, array( 'turu', 'türü', 'tur', 'tür', 'etkinlik turu', 'etkinlik türü' ) );

        if ( null === $title_index ) {
            return array();
        }

        $normalized = array();
        foreach ( array_slice( $rows, 1 ) as $row ) {
            $title = isset( $row[ $title_index ] ) ? sanitize_text_field( (string) $row[ $title_index ] ) : '';
            if ( ! $title ) {
                continue;
            }

            $raw_url = null !== $url_index && isset( $row[ $url_index ] ) ? (string) $row[ $url_index ] : '';
            $source_type = null !== $type_index && isset( $row[ $type_index ] ) ? sanitize_text_field( (string) $row[ $type_index ] ) : '';
            $url = self::normalize_source_url( $raw_url );

            $normalized[] = array(
                'title'       => $title,
                'source_url'  => $url,
                'source_type' => $source_type,
            );
        }

        return $normalized;
    }

    private static function normalize_header( $value ) {
        $value = trim( (string) $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }

    private static function find_header_index( $headers, $aliases ) {
        foreach ( $aliases as $alias ) {
            $normalized_alias = self::normalize_header( $alias );
            $index = array_search( $normalized_alias, $headers, true );
            if ( false !== $index ) {
                return $index;
            }
        }
        return null;
    }

    private static function normalize_source_url( $url ) {
        $url = trim( (string) $url );
        if ( ! $url || in_array( $url, array( '-', '–', '—', '−' ), true ) ) {
            return '';
        }

        if ( ! preg_match( '#^https?://#i', $url ) ) {
            $url = 'https://' . ltrim( $url, '/' );
        }

        return esc_url_raw( $url, array( 'http', 'https' ) );
    }

    private static function normalize_url_key( $url ) {
        if ( ! $url ) {
            return '';
        }
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        return rtrim( $host . $path, '/' );
    }

    private static function import_key( $title, $url ) {
        $normalized_title = self::normalize_header( $title );
        return sha1( $normalized_title . '|' . self::normalize_url_key( $url ) );
    }

    private static function find_existing( $title, $url, $import_key ) {
        $by_key = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_source_import_key',
            'meta_value'     => $import_key,
            'no_found_rows'  => true,
        ) );
        if ( ! empty( $by_key[0] ) ) {
            return (int) $by_key[0];
        }

        $same_title = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'any',
            'posts_per_page' => 10,
            'fields'         => 'ids',
            'title'          => $title,
            'no_found_rows'  => true,
        ) );

        $url_key = self::normalize_url_key( $url );
        foreach ( $same_title as $post_id ) {
            $existing_url = (string) get_post_meta( $post_id, 'source_url', true );
            if ( self::normalize_url_key( $existing_url ) === $url_key ) {
                update_post_meta( $post_id, '_source_import_key', $import_key );
                return (int) $post_id;
            }
        }

        return 0;
    }

    private static function import_row( $row ) {
        $title = isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '';
        $url = isset( $row['source_url'] ) ? self::normalize_source_url( $row['source_url'] ) : '';
        $source_type = isset( $row['source_type'] ) ? sanitize_text_field( $row['source_type'] ) : '';
        if ( ! $title ) {
            return new WP_Error( 'missing_title', 'Etkinlik ismi boş.' );
        }

        $key = self::import_key( $title, $url );
        if ( self::find_existing( $title, $url, $key ) ) {
            return 'skipped';
        }

        $post_id = wp_insert_post( array(
            'post_type'   => 'event_source',
            'post_status' => 'publish',
            'post_title'  => $title,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, 'source_url', $url );
        update_post_meta( $post_id, 'source_type', $source_type );
        update_post_meta( $post_id, 'parser_type', 'auto' );
        update_post_meta( $post_id, 'source_status', $url ? 'active' : 'missing_url' );
        update_post_meta( $post_id, '_source_import_key', $key );
        update_post_meta( $post_id, '_source_imported_at', current_time( 'mysql' ) );

        return (int) $post_id;
    }

    private static function transient_key( $user_id, $token ) {
        return 'sektorel_src_imp_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
