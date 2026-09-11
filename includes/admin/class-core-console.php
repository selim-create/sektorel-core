<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Premium operational console for Sektörel Core. */
class Sektorel_Core_Console {

    const MENU_SLUG = 'sektorel-core';
    const SETTINGS_SLUG = 'sektorel-core-settings';
    const QUEUE_SLUG = 'sektorel-core-queue';
    const SYSTEM_SLUG = 'sektorel-core-system';
    const SAVE_ACTION = 'sektorel_core_save_settings';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 3 );
        add_action( 'admin_menu', array( __CLASS__, 'hide_legacy_entries' ), 999 );
        add_action( 'admin_post_' . self::SAVE_ACTION, array( __CLASS__, 'save_settings' ) );
    }

    public static function register_menu() {
        add_menu_page(
            'Sektörel Core',
            'Sektörel Core',
            'manage_options',
            self::MENU_SLUG,
            array( __CLASS__, 'render_dashboard' ),
            'dashicons-chart-area',
            3
        );

        add_submenu_page( self::MENU_SLUG, 'Dashboard', 'Dashboard', 'manage_options', self::MENU_SLUG, array( __CLASS__, 'render_dashboard' ) );
        add_submenu_page( self::MENU_SLUG, 'Operasyon Kuyruğu', 'Kuyruk', 'manage_options', self::QUEUE_SLUG, array( __CLASS__, 'render_queue' ) );
        add_submenu_page( self::MENU_SLUG, 'AI & Maliyet', 'AI & Maliyet', 'manage_options', self::SETTINGS_SLUG, array( __CLASS__, 'render_settings' ) );
        add_submenu_page( self::MENU_SLUG, 'Sistem', 'Sistem', 'manage_options', self::SYSTEM_SLUG, array( __CLASS__, 'render_system' ) );

        // Existing operational surfaces are kept intact; the Core menu becomes
        // their single discoverable daily-work entry point.
        add_submenu_page( self::MENU_SLUG, 'İçerik Motoru', 'İçerik Motoru', 'manage_options', 'edit.php?page=sektorel-content-source-center' );
        add_submenu_page( self::MENU_SLUG, 'İçerik Kaynakları', 'İçerik Kaynakları', 'manage_options', 'edit.php?post_type=content_source' );
        add_submenu_page( self::MENU_SLUG, 'Etkinlik Motoru', 'Etkinlik Motoru', 'manage_options', 'edit.php?post_type=event&page=sektorel-source-center' );
        add_submenu_page( self::MENU_SLUG, 'Etkinlik Kaynakları', 'Etkinlik Kaynakları', 'manage_options', 'edit.php?post_type=event_source' );
        add_submenu_page( self::MENU_SLUG, 'Aday Etkinlikler', 'Aday Etkinlikler', 'manage_options', 'edit.php?post_type=event_candidate' );
        add_submenu_page( self::MENU_SLUG, 'Aday Firmalar', 'Aday Firmalar', 'manage_options', 'edit.php?post_type=company&page=sektorel-company-candidates' );
    }

    public static function hide_legacy_entries() {
        remove_submenu_page( 'edit.php', 'sektorel-content-source-center' );
        remove_submenu_page( 'edit.php', 'edit.php?post_type=content_source' );
        remove_submenu_page( 'edit.php?post_type=event', 'sektorel-source-center' );
        remove_submenu_page( 'edit.php?post_type=event', 'edit.php?post_type=event_source' );
        remove_submenu_page( 'edit.php?post_type=event', 'edit.php?post_type=event_candidate' );
        remove_submenu_page( 'edit.php?post_type=company', 'sektorel-company-candidates' );
    }

    public static function save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }
        check_admin_referer( self::SAVE_ACTION );

        Sektorel_Core_Settings::update_settings( $_POST );

        foreach ( array( 'openai_api_key', 'pexels_api_key', 'unsplash_access_key', 'unsplash_secret_key' ) as $key ) {
            $clear = ! empty( $_POST[ 'clear_' . $key ] );
            $value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
            $result = Sektorel_Core_Settings::update_secret( $key, $value, $clear );
            if ( is_wp_error( $result ) ) {
                wp_safe_redirect( add_query_arg( array( 'page' => self::SETTINGS_SLUG, 'core_error' => rawurlencode( $result->get_error_message() ) ), admin_url( 'admin.php' ) ) );
                exit;
            }
        }

        wp_safe_redirect( add_query_arg( array( 'page' => self::SETTINGS_SLUG, 'updated' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function render_dashboard() {
        self::guard();
        $content = class_exists( 'Sektorel_Content_Candidates' ) ? Sektorel_Content_Candidates::stats() : array();
        $company = class_exists( 'Sektorel_Company_Candidates' ) ? Sektorel_Company_Candidates::stats() : array();
        $event_counts = wp_count_posts( 'event_candidate' );
        $event_candidates = $event_counts ? array_sum( array_map( 'intval', (array) $event_counts ) ) : 0;
        $today = Sektorel_Core_Settings::usage_summary( 'day' );
        $month = Sektorel_Core_Settings::usage_summary( 'month' );
        $budget = Sektorel_Core_Settings::budget_status();
        $model = Sektorel_Core_Settings::effective_model();
        $catalog = Sektorel_Core_Settings::model_catalog();
        $model_label = isset( $catalog[ $model ] ) ? $catalog[ $model ]['label'] : $model;
        ?>
        <div class="wrap sc-core">
            <?php self::styles(); ?>
            <div class="sc-hero">
                <div><div class="sc-eyebrow">SEKTÖREL AJANDA · OPERATIONS</div><h1>Sektörel Core</h1><p>İçerik, etkinlik, firma ve AI operasyonlarının tek kontrol merkezi.</p></div>
                <div class="sc-version">Core <?php echo esc_html( self::version() ); ?></div>
            </div>

            <div class="sc-grid sc-grid-4">
                <?php self::metric( 'İçerik Ready', (int) ( $content['ready'] ?? 0 ), 'AI taslağı bekleyen güvenli candidate' ); ?>
                <?php self::metric( 'İçerik İşlendi', (int) ( $content['processed'] ?? 0 ), 'Draft üretilmiş candidate' ); ?>
                <?php self::metric( 'Aday Etkinlik', $event_candidates, 'Etkinlik inceleme havuzu' ); ?>
                <?php self::metric( 'Aday Firma', (int) ( $company['total'] ?? 0 ), 'Firma discovery havuzu' ); ?>
            </div>

            <div class="sc-grid sc-grid-2">
                <section class="sc-card sc-ai-card">
                    <div class="sc-card-head"><div><span class="sc-kicker">AI OPERATIONS</span><h2>Model & maliyet</h2></div><span class="sc-status <?php echo Sektorel_Core_Settings::secret( 'openai_api_key' ) ? 'ok' : 'warn'; ?>"><?php echo Sektorel_Core_Settings::secret( 'openai_api_key' ) ? 'API bağlı' : 'API anahtarı yok'; ?></span></div>
                    <div class="sc-model"><strong><?php echo esc_html( $model_label ); ?></strong><span>Aktif model</span></div>
                    <div class="sc-cost-row"><div><span>Bugün</span><strong>$<?php echo esc_html( number_format( $today['cost'], 4 ) ); ?></strong></div><div><span>Bu ay</span><strong>$<?php echo esc_html( number_format( $month['cost'], 4 ) ); ?></strong></div><div><span>Aylık limit</span><strong><?php echo $budget['budget'] > 0 ? '$' . esc_html( number_format( $budget['budget'], 2 ) ) : 'Limitsiz'; ?></strong></div></div>
                    <?php if ( $budget['budget'] > 0 ) : ?><div class="sc-progress"><div style="width:<?php echo esc_attr( min( 100, $budget['percent'] ) ); ?>%"></div></div><small>%<?php echo esc_html( number_format( $budget['percent'], 1 ) ); ?> kullanıldı</small><?php endif; ?>
                    <p class="sc-actions"><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>">AI & Maliyet Ayarları</a></p>
                </section>

                <section class="sc-card">
                    <div class="sc-card-head"><div><span class="sc-kicker">QUICK ACTIONS</span><h2>Operasyon</h2></div></div>
                    <div class="sc-action-list">
                        <?php self::action_link( 'İçerik motoru', 'Kaynak tara → triage → AI draft', admin_url( 'edit.php?page=sektorel-content-source-center' ) ); ?>
                        <?php self::action_link( 'Etkinlik motoru', 'Tüm etkinlik kaynaklarını tara', admin_url( 'edit.php?post_type=event&page=sektorel-source-center' ) ); ?>
                        <?php self::action_link( 'Kuyruk', 'Tüm candidate ve hata durumları', admin_url( 'admin.php?page=' . self::QUEUE_SLUG ) ); ?>
                        <?php self::action_link( 'Sistem', 'API bağlantıları ve ortam sağlığı', admin_url( 'admin.php?page=' . self::SYSTEM_SLUG ) ); ?>
                    </div>
                </section>
            </div>

            <div class="sc-grid sc-grid-3">
                <?php self::health_card( 'OpenAI', Sektorel_Core_Settings::secret( 'openai_api_key' ), 'Metin üretimi ve editoryal dönüşüm' ); ?>
                <?php self::health_card( 'Pexels', Sektorel_Core_Settings::secret( 'pexels_api_key' ), 'Stok görsel kaynağı' ); ?>
                <?php self::health_card( 'Unsplash', Sektorel_Core_Settings::secret( 'unsplash_access_key' ), 'Alternatif stok görsel kaynağı' ); ?>
            </div>
        </div>
        <?php
    }

    public static function render_queue() {
        self::guard();
        global $wpdb;
        $content = class_exists( 'Sektorel_Content_Candidates' ) ? Sektorel_Content_Candidates::stats() : array();
        $company = class_exists( 'Sektorel_Company_Candidates' ) ? Sektorel_Company_Candidates::stats() : array();
        $event_counts = wp_count_posts( 'event_candidate' );
        $rows = array();
        if ( class_exists( 'Sektorel_Content_Candidates' ) && Sektorel_Content_Candidates::maybe_install() ) {
            $table = Sektorel_Content_Candidates::table_name();
            $rows = $wpdb->get_results( "SELECT id, source_key, title, status, ai_status, draft_post_id, error_code, error_message, updated_at FROM {$table} ORDER BY updated_at DESC LIMIT 30", ARRAY_A );
        }
        ?>
        <div class="wrap sc-core"><?php self::styles(); ?>
            <div class="sc-page-head"><div><span class="sc-eyebrow">OPERATIONS</span><h1>Kuyruk</h1><p>İçerik, etkinlik ve firma candidate akışlarının merkezi görünümü.</p></div></div>
            <div class="sc-grid sc-grid-3">
                <?php self::metric( 'İçerik', (int) ( $content['total'] ?? 0 ), sprintf( '%d ready · %d review · %d hata', (int) ( $content['ready'] ?? 0 ), (int) ( $content['review'] ?? 0 ), (int) ( $content['error'] ?? 0 ) ) ); ?>
                <?php self::metric( 'Etkinlik', $event_counts ? array_sum( array_map( 'intval', (array) $event_counts ) ) : 0, 'Aday etkinlik kayıtları' ); ?>
                <?php self::metric( 'Firma', (int) ( $company['total'] ?? 0 ), sprintf( '%d yeni · %d eşleşen · %d review', (int) ( $company['new'] ?? 0 ), (int) ( $company['matched'] ?? 0 ), (int) ( $company['review'] ?? 0 ) ) ); ?>
            </div>
            <section class="sc-card"><div class="sc-card-head"><div><span class="sc-kicker">CONTENT QUEUE</span><h2>Son içerik candidate kayıtları</h2></div><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?page=sektorel-content-source-center' ) ); ?>">İçerik Motoru</a></div>
                <div class="sc-table-wrap"><table class="widefat striped"><thead><tr><th>ID</th><th>Kaynak</th><th>Başlık</th><th>Durum</th><th>AI</th><th>Draft</th><th>Hata</th><th>Güncelleme</th></tr></thead><tbody>
                <?php foreach ( $rows as $row ) : ?><tr><td>#<?php echo (int) $row['id']; ?></td><td><code><?php echo esc_html( $row['source_key'] ); ?></code></td><td><?php echo esc_html( $row['title'] ?: '—' ); ?></td><td><?php echo esc_html( $row['status'] ); ?></td><td><?php echo esc_html( $row['ai_status'] ); ?></td><td><?php echo $row['draft_post_id'] ? '<a href="' . esc_url( get_edit_post_link( (int) $row['draft_post_id'] ) ) . '">#' . (int) $row['draft_post_id'] . '</a>' : '—'; ?></td><td><?php echo esc_html( $row['error_code'] ?: '—' ); ?><?php if ( $row['error_message'] ) : ?><br><small><?php echo esc_html( $row['error_message'] ); ?></small><?php endif; ?></td><td><?php echo esc_html( $row['updated_at'] ); ?></td></tr><?php endforeach; ?>
                <?php if ( ! $rows ) : ?><tr><td colspan="8">Henüz kuyruk kaydı yok.</td></tr><?php endif; ?></tbody></table></div>
            </section>
        </div><?php
    }

    public static function render_settings() {
        self::guard();
        $settings = Sektorel_Core_Settings::settings();
        $catalog = Sektorel_Core_Settings::model_catalog();
        $model = Sektorel_Core_Settings::effective_model();
        $budget = Sektorel_Core_Settings::budget_status();
        $month = Sektorel_Core_Settings::usage_summary( 'month' );
        ?>
        <div class="wrap sc-core"><?php self::styles(); ?>
            <div class="sc-page-head"><div><span class="sc-eyebrow">CONFIGURATION</span><h1>AI & Maliyet</h1><p>Model seçimi, API anahtarları, günlük üretim limiti ve aylık harcama koruması.</p></div></div>
            <?php if ( ! empty( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Ayarlar kaydedildi.</p></div><?php endif; ?>
            <?php if ( ! empty( $_GET['core_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['core_error'] ) ); ?></p></div><?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>"><?php wp_nonce_field( self::SAVE_ACTION ); ?>
                <div class="sc-grid sc-grid-2">
                    <section class="sc-card">
                        <span class="sc-kicker">OPENAI</span><h2>Model seçimi</h2><p class="description">İçerik üretimi için varsayılan öneri: <strong>GPT-5.6 Terra</strong>. Sol kritik kalite, Luna ise yüksek hacim için.</p>
                        <div class="sc-model-options">
                        <?php foreach ( $catalog as $id => $info ) : ?>
                            <label class="sc-model-option <?php echo $info['recommended'] ? 'recommended' : ''; ?>">
                                <input type="radio" name="openai_model" value="<?php echo esc_attr( $id ); ?>" <?php checked( $model, $id ); ?> <?php disabled( defined( 'SEKTOREL_OPENAI_MODEL' ) ); ?>>
                                <span><strong><?php echo esc_html( $info['label'] ); ?></strong><?php if ( $info['recommended'] ) : ?><em>Önerilen</em><?php endif; ?><small><?php echo esc_html( $info['description'] ); ?></small><b>$<?php echo esc_html( number_format( $info['input'], 2 ) ); ?> input · $<?php echo esc_html( number_format( $info['output'], 2 ) ); ?> output / 1M token</b></span>
                            </label>
                        <?php endforeach; ?>
                        </div>
                        <?php if ( defined( 'SEKTOREL_OPENAI_MODEL' ) ) : ?><p class="sc-lock">Model <code>wp-config.php</code> tarafından kilitli: <strong><?php echo esc_html( $model ); ?></strong></p><?php endif; ?>
                        <p><a href="https://openai.com/api/pricing/" target="_blank" rel="noopener noreferrer">OpenAI güncel fiyatlandırması ↗</a> · <a href="https://platform.openai.com/docs/models" target="_blank" rel="noopener noreferrer">Model dokümanları ↗</a></p>
                    </section>
                    <section class="sc-card">
                        <span class="sc-kicker">COST GUARD</span><h2>Limitler</h2>
                        <label class="sc-field"><span>Günlük içerik AI limiti</span><input type="number" min="1" max="200" name="content_daily_limit" value="<?php echo esc_attr( Sektorel_Core_Settings::effective_daily_limit() ); ?>" <?php disabled( defined( 'SEKTOREL_CONTENT_AI_DAILY_LIMIT' ) ); ?>><small>Bir günde işlenebilecek maksimum content candidate.</small></label>
                        <label class="sc-field"><span>Aylık AI bütçe limiti (USD)</span><input type="number" min="0" step="0.01" name="monthly_budget_usd" value="<?php echo esc_attr( $settings['monthly_budget_usd'] ); ?>"><small>0 = maliyet guard kapalı. Limit dolduğunda yeni AI draft çağrıları engellenir.</small></label>
                        <label class="sc-field"><span>Uyarı eşiği (%)</span><input type="number" min="50" max="100" name="budget_warning_pct" value="<?php echo esc_attr( $settings['budget_warning_pct'] ); ?>"></label>
                        <div class="sc-cost-row"><div><span>Bu ay istek</span><strong><?php echo (int) $month['requests']; ?></strong></div><div><span>Tahmini maliyet</span><strong>$<?php echo esc_html( number_format( $month['cost'], 4 ) ); ?></strong></div><div><span>Kalan</span><strong><?php echo $budget['budget'] > 0 ? '$' . esc_html( number_format( $budget['remaining'], 2 ) ) : '∞'; ?></strong></div></div>
                    </section>
                </div>

                <section class="sc-card"><div class="sc-card-head"><div><span class="sc-kicker">CREDENTIALS</span><h2>API anahtarları</h2><p>Panelden kaydedilen anahtarlar WordPress salt anahtarlarından türetilmiş AES-256 şifreleme ile tutulur. <code>wp-config.php</code> tanımları her zaman önceliklidir.</p></div></div>
                    <div class="sc-grid sc-grid-3">
                        <?php self::secret_field( 'openai_api_key', 'OpenAI API Key', 'https://platform.openai.com/api-keys', 'API Keys → Create new secret key' ); ?>
                        <?php self::secret_field( 'pexels_api_key', 'Pexels API Key', 'https://www.pexels.com/api/', 'Pexels hesabı → API → Your API Key' ); ?>
                        <?php self::secret_field( 'unsplash_access_key', 'Unsplash Access Key', 'https://unsplash.com/developers', 'Developers → New Application → Access Key' ); ?>
                    </div>
                    <details style="margin-top:16px"><summary style="cursor:pointer;font-weight:700">Unsplash Secret Key (yalnız OAuth gerekirse)</summary><div style="max-width:520px;margin-top:12px"><?php self::secret_field( 'unsplash_secret_key', 'Unsplash Secret Key', 'https://unsplash.com/documentation', 'Uygulama credentials ekranı' ); ?></div></details>
                </section>

                <section class="sc-card"><span class="sc-kicker">MEDIA</span><h2>Görsel kaynağı önceliği</h2><select name="media_provider"><option value="pexels_first" <?php selected( $settings['media_provider'], 'pexels_first' ); ?>>Pexels → Unsplash fallback (önerilen)</option><option value="unsplash_first" <?php selected( $settings['media_provider'], 'unsplash_first' ); ?>>Unsplash → Pexels fallback</option><option value="pexels" <?php selected( $settings['media_provider'], 'pexels' ); ?>>Yalnız Pexels</option><option value="unsplash" <?php selected( $settings['media_provider'], 'unsplash' ); ?>>Yalnız Unsplash</option></select><p class="description">Bu ayar görsel otomasyon fazında kullanılacak; şu anda yalnız merkezi konfigürasyon olarak saklanır.</p></section>
                <p><button type="submit" class="button button-primary button-hero">Ayarları Kaydet</button></p>
            </form>
        </div><?php
    }

    public static function render_system() {
        self::guard();
        $checks = array(
            'PHP' => PHP_VERSION,
            'WordPress' => get_bloginfo( 'version' ),
            'Core' => self::version(),
            'OpenSSL' => function_exists( 'openssl_encrypt' ) ? 'Hazır' : 'Eksik',
            'DOMDocument' => class_exists( 'DOMDocument' ) ? 'Hazır' : 'Eksik',
            'WP-Cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'Devre dışı' : 'Aktif',
        );
        ?>
        <div class="wrap sc-core"><?php self::styles(); ?><div class="sc-page-head"><div><span class="sc-eyebrow">SYSTEM</span><h1>Sistem</h1><p>Core ortamı, entegrasyon sağlığı ve hızlı teşhis bağlantıları.</p></div></div>
        <div class="sc-grid sc-grid-3"><?php foreach ( $checks as $label => $value ) : ?><section class="sc-card"><span class="sc-kicker"><?php echo esc_html( $label ); ?></span><h2><?php echo esc_html( $value ); ?></h2></section><?php endforeach; ?></div>
        <section class="sc-card"><h2>Entegrasyon bağlantıları</h2><div class="sc-action-list"><?php self::action_link( 'OpenAI API keys', 'Secret key oluştur', 'https://platform.openai.com/api-keys', true ); ?><?php self::action_link( 'OpenAI pricing', 'Güncel model fiyatları', 'https://openai.com/api/pricing/', true ); ?><?php self::action_link( 'Pexels API', 'API key ve dokümantasyon', 'https://www.pexels.com/api/', true ); ?><?php self::action_link( 'Unsplash Developers', 'Application ve access key', 'https://unsplash.com/developers', true ); ?></div></section>
        </div><?php
    }

    private static function secret_field( $key, $label, $url, $hint ) {
        $source = Sektorel_Core_Settings::secret_source( $key );
        $configured = 'missing' !== $source;
        ?>
        <div class="sc-secret"><div class="sc-card-head"><strong><?php echo esc_html( $label ); ?></strong><span class="sc-status <?php echo $configured ? 'ok' : 'warn'; ?>"><?php echo $configured ? ( 'wp-config' === $source ? 'wp-config' : 'Kayıtlı' ) : 'Eksik'; ?></span></div>
        <?php if ( 'wp-config' === $source ) : ?><p class="description">Bu anahtar <code>wp-config.php</code> tarafından yönetiliyor; panel değeri kullanılmaz.</p><?php else : ?><input type="password" name="<?php echo esc_attr( $key ); ?>" value="" autocomplete="new-password" placeholder="<?php echo $configured ? '••••••••••••  (değiştirmek için yeni değer gir)' : 'Anahtarı yapıştır'; ?>"><label class="sc-clear"><input type="checkbox" name="clear_<?php echo esc_attr( $key ); ?>" value="1"> Kayıtlı anahtarı temizle</label><?php endif; ?>
        <p><small><?php echo esc_html( $hint ); ?></small><br><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener noreferrer">Anahtarı al / dokümantasyon ↗</a></p></div>
        <?php
    }

    private static function metric( $label, $value, $desc ) { ?><div class="sc-metric"><span><?php echo esc_html( $label ); ?></span><strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong><small><?php echo esc_html( $desc ); ?></small></div><?php }
    private static function health_card( $label, $value, $desc ) { ?><section class="sc-card sc-health"><div><span class="sc-dot <?php echo $value ? 'on' : ''; ?>"></span><strong><?php echo esc_html( $label ); ?></strong></div><p><?php echo esc_html( $desc ); ?></p><small><?php echo $value ? 'Bağlantı bilgisi hazır' : 'API anahtarı bekleniyor'; ?></small></section><?php }
    private static function action_link( $title, $desc, $url, $external = false ) { ?><a class="sc-action" href="<?php echo esc_url( $url ); ?>" <?php echo $external ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>><span><strong><?php echo esc_html( $title ); ?></strong><small><?php echo esc_html( $desc ); ?></small></span><b>→</b></a><?php }
    private static function guard() { if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetkisiz işlem.' ); }
    private static function version() { $data = get_file_data( SEKTOREL_CORE_PATH . 'sektorel-core.php', array( 'Version' => 'Version' ) ); return $data['Version'] ?: '—'; }

    private static function styles() {
        ?><style>
        .sc-core{--ink:#14213d;--muted:#667085;--line:#e6e9ef;--paper:#fff;--wash:#f6f8fb;--accent:#2357ff;max-width:1440px}.sc-core *{box-sizing:border-box}.sc-hero,.sc-page-head{display:flex;justify-content:space-between;align-items:flex-start;padding:30px 32px;margin:18px 0 24px;border-radius:22px;background:linear-gradient(135deg,#111827 0%,#1f2c4c 58%,#2357ff 150%);color:#fff;box-shadow:0 18px 50px rgba(17,24,39,.12)}.sc-hero h1,.sc-page-head h1{font-size:34px;line-height:1.05;margin:5px 0 8px;color:#fff}.sc-hero p,.sc-page-head p{margin:0;color:#d6dbea;font-size:15px}.sc-eyebrow,.sc-kicker{font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.sc-eyebrow{color:#9db5ff}.sc-kicker{color:#64748b}.sc-version{border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.08);border-radius:999px;padding:8px 13px;font-weight:700}.sc-grid{display:grid;gap:16px;margin:16px 0}.sc-grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}.sc-grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}.sc-grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sc-card,.sc-metric{background:var(--paper);border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 7px 24px rgba(15,23,42,.035)}.sc-metric span{display:block;color:var(--muted);font-weight:700;font-size:12px}.sc-metric strong{display:block;font-size:32px;line-height:1;margin:9px 0;color:var(--ink)}.sc-metric small{color:#8791a5}.sc-card h2{margin:5px 0 12px;color:var(--ink);font-size:20px}.sc-card-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start}.sc-status{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:800;background:#f2f4f7;color:#667085}.sc-status.ok{background:#eaf8ef;color:#18753a}.sc-status.warn{background:#fff3db;color:#9a6200}.sc-model{padding:18px;border-radius:14px;background:var(--wash);margin:12px 0 16px}.sc-model strong{font-size:22px;color:var(--ink)}.sc-model span{display:block;color:var(--muted);margin-top:3px}.sc-cost-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:16px 0}.sc-cost-row>div{padding:12px;border-radius:12px;background:#f8fafc}.sc-cost-row span{display:block;font-size:11px;color:var(--muted);margin-bottom:4px}.sc-cost-row strong{font-size:17px;color:var(--ink)}.sc-progress{height:8px;border-radius:999px;overflow:hidden;background:#edf0f5}.sc-progress>div{height:100%;background:var(--accent);border-radius:999px}.sc-actions{margin:18px 0 0}.sc-action-list{display:grid;gap:8px}.sc-action{display:flex;justify-content:space-between;align-items:center;text-decoration:none;padding:13px 14px;border:1px solid var(--line);border-radius:12px;color:var(--ink);transition:.15s}.sc-action:hover{border-color:#9db5ff;background:#f8faff;color:var(--accent)}.sc-action strong,.sc-action small{display:block}.sc-action small{font-weight:400;color:var(--muted);margin-top:3px}.sc-health .sc-dot{display:inline-block;width:9px;height:9px;border-radius:50%;background:#d0d5dd;margin-right:8px}.sc-health .sc-dot.on{background:#12b76a}.sc-health p{color:var(--muted)}.sc-field{display:block;margin:16px 0}.sc-field>span{display:block;font-weight:700;margin-bottom:6px}.sc-field input,.sc-secret input[type=password],.sc-card select{width:100%;max-width:520px;border-radius:10px;padding:8px 10px}.sc-field small{display:block;color:var(--muted);margin-top:5px}.sc-model-options{display:grid;gap:10px}.sc-model-option{display:flex;gap:10px;padding:14px;border:1px solid var(--line);border-radius:13px}.sc-model-option.recommended{border-color:#9db5ff;background:#f8faff}.sc-model-option span{display:block;flex:1}.sc-model-option strong,.sc-model-option small,.sc-model-option b{display:block}.sc-model-option em{display:inline-block;margin-left:8px;font-size:10px;font-style:normal;background:#e6edff;color:#2357ff;padding:2px 6px;border-radius:999px}.sc-model-option small{color:var(--muted);margin:4px 0}.sc-model-option b{font-size:11px;color:#475467}.sc-secret{padding:16px;border:1px solid var(--line);border-radius:14px;background:#fafbfc}.sc-clear{display:block;margin-top:8px;color:var(--muted);font-size:12px}.sc-lock{padding:10px 12px;border-radius:10px;background:#fff3db;color:#8a5a00}.sc-table-wrap{overflow:auto}@media(max-width:1100px){.sc-grid-4,.sc-grid-3{grid-template-columns:repeat(2,1fr)}}@media(max-width:782px){.sc-grid-4,.sc-grid-3,.sc-grid-2{grid-template-columns:1fr}.sc-hero,.sc-page-head{padding:22px}.sc-hero h1,.sc-page-head h1{font-size:28px}.sc-cost-row{grid-template-columns:1fr}}
        </style><?php
    }
}
