<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

        $category_slugs = isset( $_POST['category_slugs'] ) ? (string) wp_unslash( $_POST['category_slugs'] ) : '';
        $category_slugs = implode( ',', array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', $category_slugs ) ) ) ) );

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
        );

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
}
