<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-source-policy.php';

class Sektorel_Content_Source_Admin {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_content_source', array( __CLASS__, 'save_source' ), 10, 2 );
        add_filter( 'manage_content_source_posts_columns', array( __CLASS__, 'columns' ) );
        add_action( 'manage_content_source_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_content_source_details',
            'İçerik Kaynağı Ayarları',
            array( __CLASS__, 'render_meta_box' ),
            'content_source',
            'normal',
            'high'
        );

        add_meta_box(
            'sektorel_content_source_coverage',
            'Source Coverage Registry',
            array( __CLASS__, 'render_coverage_meta_box' ),
            'content_source',
            'normal',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'sektorel_content_source_save', 'sektorel_content_source_nonce' );

        $values = array(
            'source_key'          => get_post_meta( $post->ID, 'source_key', true ),
            'base_url'            => get_post_meta( $post->ID, 'base_url', true ),
            'feed_url'            => get_post_meta( $post->ID, 'feed_url', true ),
            'source_type'         => get_post_meta( $post->ID, 'source_type', true ),
            'adapter'             => get_post_meta( $post->ID, 'adapter', true ),
            'role'                => get_post_meta( $post->ID, 'role', true ),
            'trust_level'         => get_post_meta( $post->ID, 'trust_level', true ),
            'language'            => get_post_meta( $post->ID, 'language', true ),
            'category_slugs'      => get_post_meta( $post->ID, 'category_slugs', true ),
            'scan_interval'       => get_post_meta( $post->ID, 'scan_interval', true ),
            'enabled'             => get_post_meta( $post->ID, 'enabled', true ),
            'ai_enabled'          => get_post_meta( $post->ID, 'ai_enabled', true ),
            'max_items_per_scan'  => get_post_meta( $post->ID, 'max_items_per_scan', true ),
            'max_ai_items_daily'  => get_post_meta( $post->ID, 'max_ai_items_daily', true ),
            'last_scan'           => get_post_meta( $post->ID, 'last_scan', true ),
            'last_success'        => get_post_meta( $post->ID, 'last_success', true ),
            'last_error'          => get_post_meta( $post->ID, 'last_error', true ),
        );

