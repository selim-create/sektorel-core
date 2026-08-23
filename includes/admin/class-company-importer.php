<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin-side bulk company importer.
 *
 * The browser parses the CSV and sends normalized row objects in small batches.
 * This keeps large imports away from PHP upload/time limits and lets admins run
 * a read-only analysis before writing anything.
 */
class Sektorel_Company_Importer {

    const AJAX_ACTION = 'sektorel_company_import_batch';
    const NONCE_ACTION = 'sektorel_company_import';
    const BATCH_SIZE = 40;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_import_batch' ) );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=company',
            'Firma İçe Aktar',
            'İçe Aktar',
            'manage_options',
            'sektorel-company-import',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'sektorel-core' ) );
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <div class="wrap sektorel-company-importer">
            <h1>Firma İçe Aktar</h1>
            <p>
                Master firma listesini önce <strong>CSV UTF-8</strong> olarak dışa aktarın ve burada yükleyin.
                İşlem tarayıcıdan <?php echo (int) self::BATCH_SIZE; ?> kayıtlık paketlerle ilerler; büyük dosyalarda PHP zaman aşımına takılmaz.
            </p>

            <div class="notice notice-info inline" style="max-width:1000px;">
                <p><strong>Güvenli import kuralları</strong></p>
                <ul style="list-style:disc;margin-left:20px;">
                    <li>Eşleşme sırası: website domaini → e-posta → benzersiz birebir firma adı.</li>
                    <li>Mevcut dolu değerler otomatik olarak ezilmez. Farklı değerler conflict kaydı olarak tutulur.</li>
                    <li>Kaynak kategorileri otomatik olarak Sektör taxonomy'sine atanmaz.</li>
                    <li>Lokasyon taxonomy'sine otomatik atama yapılmaz; ham ülke/adres bilgisi korunur.</li>
                    <li>Logo, Media Library'de Excel'deki <code>logo_file</code> ile birebir dosya adına göre aranır. Birebir bulunamazsa yalnızca tek bir stem adayı varsa kullanılır.</li>
                </ul>
            </div>

            <div class="card" style="max-width:1000px;padding:22px;margin-top:18px;">
                <h2 style="margin-top:0;">1. Dosya</h2>
                <input type="file" id="sektorel-company-file" accept=".csv,text/csv" />
                <p class="description">
                    Zorunlu kolon: <code>company_name</code>. Desteklenen master kolonları: slug, website, email, phone, country, address,
                    about, brand, representative, categories, products, brands, source_sites, source_detail_urls, source_count, logo_file.
                </p>

                <div id="sektorel-company-file-summary" style="display:none;margin-top:14px;padding:12px;background:#f6f7f7;border:1px solid #dcdcde;"></div>

                <hr style="margin:22px 0;">

                <h2>2. Import ayarları</h2>
                <label for="sektorel-company-status"><strong>Yeni firmaların durumu:</strong></label>
                <select id="sektorel-company-status" style="margin-left:8px;">
                    <option value="draft" selected>Taslak — kontrol sonrası yayınla</option>
                    <option value="publish">Doğrudan yayınla</option>
                </select>

                <p style="margin-top:18px;">
                    <button type="button" id="sektorel-company-analyze" class="button button-secondary button-large" disabled>Ön Analiz</button>
                    <button type="button" id="sektorel-company-import" class="button button-primary button-large" disabled style="margin-left:8px;">İçe Aktarmayı Başlat</button>
                    <button type="button" id="sektorel-company-stop" class="button button-secondary" style="display:none;margin-left:8px;">Durdur</button>
                </p>
            </div>

            <div id="sektorel-company-progress" class="card" style="display:none;max-width:1000px;padding:22px;margin-top:18px;">
                <h2 id="sektorel-company-mode-title" style="margin-top:0;">İşleniyor</h2>
                <div style="height:24px;background:#e2e4e7;border-radius:4px;overflow:hidden;">
                    <div id="sektorel-company-progress-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s;"></div>
                </div>
                <p><strong id="sektorel-company-progress-count">0 / 0</strong> — <span id="sektorel-company-progress-percent">%0</span></p>

                <div id="sektorel-company-stats" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:10px;margin-top:16px;"></div>
                <div id="sektorel-company-log" style="margin-top:16px;max-height:240px;overflow:auto;background:#1d2327;color:#dcdcde;padding:12px;font:12px/1.55 monospace;border-radius:4px;"></div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            const ajaxAction = <?php echo wp_json_encode( self::AJAX_ACTION ); ?>;
            const nonce = <?php echo wp_json_encode( $nonce ); ?>;
            const batchSize = <?php echo (int) self::BATCH_SIZE; ?>;
            let rows = [];
            let running = false;
            let currentIndex = 0;
            let currentMode = 'analyze';
            let stats = {};

            const knownAliases = {
                company_name: ['company_name', 'name', 'title'],
                slug: ['slug'],
                website: ['website', 'web_site'],
                email: ['email'],
                phone: ['phone', 'telefon'],
                country: ['country'],
                address: ['address'],
                about: ['about', 'description', 'short_about', 'tab_about'],
                brand: ['brand'],
                representative: ['representative', 'yetkili_kisi'],
                categories: ['categories', 'categories_json', 'product_groups_json'],
                products: ['products', 'tab_products'],
                brands: ['brands', 'brands_text', 'tab_brands'],
                source_sites: ['source_sites', 'source_site'],
                source_detail_urls: ['source_detail_urls', 'detail_url'],
                source_count: ['source_count'],
                logo_file: ['logo_file']
            };

            function normalizeHeader(value) {
                return String(value || '')
                    .replace(/^\uFEFF/, '')
                    .trim()
                    .toLowerCase()
                    .replace(/\s+/g, '_');
            }

            function detectDelimiter(text) {
                const sample = text.slice(0, 8000);
                let inQuotes = false;
                const counts = {',': 0, ';': 0, '\t': 0};
                for (let i = 0; i < sample.length; i++) {
                    const ch = sample[i];
                    if (ch === '"') {
                        if (inQuotes && sample[i + 1] === '"') { i++; continue; }
                        inQuotes = !inQuotes;
                    }
                    if (!inQuotes && ch === '\n') break;
                    if (!inQuotes && Object.prototype.hasOwnProperty.call(counts, ch)) counts[ch]++;
                }
                return Object.keys(counts).sort((a, b) => counts[b] - counts[a])[0] || ',';
            }

            function parseCsv(text) {
                const delimiter = detectDelimiter(text);
                const output = [];
                let row = [];
                let field = '';
                let inQuotes = false;

                for (let i = 0; i < text.length; i++) {
                    const ch = text[i];
                    if (ch === '"') {
                        if (inQuotes && text[i + 1] === '"') {
                            field += '"';
                            i++;
                        } else {
                            inQuotes = !inQuotes;
                        }
                    } else if (ch === delimiter && !inQuotes) {
                        row.push(field);
                        field = '';
                    } else if ((ch === '\n' || ch === '\r') && !inQuotes) {
                        if (ch === '\r' && text[i + 1] === '\n') i++;
                        row.push(field);
                        field = '';
                        if (row.some(cell => String(cell).trim() !== '')) output.push(row);
                        row = [];
                    } else {
                        field += ch;
                    }
                }

                if (field !== '' || row.length) {
                    row.push(field);
                    if (row.some(cell => String(cell).trim() !== '')) output.push(row);
                }
                return { rows: output, delimiter };
            }

            function canonicalMap(headers) {
                const map = {};
                Object.keys(knownAliases).forEach(canonical => {
                    const index = headers.findIndex(header => knownAliases[canonical].includes(header));
                    if (index >= 0) map[canonical] = index;
                });
                return map;
            }

            function valueAt(sourceRow, index) {
                if (typeof index === 'undefined') return '';
                return String(sourceRow[index] == null ? '' : sourceRow[index]).trim();
            }

            function prepareRows(parsed) {
                if (!parsed.rows.length) throw new Error('CSV boş.');
                const headers = parsed.rows[0].map(normalizeHeader);
                const map = canonicalMap(headers);
                if (typeof map.company_name === 'undefined') {
                    throw new Error('company_name (veya name/title) kolonu bulunamadı.');
                }

                const prepared = [];
                parsed.rows.slice(1).forEach(sourceRow => {
                    const item = {};
                    Object.keys(knownAliases).forEach(key => item[key] = valueAt(sourceRow, map[key]));
                    if (item.company_name) prepared.push(item);
                });
                return { prepared, headers, map };
            }

            function resetStats() {
                stats = {
                    created: 0,
                    matched: 0,
                    enriched: 0,
                    unchanged: 0,
                    conflicts: 0,
                    logo_linked: 0,
                    logo_missing: 0,
                    errors: 0,
                    would_create: 0,
                    would_match: 0,
                    would_conflict: 0
                };
                renderStats();
            }

            function statLabel(key) {
                const labels = {
                    created: 'Yeni firma', matched: 'Mevcut eşleşme', enriched: 'Zenginleştirildi', unchanged: 'Değişiklik yok',
                    conflicts: 'Conflict', logo_linked: 'Logo bulundu', logo_missing: 'Logo yok', errors: 'Hata',
                    would_create: 'Oluşturulacak', would_match: 'Eşleşecek', would_conflict: 'Olası conflict'
                };
                return labels[key] || key;
            }

            function renderStats() {
                const visible = currentMode === 'analyze'
                    ? ['would_create', 'would_match', 'would_conflict', 'logo_linked', 'logo_missing', 'errors']
                    : ['created', 'matched', 'enriched', 'unchanged', 'conflicts', 'logo_linked', 'logo_missing', 'errors'];
                $('#sektorel-company-stats').html(visible.map(key =>
                    '<div style="padding:12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;">' +
                    '<div style="font-size:11px;color:#646970;text-transform:uppercase;">' + statLabel(key) + '</div>' +
                    '<strong style="font-size:22px;">' + (stats[key] || 0) + '</strong></div>'
                ).join(''));
            }

            function appendLog(message, type) {
                const colors = {error:'#ff8080', success:'#70e39b', warn:'#ffd166', info:'#9ecbff'};
                const color = colors[type] || '#dcdcde';
                const node = $('<div>').css('color', color).text('> ' + message);
                $('#sektorel-company-log').append(node);
                const el = document.getElementById('sektorel-company-log');
                el.scrollTop = el.scrollHeight;
            }

            $('#sektorel-company-file').on('change', function() {
                const file = this.files && this.files[0];
                rows = [];
                $('#sektorel-company-analyze, #sektorel-company-import').prop('disabled', true);
                $('#sektorel-company-file-summary').hide().empty();
                if (!file) return;

                const reader = new FileReader();
                reader.onload = function(event) {
                    try {
                        const parsed = parseCsv(event.target.result || '');
                        const result = prepareRows(parsed);
                        rows = result.prepared;
                        const withLogo = rows.filter(row => row.logo_file).length;
                        $('#sektorel-company-file-summary').html(
                            '<strong>' + rows.length.toLocaleString('tr-TR') + ' firma kaydı okundu.</strong><br>' +
                            'Logo dosya adı bulunan kayıt: ' + withLogo.toLocaleString('tr-TR') + '<br>' +
                            'Ayraç: <code>' + (parsed.delimiter === '\t' ? 'TAB' : parsed.delimiter) + '</code>'
                        ).show();
                        $('#sektorel-company-analyze, #sektorel-company-import').prop('disabled', !rows.length);
                    } catch (error) {
                        $('#sektorel-company-file-summary').html('<strong style="color:#b32d2e;">' + $('<div>').text(error.message).html() + '</strong>').show();
                    }
                };
                reader.readAsText(file, 'UTF-8');
            });

            $('#sektorel-company-analyze').on('click', function() { start('analyze'); });
            $('#sektorel-company-import').on('click', function() {
                if (!window.confirm(rows.length.toLocaleString('tr-TR') + ' kayıt için gerçek import başlatılsın mı? Mevcut dolu alanlar ezilmeyecek.')) return;
                start('import');
            });
            $('#sektorel-company-stop').on('click', function() {
                running = false;
                $(this).hide();
                appendLog('İşlem kullanıcı tarafından durduruldu.', 'warn');
                $('#sektorel-company-analyze, #sektorel-company-import').prop('disabled', false);
            });

            function start(mode) {
                if (!rows.length || running) return;
                currentMode = mode;
                currentIndex = 0;
                running = true;
                resetStats();
                $('#sektorel-company-progress').show();
                $('#sektorel-company-log').empty();
                $('#sektorel-company-progress-bar').css({width:'0%', background:'#2271b1'});
                $('#sektorel-company-mode-title').text(mode === 'analyze' ? 'Ön analiz yapılıyor' : 'Firmalar içe aktarılıyor');
                $('#sektorel-company-analyze, #sektorel-company-import').prop('disabled', true);
                $('#sektorel-company-stop').show();
                appendLog((mode === 'analyze' ? 'Ön analiz' : 'Gerçek import') + ' başladı. Toplam: ' + rows.length, 'info');
                processNext();
            }

            function processNext() {
                if (!running) return;
                if (currentIndex >= rows.length) {
                    finish();
                    return;
                }

                const chunk = rows.slice(currentIndex, currentIndex + batchSize);
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: ajaxAction,
                        nonce: nonce,
                        mode: currentMode,
                        post_status: $('#sektorel-company-status').val(),
                        rows: JSON.stringify(chunk)
                    }
                }).done(function(response) {
                    if (!response || !response.success) {
                        stats.errors += chunk.length;
                        appendLog('Batch hatası: ' + ((response && response.data && response.data.message) || response.data || 'Bilinmeyen hata'), 'error');
                    } else {
                        const data = response.data || {};
                        Object.keys(data.stats || {}).forEach(key => stats[key] = (stats[key] || 0) + Number(data.stats[key] || 0));
                        (data.messages || []).forEach(item => appendLog(item.message, item.type));
                    }
                    currentIndex += chunk.length;
                    updateProgress();
                    renderStats();
                    processNext();
                }).fail(function(xhr) {
                    appendLog('Sunucu isteği başarısız oldu. Aynı batch 2 saniye sonra yeniden denenecek.', 'error');
                    setTimeout(processNext, 2000);
                });
            }

            function updateProgress() {
                const percent = rows.length ? Math.min(100, Math.round((currentIndex / rows.length) * 100)) : 0;
                $('#sektorel-company-progress-bar').css('width', percent + '%');
                $('#sektorel-company-progress-count').text(currentIndex.toLocaleString('tr-TR') + ' / ' + rows.length.toLocaleString('tr-TR'));
                $('#sektorel-company-progress-percent').text('%' + percent);
            }

            function finish() {
                running = false;
                updateProgress();
                $('#sektorel-company-progress-bar').css({width:'100%', background:'#00a32a'});
                $('#sektorel-company-stop').hide();
                $('#sektorel-company-analyze, #sektorel-company-import').prop('disabled', false);
                appendLog(currentMode === 'analyze' ? 'Ön analiz tamamlandı. Henüz hiçbir kayıt değiştirilmedi.' : 'İçe aktarma tamamlandı.', 'success');
            }
        });
        </script>
        <?php
    }

    public static function ajax_import_batch() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $mode = isset( $_POST['mode'] ) && 'import' === sanitize_key( wp_unslash( $_POST['mode'] ) ) ? 'import' : 'analyze';
        $post_status = isset( $_POST['post_status'] ) && 'publish' === sanitize_key( wp_unslash( $_POST['post_status'] ) ) ? 'publish' : 'draft';
        $raw_rows = isset( $_POST['rows'] ) ? wp_unslash( $_POST['rows'] ) : '';
        $rows = json_decode( $raw_rows, true );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            wp_send_json_error( array( 'message' => 'İşlenecek kayıt bulunamadı.' ), 400 );
        }

        if ( count( $rows ) > self::BATCH_SIZE + 5 ) {
            wp_send_json_error( array( 'message' => 'Batch boyutu izin verilen sınırı aşıyor.' ), 400 );
        }

        $stats = self::empty_stats();
        $messages = array();

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                ++$stats['errors'];
                continue;
            }

            $result = self::process_row( $row, 'analyze' === $mode, $post_status );
            foreach ( $result['stats'] as $key => $value ) {
                if ( isset( $stats[ $key ] ) ) {
                    $stats[ $key ] += (int) $value;
                }
            }

            if ( ! empty( $result['message'] ) && count( $messages ) < 8 ) {
                $messages[] = array(
                    'message' => $result['message'],
                    'type'    => $result['type'] ?? 'info',
                );
            }
        }

        wp_send_json_success( array(
            'stats'    => $stats,
            'messages' => $messages,
        ) );
    }

    private static function empty_stats() {
        return array(
            'created'        => 0,
            'matched'        => 0,
            'enriched'       => 0,
            'unchanged'      => 0,
            'conflicts'      => 0,
            'logo_linked'    => 0,
            'logo_missing'   => 0,
            'errors'         => 0,
            'would_create'   => 0,
            'would_match'    => 0,
            'would_conflict' => 0,
        );
    }

    private static function process_row( $raw_row, $dry_run, $post_status ) {
        $row = self::sanitize_row( $raw_row );
        $stats = self::empty_stats();
        $name = $row['company_name'];

        if ( '' === $name ) {
            ++$stats['errors'];
            return array( 'stats' => $stats, 'message' => 'Firma adı boş olan satır atlandı.', 'type' => 'error' );
        }

        $match = self::find_existing_company( $row );
        $company_id = $match['id'];
        $has_conflict = false;
        $would_enrich = false;

        if ( $company_id ) {
            $comparison = self::compare_existing( $company_id, $row );
            $has_conflict = ! empty( $comparison['conflicts'] );
            $would_enrich = ! empty( $comparison['fillable'] );

            if ( $dry_run ) {
                ++$stats['would_match'];
                if ( $has_conflict ) {
                    ++$stats['would_conflict'];
                }
                self::count_logo_status( $row, $stats );
                return array(
                    'stats'   => $stats,
                    'message' => sprintf( '%s → mevcut #%d (%s)%s', $name, $company_id, $match['method'], $has_conflict ? ' / conflict' : '' ),
                    'type'    => $has_conflict ? 'warn' : 'info',
                );
            }

            ++$stats['matched'];
            $changed = self::enrich_company( $company_id, $row, $comparison );
            if ( $changed ) {
                ++$stats['enriched'];
            } else {
                ++$stats['unchanged'];
            }
            if ( $has_conflict ) {
                ++$stats['conflicts'];
                self::store_conflicts( $company_id, $comparison['conflicts'], $row );
            }
        } else {
            if ( $dry_run ) {
                ++$stats['would_create'];
                self::count_logo_status( $row, $stats );
                return array(
                    'stats'   => $stats,
                    'message' => $name . ' → yeni firma olarak oluşturulacak',
                    'type'    => 'info',
                );
            }

            $company_id = self::create_company( $row, $post_status );
            if ( is_wp_error( $company_id ) ) {
                ++$stats['errors'];
                return array(
                    'stats'   => $stats,
                    'message' => $name . ' oluşturulamadı: ' . $company_id->get_error_message(),
                    'type'    => 'error',
                );
            }
            ++$stats['created'];
        }

        self::save_provenance( $company_id, $row, $match['method'] ?? 'new' );
        $logo_result = self::link_logo( $company_id, $row['logo_file'] );
        if ( $logo_result['found'] ) {
            ++$stats['logo_linked'];
        } elseif ( '' !== $row['logo_file'] ) {
            ++$stats['logo_missing'];
        }

        return array(
            'stats'   => $stats,
            'message' => sprintf( '%s → #%d%s', $name, $company_id, $logo_result['found'] ? ' / logo bağlı' : '' ),
            'type'    => $has_conflict ? 'warn' : 'success',
        );
    }

    private static function sanitize_row( $row ) {
        $text_fields = array( 'company_name', 'slug', 'phone', 'country', 'brand', 'representative', 'source_count', 'logo_file' );
        $textarea_fields = array( 'address', 'categories', 'products', 'brands', 'source_sites', 'source_detail_urls' );
        $clean = array();

        foreach ( $text_fields as $field ) {
            $clean[ $field ] = isset( $row[ $field ] ) ? sanitize_text_field( $row[ $field ] ) : '';
        }
        foreach ( $textarea_fields as $field ) {
            $clean[ $field ] = isset( $row[ $field ] ) ? sanitize_textarea_field( $row[ $field ] ) : '';
        }

        $clean['about'] = isset( $row['about'] ) ? wp_kses_post( $row['about'] ) : '';
        $clean['email'] = isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '';
        $clean['website'] = isset( $row['website'] ) ? self::normalize_website( $row['website'] ) : '';
        $clean['slug'] = $clean['slug'] ? sanitize_title( $clean['slug'] ) : sanitize_title( $clean['company_name'] );
        $clean['logo_file'] = basename( $clean['logo_file'] );
        return $clean;
    }

    private static function normalize_website( $website ) {
        $website = trim( (string) $website );
        if ( '' === $website ) {
            return '';
        }
        if ( 0 !== stripos( $website, 'http://' ) && 0 !== stripos( $website, 'https://' ) ) {
            $website = 'https://' . ltrim( $website, '/' );
        }
        return esc_url_raw( $website, array( 'http', 'https' ) );
    }

    private static function website_domain( $website ) {
        $website = self::normalize_website( $website );
        if ( '' === $website ) {
            return '';
        }
        $host = strtolower( (string) wp_parse_url( $website, PHP_URL_HOST ) );
        $host = preg_replace( '/^www\./i', '', $host );
        return trim( $host );
    }

    private static function find_existing_company( $row ) {
        global $wpdb;

        $domain = self::website_domain( $row['website'] );
        if ( $domain ) {
            $ids = get_posts( array(
                'post_type'      => 'company',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => 2,
                'meta_key'       => '_sektorel_import_domain',
                'meta_value'     => $domain,
                'no_found_rows'  => true,
            ) );
            if ( 1 === count( $ids ) ) {
                return array( 'id' => (int) $ids[0], 'method' => 'domain' );
            }

            $like = '%' . $wpdb->esc_like( $domain ) . '%';
            $candidate_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = 'website'
                   AND pm.meta_value LIKE %s
                   AND p.post_type = 'company'
                   AND p.post_status NOT IN ('trash','auto-draft')
                 LIMIT 8",
                $like
            ) );
            $verified = array();
            foreach ( $candidate_ids as $candidate_id ) {
                if ( $domain === self::website_domain( get_post_meta( $candidate_id, 'website', true ) ) ) {
                    $verified[] = (int) $candidate_id;
                }
            }
            $verified = array_values( array_unique( $verified ) );
            if ( 1 === count( $verified ) ) {
                return array( 'id' => $verified[0], 'method' => 'domain' );
            }
        }

        if ( $row['email'] && is_email( $row['email'] ) ) {
            $ids = get_posts( array(
                'post_type'      => 'company',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => 2,
                'meta_key'       => 'email',
                'meta_value'     => $row['email'],
                'no_found_rows'  => true,
            ) );
            if ( 1 === count( $ids ) ) {
                return array( 'id' => (int) $ids[0], 'method' => 'email' );
            }
        }

        $name = trim( $row['company_name'] );
        if ( $name ) {
            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'company'
                   AND post_status NOT IN ('trash','auto-draft')
                   AND post_title = %s
                 LIMIT 2",
                $name
            ) );
            if ( 1 === count( $ids ) ) {
                return array( 'id' => (int) $ids[0], 'method' => 'name_exact' );
            }

            $official_ids = get_posts( array(
                'post_type'      => 'company',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => 2,
                'meta_key'       => 'official_name',
                'meta_value'     => $name,
                'no_found_rows'  => true,
            ) );
            if ( 1 === count( $official_ids ) ) {
                return array( 'id' => (int) $official_ids[0], 'method' => 'official_name' );
            }
        }

        return array( 'id' => 0, 'method' => 'new' );
    }

    private static function compare_existing( $company_id, $row ) {
        $fillable = array();
        $conflicts = array();

        $post = get_post( $company_id );
        $checks = array(
            'official_name' => array( (string) get_post_meta( $company_id, 'official_name', true ), $row['company_name'] ),
            'email'         => array( (string) get_post_meta( $company_id, 'email', true ), $row['email'] ),
            'phone'         => array( (string) get_post_meta( $company_id, 'phone', true ), $row['phone'] ),
            'website'       => array( (string) get_post_meta( $company_id, 'website', true ), $row['website'] ),
            'address'       => array( (string) get_post_meta( $company_id, 'address', true ), $row['address'] ),
            'post_content'  => array( $post ? (string) $post->post_content : '', $row['about'] ),
        );

        foreach ( $checks as $field => $pair ) {
            $existing = trim( wp_strip_all_tags( (string) $pair[0] ) );
            $incoming = trim( wp_strip_all_tags( (string) $pair[1] ) );
            if ( '' === $incoming ) {
                continue;
            }
            if ( '' === $existing ) {
                $fillable[ $field ] = $pair[1];
                continue;
            }

            $same = 'website' === $field
                ? self::website_domain( $existing ) === self::website_domain( $incoming )
                : self::normalized_compare_value( $existing ) === self::normalized_compare_value( $incoming );

            if ( ! $same ) {
                $conflicts[ $field ] = array( 'existing' => $pair[0], 'incoming' => $pair[1] );
            }
        }

        return array( 'fillable' => $fillable, 'conflicts' => $conflicts );
    }

    private static function normalized_compare_value( $value ) {
        $value = remove_accents( wp_strip_all_tags( (string) $value ) );
        $value = strtolower( trim( preg_replace( '/\s+/', ' ', $value ) ) );
        return $value;
    }

    private static function create_company( $row, $post_status ) {
        $post_id = wp_insert_post( array(
            'post_type'    => 'company',
            'post_status'  => $post_status,
            'post_title'   => $row['company_name'],
            'post_name'    => $row['slug'],
            'post_content' => $row['about'],
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( $post_id, 'official_name', $row['company_name'] );
        self::save_empty_meta_values( $post_id, $row );
        return (int) $post_id;
    }

    private static function enrich_company( $company_id, $row, $comparison ) {
        $changed = false;
        foreach ( $comparison['fillable'] as $field => $value ) {
            if ( 'post_content' === $field ) {
                $result = wp_update_post( array( 'ID' => $company_id, 'post_content' => wp_kses_post( $value ) ), true );
                if ( ! is_wp_error( $result ) ) {
                    $changed = true;
                }
            } else {
                update_post_meta( $company_id, $field, $value );
                $changed = true;
            }
        }

        if ( self::save_empty_meta_values( $company_id, $row ) ) {
            $changed = true;
        }
        return $changed;
    }

    private static function save_empty_meta_values( $company_id, $row ) {
        $changed = false;
        $map = array(
            'email'         => 'email',
            'phone'         => 'phone',
            'website'       => 'website',
            'address'       => 'address',
            'brand'         => '_sektorel_source_brand',
            'representative'=> '_sektorel_source_representative',
            'country'       => '_sektorel_source_country',
            'categories'    => '_sektorel_source_categories',
            'products'      => '_sektorel_source_products',
            'brands'        => '_sektorel_source_brands',
        );

        foreach ( $map as $row_key => $meta_key ) {
            if ( '' === $row[ $row_key ] ) {
                continue;
            }
            $existing = get_post_meta( $company_id, $meta_key, true );
            if ( '' === (string) $existing ) {
                update_post_meta( $company_id, $meta_key, $row[ $row_key ] );
                $changed = true;
            }
        }

        $domain = self::website_domain( $row['website'] );
        if ( $domain && ! get_post_meta( $company_id, '_sektorel_import_domain', true ) ) {
            update_post_meta( $company_id, '_sektorel_import_domain', $domain );
            $changed = true;
        }
        return $changed;
    }

    private static function store_conflicts( $company_id, $conflicts, $row ) {
        $stored = get_post_meta( $company_id, '_sektorel_import_conflicts', true );
        $stored = is_array( $stored ) ? $stored : array();
        foreach ( $conflicts as $field => $values ) {
            $stored[] = array(
                'field'       => sanitize_key( $field ),
                'existing'    => is_scalar( $values['existing'] ) ? (string) $values['existing'] : '',
                'incoming'    => is_scalar( $values['incoming'] ) ? (string) $values['incoming'] : '',
                'source_sites'=> $row['source_sites'],
                'created_at'  => current_time( 'mysql' ),
            );
        }
        if ( count( $stored ) > 100 ) {
            $stored = array_slice( $stored, -100 );
        }
        update_post_meta( $company_id, '_sektorel_import_conflicts', $stored );
    }

    private static function save_provenance( $company_id, $row, $match_method ) {
        $sites = self::merge_list_meta( get_post_meta( $company_id, '_sektorel_source_sites', true ), $row['source_sites'] );
        $urls = self::merge_list_meta( get_post_meta( $company_id, '_sektorel_source_urls', true ), $row['source_detail_urls'] );
        update_post_meta( $company_id, '_sektorel_source_sites', $sites );
        update_post_meta( $company_id, '_sektorel_source_urls', $urls );
        update_post_meta( $company_id, '_sektorel_import_match_method', sanitize_key( $match_method ) );
        update_post_meta( $company_id, '_sektorel_imported_at', current_time( 'mysql' ) );
        if ( '' !== $row['source_count'] ) {
            update_post_meta( $company_id, '_sektorel_source_count', absint( $row['source_count'] ) );
        }
        if ( '' !== $row['logo_file'] ) {
            update_post_meta( $company_id, '_sektorel_import_logo_file', $row['logo_file'] );
        }
    }

    private static function merge_list_meta( $existing, $incoming ) {
        $items = is_array( $existing ) ? $existing : self::split_list( (string) $existing );
        $items = array_merge( $items, self::split_list( (string) $incoming ) );
        $items = array_values( array_unique( array_filter( array_map( 'trim', $items ) ) ) );
        return array_slice( $items, 0, 100 );
    }

    private static function split_list( $value ) {
        if ( '' === trim( (string) $value ) ) {
            return array();
        }
        return preg_split( '/\s*\|\s*|\r\n|\r|\n/', (string) $value );
    }

    private static function count_logo_status( $row, &$stats ) {
        if ( '' === $row['logo_file'] ) {
            return;
        }
        $attachment = self::find_logo_attachment( $row['logo_file'] );
        if ( $attachment['id'] ) {
            ++$stats['logo_linked'];
        } else {
            ++$stats['logo_missing'];
        }
    }

    private static function link_logo( $company_id, $logo_file ) {
        if ( '' === $logo_file ) {
            return array( 'found' => false, 'id' => 0, 'method' => 'none' );
        }

        $attachment = self::find_logo_attachment( $logo_file );
        if ( ! $attachment['id'] ) {
            return array( 'found' => false, 'id' => 0, 'method' => 'missing' );
        }

        $url = wp_get_attachment_url( $attachment['id'] );
        if ( $url && ! get_post_meta( $company_id, 'logo_image', true ) ) {
            update_post_meta( $company_id, 'logo_image', esc_url_raw( $url ) );
        }
        if ( ! get_post_thumbnail_id( $company_id ) ) {
            set_post_thumbnail( $company_id, $attachment['id'] );
        }
        update_post_meta( $company_id, '_sektorel_import_logo_attachment_id', (int) $attachment['id'] );
        update_post_meta( $company_id, '_sektorel_import_logo_match', $attachment['method'] );

        return array( 'found' => true, 'id' => (int) $attachment['id'], 'method' => $attachment['method'] );
    }

    private static function find_logo_attachment( $logo_file ) {
        global $wpdb;
        $filename = basename( sanitize_file_name( $logo_file ) );
        if ( '' === $filename ) {
            return array( 'id' => 0, 'method' => 'missing' );
        }

        $exact_like = '%/' . $wpdb->esc_like( $filename );
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wp_attached_file'
               AND (pm.meta_value = %s OR pm.meta_value LIKE %s)
               AND p.post_type = 'attachment'
             LIMIT 2",
            $filename,
            $exact_like
        ) );
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        if ( 1 === count( $ids ) ) {
            return array( 'id' => $ids[0], 'method' => 'exact_filename' );
        }

        $stem = pathinfo( $filename, PATHINFO_FILENAME );
        if ( '' === $stem ) {
            return array( 'id' => 0, 'method' => 'missing' );
        }

        $stem_like = '%/' . $wpdb->esc_like( $stem ) . '.%';
        $stem_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wp_attached_file'
               AND pm.meta_value LIKE %s
               AND p.post_type = 'attachment'
             LIMIT 3",
            $stem_like
        ) );
        $stem_ids = array_values( array_unique( array_map( 'intval', $stem_ids ) ) );
        if ( 1 === count( $stem_ids ) ) {
            return array( 'id' => $stem_ids[0], 'method' => 'unique_stem' );
        }

        return array( 'id' => 0, 'method' => count( $stem_ids ) > 1 ? 'ambiguous' : 'missing' );
    }
}
