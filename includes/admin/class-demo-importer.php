<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Demo_Importer {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ) );
        
        // AJAX Hook'u (Giriş yapmış adminler için)
        add_action( 'wp_ajax_sektorel_import_batch', array( __CLASS__, 'ajax_import_batch' ) );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'options-general.php',
            'Sektörel Demo Veri',
            'Sektörel Demo',
            'manage_options',
            'sektorel-demo-import',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        ?>
        <div class="wrap">
            <h1>🚀 Sektörel Ajanda - Big Data Yükleyici (v9.0)</h1>
            <p>Bu araç, on binlerce satırlık CSV dosyalarını tarayıcıda işleyip sunucuya parça parça gönderir. Zaman aşımı sorunu yaşanmaz.</p>
            
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; border-left: 4px solid #ea580c;">
                <h3>🌍 Lokasyon İçe Aktar (Akıllı CSV İşleyici)</h3>
                <p><strong>Lokasyon - Türkiye.csv</strong> (Ülke, Şehir, İlçe) veya <strong>Lokasyon - Global.csv</strong> (Ülke, Şehir, ascii, lat, lng) dosyasını seçin.</p>
                <div style="background:#fff; border:1px solid #ddd; padding:10px; margin-bottom:15px; font-size:12px;">
                    <strong>Nasıl Çalışır?</strong>
                    <ul style="list-style:disc; margin-left:20px;">
                        <li>Dosya sunucuya yüklenmez, tarayıcınızda okunur.</li>
                        <li>Veriler 50'şerli paketler halinde veritabanına işlenir.</li>
                        <li>Sistem otomatik olarak Ülke > Şehir > İlçe hiyerarşisini kurar.</li>
                        <li>"Türkiye" satırları tespit edilirse 3. sütun "İlçe" olarak işlenir.</li>
                        <li>Global satırlarda Lat/Lng verileri otomatik formatlanıp kaydedilir.</li>
                    </ul>
                </div>
                
                <div style="margin-top: 15px;">
                    <label style="display:block; margin-bottom:10px; font-weight:bold;">CSV Dosyası Seçin:</label>
                    <input type="file" id="csv_file" accept=".csv" style="padding: 10px; background: #f0f0f1; width: 100%;">
                </div>

                <!-- İlerleme Çubuğu -->
                <div id="progress_wrapper" style="display:none; margin-top: 20px;">
                    <div style="background: #e5e5e5; border-radius: 3px; height: 25px; width: 100%; overflow: hidden; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);">
                        <div id="progress_bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.2s; background-image: linear-gradient(45deg,rgba(255,255,255,.15) 25%,transparent 25%,transparent 50%,rgba(255,255,255,.15) 50%,rgba(255,255,255,.15) 75%,transparent 75%,transparent); background-size: 1rem 1rem;"></div>
                    </div>
                    <div style="display:flex; justify-content: space-between; margin-top:5px; font-size: 12px; font-weight: bold; color: #555;">
                        <span id="progress_percent">%0</span>
                        <span id="progress_count">0 / 0 Satır</span>
                        <span id="progress_status">Bekliyor...</span>
                    </div>
                </div>

                <!-- Hata / Log Alanı -->
                <div id="log_area" style="margin-top: 20px; max-height: 150px; overflow-y: auto; background: #23282d; color: #00f0ff; padding: 10px; border-radius: 4px; display:none; font-family: monospace; font-size: 11px;"></div>

                <p class="submit">
                    <button type="button" id="start_import" class="button button-primary button-hero">🚀 Yüklemeyi Başlat</button>
                    <button type="button" id="stop_import" class="button button-secondary" style="display:none; margin-left: 10px;">Durdur</button>
                </p>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var allRows = [];
            var totalRows = 0;
            var batchSize = 50; // Her seferde 50 kayıt (Sunucuyu yormamak için ideal)
            var currentIdx = 0;
            var isRunning = false;
            var errorCount = 0;

            // CSV Parse Helper (Basit virgül ayrıştırma, tırnak içini korur)
            function csvToArray(str, delimiter = ",") {
                // Başlık satırını atlamak için ilk satırı siliyoruz
                const rows = str.slice(str.indexOf("\n") + 1).split("\n");
                return rows.filter(row => row.trim().length > 0);
            }

            $('#start_import').on('click', function() {
                var fileInput = document.getElementById('csv_file');
                if (!fileInput.files.length) {
                    alert('Lütfen bir CSV dosyası seçin!');
                    return;
                }

                var file = fileInput.files[0];
                var reader = new FileReader();

                // UI Reset
                $(this).prop('disabled', true);
                $('#stop_import').show();
                $('#progress_wrapper').show();
                $('#log_area').show().html('> Dosya okunuyor...<br>');
                $('#progress_bar').css('width', '0%').css('background', '#2271b1');
                
                reader.onload = function(e) {
                    var text = e.target.result;
                    allRows = csvToArray(text);
                    totalRows = allRows.length;
                    currentIdx = 0;
                    errorCount = 0;
                    isRunning = true;

                    $('#log_area').append('> Toplam ' + totalRows + ' satır veri okundu. İşlem başlıyor...<br>');
                    processNextBatch();
                };

                reader.readAsText(file);
            });

            $('#stop_import').on('click', function() {
                isRunning = false;
                $('#log_area').append('<span style="color:red">> İşlem kullanıcı tarafından durduruldu.</span><br>');
                $(this).hide();
                $('#start_import').prop('disabled', false).text('Kaldığı Yerden Devam Et');
            });

            function processNextBatch() {
                if (!isRunning) return;

                if (currentIdx >= totalRows) {
                    finishImport();
                    return;
                }

                // Batch oluştur
                var chunk = allRows.slice(currentIdx, currentIdx + batchSize);
                
                $('#progress_status').text('İşleniyor: ' + currentIdx + ' - ' + (currentIdx + chunk.length));

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'sektorel_import_batch',
                        rows: chunk,
                        nonce: '<?php echo wp_create_nonce("sektorel_batch_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            currentIdx += chunk.length;
                            var percent = Math.round((currentIdx / totalRows) * 100);
                            
                            // UI Güncelle
                            $('#progress_bar').css('width', percent + '%');
                            $('#progress_percent').text('%' + percent);
                            $('#progress_count').text(currentIdx + ' / ' + totalRows);
                            
                            // Scroll Log
                            var logDiv = document.getElementById("log_area");
                            logDiv.scrollTop = logDiv.scrollHeight;

                            // Devam et
                            processNextBatch();
                        } else {
                            errorCount++;
                            $('#log_area').append('<span style="color:red">> Hata: ' + response.data + '</span><br>');
                            // Hata olsa da devam etmeye çalış
                            currentIdx += chunk.length; 
                            processNextBatch();
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#log_area').append('<span style="color:red">> Sunucu Hatası (Timeout olabilir). 3sn bekleyip tekrar deneniyor...</span><br>');
                        setTimeout(processNextBatch, 3000);
                    }
                });
            }

            function finishImport() {
                $('#progress_bar').css('width', '100%').css('background', '#46b450');
                $('#progress_status').text('Tamamlandı!');
                $('#log_area').append('<br><strong style="color:#46b450">> ✅ İŞLEM BAŞARIYLA TAMAMLANDI!</strong>');
                $('#start_import').prop('disabled', false).text('Yeni Yükleme Yap');
                $('#stop_import').hide();
                isRunning = false;
            }
        });
        </script>
        <?php
    }

    /* -------------------------------------------------------------------------- */
    /* AJAX CALLBACK (BATCH PROCESSOR) - SUNUCU TARAFI                            */
    /* -------------------------------------------------------------------------- */
    public static function ajax_import_batch() {
        // Güvenlik Kontrolü
        check_ajax_referer('sektorel_batch_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Yetkisiz işlem.');
        }

        $rows = isset($_POST['rows']) ? $_POST['rows'] : [];
        if (empty($rows)) {
            wp_send_json_error('Veri boş.');
        }

        // Hiyerarşiyi optimize etmek için basit bir cache (Bu request süresince)
        // Not: wp_insert_term zaten var olanı kontrol eder ama bu cache DB sorgusunu azaltır.
        $parent_cache = []; 

        foreach ($rows as $line) {
            // CSV Satırını Parçala (Virgül ile, tırnakları dikkate alarak)
            $data = str_getcsv($line);
            
            // Veri Kontrolü
            // [0]: Ülke
            // [1]: Şehir
            // [2]: İlçe (Varsa) veya CityAscii
            // [3]: Lat (Global ise)
            // [4]: Lng (Global ise)
            
            if (empty($data[0])) continue;

            $country_name = trim($data[0]);
            
            // Eğer başlık satırı geldiyse atla
            if (strtolower($country_name) === 'ülke' || strtolower($country_name) === 'country' || strtolower($country_name) === 'ulke') continue;

            $city_name = isset($data[1]) ? trim($data[1]) : '';
            
            // Türkiye Dosyası mı Global mi?
            $is_turkey = (mb_strtolower($country_name) === 'türkiye' || mb_strtolower($country_name) === 'turkey');

            // --- 1. ÜLKE İŞLEMLERİ ---
            $country_id = self::get_term_id($country_name, 0); // Parent 0
            if ($country_id) {
                // Meta sadece yeni eklendiyse veya güncelleniyorsa (Her seferinde yapmak yük bindirmez çünkü update_term_meta değişiklik yoksa DB yazmaz)
                update_term_meta($country_id, 'location_type', 'country');
            } else {
                continue; // Ülke oluşturulamazsa diğerlerine bakma
            }

            // --- 2. ŞEHİR İŞLEMLERİ ---
            if (!empty($city_name)) {
                $city_id = self::get_term_id($city_name, $country_id);
                
                if ($city_id) {
                    update_term_meta($city_id, 'location_type', 'city');
                    
                    // Global Dosya Koordinatları
                    // Global dosyada [3] ve [4] genellikle lat/lng olur.
                    // Türkiye dosyasında [2] ilçedir.
                    if (!$is_turkey && isset($data[3]) && isset($data[4])) {
                        // Virgüllü formatı (34,567) noktaya (34.567) çevir
                        $lat = str_replace(',', '.', $data[3]);
                        $lng = str_replace(',', '.', $data[4]);
                        
                        // Sadece geçerli sayısal değerlerse kaydet
                        if (is_numeric($lat) && is_numeric($lng)) {
                            update_term_meta($city_id, 'map_lat', sanitize_text_field($lat));
                            update_term_meta($city_id, 'map_lng', sanitize_text_field($lng));
                        }
                    }

                    // --- 3. İLÇE İŞLEMLERİ (SADECE TÜRKİYE) ---
                    if ($is_turkey && isset($data[2])) {
                        $district_name = trim($data[2]);
                        if (!empty($district_name)) {
                            $dist_id = self::get_term_id($district_name, $city_id);
                            if ($dist_id) {
                                update_term_meta($dist_id, 'location_type', 'district');
                            }
                        }
                    }
                }
            }
        }

        wp_send_json_success(['count' => count($rows)]);
    }

    /**
     * Yardımcı Fonksiyon: Terim ID'sini bul veya oluştur.
     * Bu fonksiyon, WordPress'in term_exists fonksiyonunu kullanır.
     */
    private static function get_term_id($name, $parent_id = 0) {
        // İsim ve Parent ID ile kontrol et
        $term = term_exists($name, 'location', $parent_id);
        
        if ($term) {
            return is_array($term) ? $term['term_id'] : $term;
        }

        // Yoksa oluştur
        $inserted = wp_insert_term($name, 'location', ['parent' => $parent_id]);
        
        if (is_wp_error($inserted)) {
            return 0;
        }
        
        return $inserted['term_id'];
    }
}