        $values['source_type'] = $values['source_type'] ?: 'rss';
        $values['role'] = $values['role'] ?: 'official';
        $values['trust_level'] = $values['trust_level'] ?: 'high';
        $values['language'] = $values['language'] ?: 'tr';
        $values['scan_interval'] = $values['scan_interval'] ?: 'hourly';
        $values['enabled'] = '' === $values['enabled'] ? '1' : $values['enabled'];
        $values['ai_enabled'] = '' === $values['ai_enabled'] ? '1' : $values['ai_enabled'];
        $values['max_items_per_scan'] = $values['max_items_per_scan'] ?: 20;
        $values['max_ai_items_daily'] = $values['max_ai_items_daily'] ?: 20;
        ?>
        <style>
            .sektorel-content-source-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
            .sektorel-content-source-field { margin-bottom:16px; }
            .sektorel-content-source-field label { display:block; font-weight:600; margin-bottom:6px; }
            .sektorel-content-source-field input[type="text"],
            .sektorel-content-source-field input[type="url"],
            .sektorel-content-source-field input[type="number"],
            .sektorel-content-source-field select,
            .sektorel-content-source-field textarea { width:100%; }
            .sektorel-content-source-readonly { padding:12px; background:#f6f7f7; border:1px solid #dcdcde; }
            @media (max-width: 782px) { .sektorel-content-source-grid { grid-template-columns:1fr; } }
        </style>

        <div class="sektorel-content-source-grid">
            <div class="sektorel-content-source-field">
                <label for="source_key">Source Key</label>
                <input id="source_key" name="source_key" type="text" value="<?php echo esc_attr( $values['source_key'] ); ?>" placeholder="tcmb_press" />
                <p class="description">Kalıcı ve benzersiz anahtar. Tarama başladıktan sonra değiştirilmemeli.</p>
            </div>
            <div class="sektorel-content-source-field">
                <label for="source_type">Kaynak Tipi</label>
                <select id="source_type" name="source_type">
                    <?php foreach ( array( 'rss' => 'RSS / XML', 'json_api' => 'JSON API', 'html' => 'HTML', 'custom' => 'Kaynağa Özel Adapter' ) as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $values['source_type'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="sektorel-content-source-field">
            <label for="base_url">Ana Kaynak URL</label>
            <input id="base_url" name="base_url" type="url" value="<?php echo esc_attr( $values['base_url'] ); ?>" />
        </div>

        <div class="sektorel-content-source-field">
            <label for="feed_url">Feed / Endpoint URL</label>
            <input id="feed_url" name="feed_url" type="url" value="<?php echo esc_attr( $values['feed_url'] ); ?>" />
        </div>

        <div class="sektorel-content-source-grid">
            <div class="sektorel-content-source-field">
                <label for="adapter">Adapter</label>
                <input id="adapter" name="adapter" type="text" value="<?php echo esc_attr( $values['adapter'] ); ?>" placeholder="rss_generic / tcmb_press" />
            </div>
            <div class="sektorel-content-source-field">
                <label for="role">Rol</label>
                <select id="role" name="role">
                    <?php foreach ( array( 'official' => 'Resmî / Birincil', 'trusted_industry' => 'Güvenilir Sektörel', 'company_newsroom' => 'Şirket Newsroom', 'editorial' => 'Editoryal Yayın' ) as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $values['role'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="sektorel-content-source-grid">
            <div class="sektorel-content-source-field">
                <label for="trust_level">Güven Seviyesi</label>
                <select id="trust_level" name="trust_level">
                    <?php foreach ( array( 'high' => 'Yüksek', 'medium' => 'Orta', 'low' => 'Düşük' ) as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $values['trust_level'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sektorel-content-source-field">
                <label for="language">Dil</label>
                <input id="language" name="language" type="text" value="<?php echo esc_attr( $values['language'] ); ?>" maxlength="12" />
            </div>
        </div>

        <div class="sektorel-content-source-field">
            <label for="category_slugs">Kategori Slug Eşlemesi</label>
            <input id="category_slugs" name="category_slugs" type="text" value="<?php echo esc_attr( $values['category_slugs'] ); ?>" placeholder="ekonomi-piyasalar,finans-bankacilik" />
            <p class="description">Virgülle ayrılmış mevcut WordPress kategori slug’ları. Yeni kategori yaratmaz.</p>
        </div>

        <div class="sektorel-content-source-grid">
            <div class="sektorel-content-source-field">
                <label for="scan_interval">Tarama Aralığı</label>
                <select id="scan_interval" name="scan_interval">
                    <?php foreach ( array( 'hourly' => 'Saatlik', 'twicedaily' => 'Günde 2', 'daily' => 'Günlük', 'manual' => 'Sadece Manuel' ) as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $values['scan_interval'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sektorel-content-source-field">
                <label for="max_items_per_scan">Tarama Başına Maksimum İçerik</label>
                <input id="max_items_per_scan" name="max_items_per_scan" type="number" min="1" max="200" value="<?php echo esc_attr( $values['max_items_per_scan'] ); ?>" />
            </div>
        </div>

        <div class="sektorel-content-source-grid">
            <div class="sektorel-content-source-field">
                <label for="max_ai_items_daily">Günlük Maksimum AI İçeriği</label>
                <input id="max_ai_items_daily" name="max_ai_items_daily" type="number" min="0" max="500" value="<?php echo esc_attr( $values['max_ai_items_daily'] ); ?>" />
            </div>
            <div class="sektorel-content-source-field">
                <label>Otomasyon</label>
                <label><input type="checkbox" name="enabled" value="1" <?php checked( $values['enabled'], '1' ); ?> /> Kaynak aktif</label><br />
                <label><input type="checkbox" name="ai_enabled" value="1" <?php checked( $values['ai_enabled'], '1' ); ?> /> AI işleme izinli</label>
            </div>
        </div>

        <hr />
        <div class="sektorel-content-source-grid">
            <div class="sektorel-content-source-field"><label>Son Tarama</label><div class="sektorel-content-source-readonly"><?php echo esc_html( $values['last_scan'] ?: 'Henüz taranmadı' ); ?></div></div>
            <div class="sektorel-content-source-field"><label>Son Başarı</label><div class="sektorel-content-source-readonly"><?php echo esc_html( $values['last_success'] ?: '—' ); ?></div></div>
        </div>
        <?php if ( $values['last_error'] ) : ?>
            <div class="sektorel-content-source-field"><label>Son Hata</label><div class="sektorel-content-source-readonly" style="border-left:4px solid #d63638;"><?php echo esc_html( $values['last_error'] ); ?></div></div>
        <?php endif; ?>
        <?php
    }

    public static function render_coverage_meta_box( $post ) {
        $source_key = sanitize_key( (string) get_post_meta( $post->ID, 'source_key', true ) );
        $profile    = Sektorel_Content_Source_Policy::coverage_profile( $source_key, $post->ID );
        $desks      = self::primary_desk_options();
        $tiers      = array(
            'a' => 'Tier A — Resmî / canonical',
            'b' => 'Tier B — Güvenilir kurum / oda / dernek',
            'c' => 'Tier C — Kaliteli B2B medya',
            'd' => 'Tier D — Discovery-only / bilinmiyor',
        );
        $detail_strategies = array(
            'feed_only'             => 'Feed only',
            'detail_page_preferred' => 'Detail page preferred',
            'detail_page_required'  => 'Detail page required',
        );
        $image_policies = array(
            'pexels_only'      => 'Pexels only',
            'source_preferred' => 'Source preferred',
            'none'             => 'Görsel kullanma',
        );
        ?>
        <style>
            .sektorel-coverage-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
            .sektorel-coverage-field{margin-bottom:16px}
            .sektorel-coverage-field label{display:block;font-weight:600;margin-bottom:6px}
            .sektorel-coverage-field input,.sektorel-coverage-field select{width:100%}
            .sektorel-coverage-note{padding:10px 12px;background:#f6f7f7;border-left:4px solid #2271b1;margin:0 0 16px}
            @media(max-width:782px){.sektorel-coverage-grid{grid-template-columns:1fr}}
        </style>

        <div class="sektorel-coverage-note">
            Bu alanlar kaynağın kapsama/provenance profilidir. Candidate routing yine deterministik triage tarafından yapılır. Geography alanı location taxonomy terimlerini yüklemez.
        </div>

        <div class="sektorel-coverage-grid">
            <div class="sektorel-coverage-field">
                <label for="primary_desk">Primary Desk</label>
                <select id="primary_desk" name="primary_desk">
                    <?php foreach ( $desks as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $profile['primary_desk'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description">Geniş kaynaklarda boş bırakmak fail-closed triage davranışını korur.</p>
            </div>
            <div class="sektorel-coverage-field">
                <label for="source_tier">Source Tier</label>
                <select id="source_tier" name="source_tier">
                    <?php foreach ( $tiers as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $profile['source_tier'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="sektorel-coverage-grid">
            <div class="sektorel-coverage-field">
                <label for="topic_scope">Topic Scope</label>
                <input id="topic_scope" name="topic_scope" type="text" value="<?php echo esc_attr( implode( ',', (array) $profile['topic_scope'] ) ); ?>" placeholder="para-politikasi,odeme-sistemleri" />
                <p class="description">Virgülle ayrılmış topic slug’ları.</p>
            </div>
            <div class="sektorel-coverage-field">
                <label for="sector_scope">Sector Scope</label>
                <input id="sector_scope" name="sector_scope" type="text" value="<?php echo esc_attr( implode( ',', (array) $profile['sector_scope'] ) ); ?>" placeholder="finans,multi-sector" />
                <p class="description">Virgülle ayrılmış sektör kapsam etiketleri; taxonomy enumerasyonu yapmaz.</p>
            </div>
        </div>

        <div class="sektorel-coverage-grid">
            <div class="sektorel-coverage-field">
                <label for="geography">Geography</label>
                <input id="geography" name="geography" type="text" value="<?php echo esc_attr( $profile['geography'] ); ?>" placeholder="tr-national" />
                <p class="description">Örn. tr-national, tr-istanbul, global. Location taxonomy yüklenmez.</p>
            </div>
            <div class="sektorel-coverage-field">
                <label for="detail_strategy">Detail Strategy</label>
                <select id="detail_strategy" name="detail_strategy">
                    <?php foreach ( $detail_strategies as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $profile['detail_strategy'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="sektorel-coverage-grid">
            <div class="sektorel-coverage-field">
                <label for="image_policy">Image Policy</label>
                <select id="image_policy" name="image_policy">
                    <?php foreach ( $image_policies as $key => $label ) : ?>
                        <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $profile['image_policy'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="sektorel-coverage-field">
                <label>Etkin Profil</label>
                <div class="sektorel-content-source-readonly"><code><?php echo esc_html( $source_key ?: 'source-key-yok' ); ?></code></div>
                <p class="description">Kaydedilen değerler registry default’unu bu kaynak için override eder.</p>
            </div>
        </div>
        <?php
    }

    public static function save_source( $post_id, $post ) {
        if ( ! isset( $_POST['sektorel_content_source_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sektorel_content_source_nonce'] ) ), 'sektorel_content_source_save' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) || ! $post || 'content_source' !== $post->post_type ) {
            return;
        }

        $source_key = isset( $_POST['source_key'] ) ? sanitize_key( wp_unslash( $_POST['source_key'] ) ) : '';
        if ( ! $source_key && $post->post_title ) {
            $source_key = sanitize_key( $post->post_name ?: sanitize_title( $post->post_title ) );
        }

        $source_type = isset( $_POST['source_type'] ) ? sanitize_key( wp_unslash( $_POST['source_type'] ) ) : 'rss';
        $role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : 'official';
        $trust_level = isset( $_POST['trust_level'] ) ? sanitize_key( wp_unslash( $_POST['trust_level'] ) ) : 'high';
        $scan_interval = isset( $_POST['scan_interval'] ) ? sanitize_key( wp_unslash( $_POST['scan_interval'] ) ) : 'hourly';

        if ( ! in_array( $source_type, array( 'rss', 'json_api', 'html', 'custom' ), true ) ) {
            $source_type = 'rss';
        }
        if ( ! in_array( $role, array( 'official', 'trusted_industry', 'company_newsroom', 'editorial' ), true ) ) {
            $role = 'editorial';
        }
        if ( ! in_array( $trust_level, array( 'high', 'medium', 'low' ), true ) ) {
            $trust_level = 'medium';
        }
        if ( ! in_array( $scan_interval, array( 'hourly', 'twicedaily', 'daily', 'manual' ), true ) ) {
            $scan_interval = 'manual';
        }

        $category_slugs = isset( $_POST['category_slugs'] ) ? self::sanitize_slug_csv( wp_unslash( $_POST['category_slugs'] ) ) : '';

        $primary_desk = isset( $_POST['primary_desk'] ) ? sanitize_title( wp_unslash( $_POST['primary_desk'] ) ) : '';
        if ( ! array_key_exists( $primary_desk, self::primary_desk_options() ) ) {
            $primary_desk = '';
        }

        $source_tier = isset( $_POST['source_tier'] ) ? sanitize_key( wp_unslash( $_POST['source_tier'] ) ) : 'd';
        if ( ! in_array( $source_tier, Sektorel_Content_Source_Policy::source_tiers(), true ) ) {
            $source_tier = 'd';
        }

        $detail_strategy = isset( $_POST['detail_strategy'] ) ? sanitize_key( wp_unslash( $_POST['detail_strategy'] ) ) : Sektorel_Content_Source_Policy::DETAIL_FEED_ONLY;
        if ( ! in_array( $detail_strategy, Sektorel_Content_Source_Policy::detail_strategies(), true ) ) {
            $detail_strategy = Sektorel_Content_Source_Policy::DETAIL_FEED_ONLY;
        }

        $image_policy = isset( $_POST['image_policy'] ) ? sanitize_key( wp_unslash( $_POST['image_policy'] ) ) : Sektorel_Content_Source_Policy::IMAGE_PEXELS_ONLY;
        if ( ! in_array( $image_policy, Sektorel_Content_Source_Policy::image_policies(), true ) ) {
            $image_policy = Sektorel_Content_Source_Policy::IMAGE_PEXELS_ONLY;
        }

        $meta = array(
            'source_key'         => $source_key,
            'base_url'           => isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '',
            'feed_url'           => isset( $_POST['feed_url'] ) ? esc_url_raw( wp_unslash( $_POST['feed_url'] ) ) : '',
            'source_type'        => $source_type,
            'adapter'            => isset( $_POST['adapter'] ) ? sanitize_key( wp_unslash( $_POST['adapter'] ) ) : '',
            'role'               => $role,
            'trust_level'        => $trust_level,
            'language'           => isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : 'tr',
            'category_slugs'     => $category_slugs,
            'scan_interval'      => $scan_interval,
            'enabled'            => isset( $_POST['enabled'] ) ? '1' : '0',
            'ai_enabled'         => isset( $_POST['ai_enabled'] ) ? '1' : '0',
            'max_items_per_scan' => isset( $_POST['max_items_per_scan'] ) ? max( 1, min( 200, absint( $_POST['max_items_per_scan'] ) ) ) : 20,
            'max_ai_items_daily' => isset( $_POST['max_ai_items_daily'] ) ? min( 500, absint( $_POST['max_ai_items_daily'] ) ) : 20,
            'primary_desk'       => $primary_desk,
            'topic_scope'        => isset( $_POST['topic_scope'] ) ? self::sanitize_slug_csv( wp_unslash( $_POST['topic_scope'] ) ) : '',
            'sector_scope'       => isset( $_POST['sector_scope'] ) ? self::sanitize_slug_csv( wp_unslash( $_POST['sector_scope'] ) ) : '',
            'geography'          => isset( $_POST['geography'] ) ? sanitize_title( wp_unslash( $_POST['geography'] ) ) : 'unspecified',
            'source_tier'        => $source_tier,
            'detail_strategy'    => $detail_strategy,
            'image_policy'       => $image_policy,
        );

        if ( '' === $meta['geography'] ) {
            $meta['geography'] = 'unspecified';
        }

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }
    }

    public static function columns( $columns ) {
        return array(
            'cb'            => $columns['cb'] ?? '<input type="checkbox" />',
            'title'         => 'Kaynak',
            'source_key'    => 'Key',
            'source_type'   => 'Tip',
            'role'          => 'Rol',
            'trust_level'   => 'Güven',
            'source_tier'   => 'Tier',
            'enabled'       => 'Durum',
            'last_scan'     => 'Son Tarama',
            'date'          => 'Eklenme',
        );
    }

    public static function render_column( $column, $post_id ) {
        switch ( $column ) {
            case 'enabled':
                echo '1' === (string) get_post_meta( $post_id, 'enabled', true ) ? 'Aktif' : 'Pasif';
                break;
            case 'source_tier':
                $source_key = sanitize_key( (string) get_post_meta( $post_id, 'source_key', true ) );
                $tier = Sektorel_Content_Source_Policy::source_tier( $source_key, $post_id );
                echo $tier ? esc_html( strtoupper( $tier ) ) : '—';
                break;
            case 'source_key':
            case 'source_type':
            case 'role':
            case 'trust_level':
            case 'last_scan':
                $value = (string) get_post_meta( $post_id, $column, true );
                echo $value ? esc_html( $value ) : '—';
                break;
        }
    }

    private static function primary_desk_options() {
        return array(
            ''                            => 'Geniş kaynak / deterministik triage belirlesin',
            'sirketler-yatirimlar'        => 'Şirketler',
            'sanayi-uretim'               => 'Sanayi & Üretim',
            'kobi-girisimcilik'           => 'KOBİ & Girişim',
            'teknoloji-dijital-donusum'   => 'Teknoloji & Dijital Dönüşüm',
            'finans-bankacilik'           => 'Finansman',
            'dis-ticaret-ihracat'         => 'İhracat & Dış Ticaret',
            'mevzuat-tesvikler'           => 'Teşvik & Mevzuat',
            'istihdam-insan-kaynaklari'   => 'İnsan & Yönetim',
            'ekonomi-piyasalar'           => 'Ekonomi & Piyasalar',
        );
    }

    private static function sanitize_slug_csv( $value ) {
        $items = array_map( 'trim', explode( ',', (string) $value ) );
        $items = array_filter( array_map( 'sanitize_title', $items ) );
        return implode( ',', array_values( array_unique( $items ) ) );
    }
}
