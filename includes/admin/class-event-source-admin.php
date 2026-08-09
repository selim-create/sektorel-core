<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Source_Admin {

    const META_KEYS = array(
        'source_url',
        'source_type',
        'parser_type',
        'source_status',
        'last_checked_at',
        'last_result',
        'last_error',
    );

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_event_source', array( __CLASS__, 'save_source' ), 10, 2 );
        add_filter( 'manage_event_source_posts_columns', array( __CLASS__, 'columns' ) );
        add_action( 'manage_event_source_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_event_source_details',
            'Kaynak Ayarları',
            array( __CLASS__, 'render_meta_box' ),
            'event_source',
            'normal',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'sektorel_event_source_save', 'sektorel_event_source_nonce' );

        $source_url      = (string) get_post_meta( $post->ID, 'source_url', true );
        $source_type     = (string) get_post_meta( $post->ID, 'source_type', true );
        $parser_type     = (string) get_post_meta( $post->ID, 'parser_type', true );
        $source_status   = (string) get_post_meta( $post->ID, 'source_status', true );
        $last_checked_at = (string) get_post_meta( $post->ID, 'last_checked_at', true );
        $last_result     = (string) get_post_meta( $post->ID, 'last_result', true );
        $last_error      = (string) get_post_meta( $post->ID, 'last_error', true );

        if ( ! $source_status ) {
            $source_status = 'active';
        }

        if ( ! $parser_type ) {
            $parser_type = 'auto';
        }
        ?>
        <style>
            .sektorel-source-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
            .sektorel-source-field { margin-bottom:16px; }
            .sektorel-source-field label { display:block; font-weight:600; margin-bottom:6px; }
            .sektorel-source-field input[type="text"],
            .sektorel-source-field input[type="url"],
            .sektorel-source-field select,
            .sektorel-source-field textarea { width:100%; }
            .sektorel-source-readonly { padding:12px; background:#f6f7f7; border:1px solid #dcdcde; }
            @media (max-width: 782px) { .sektorel-source-grid { grid-template-columns:1fr; } }
        </style>

        <div class="sektorel-source-field">
            <label for="source_url">Kaynak URL</label>
            <input id="source_url" name="source_url" type="url" value="<?php echo esc_attr( $source_url ); ?>" placeholder="https://ornek.com/etkinlikler" />
            <p class="description">Mümkünse doğrudan etkinlik/takvim liste sayfasını kullanın. Sadece domain de kaydedilebilir.</p>
        </div>

        <div class="sektorel-source-grid">
            <div class="sektorel-source-field">
                <label for="source_type">Kaynak / Etkinlik Türü</label>
                <input id="source_type" name="source_type" type="text" value="<?php echo esc_attr( $source_type ); ?>" placeholder="Fuar, Webinar, Kongre, Resmî Kurum..." />
            </div>

            <div class="sektorel-source-field">
                <label for="parser_type">Parser</label>
                <select id="parser_type" name="parser_type">
                    <option value="auto" <?php selected( $parser_type, 'auto' ); ?>>Otomatik / Generic</option>
                    <option value="jsonld" <?php selected( $parser_type, 'jsonld' ); ?>>JSON-LD Event</option>
                    <option value="rss" <?php selected( $parser_type, 'rss' ); ?>>RSS / XML</option>
                    <option value="ics" <?php selected( $parser_type, 'ics' ); ?>>ICS / iCalendar</option>
                    <option value="html" <?php selected( $parser_type, 'html' ); ?>>HTML</option>
                    <option value="adapter" <?php selected( $parser_type, 'adapter' ); ?>>Kaynağa Özel Adapter</option>
                    <option value="manual" <?php selected( $parser_type, 'manual' ); ?>>Manuel Kontrol</option>
                </select>
            </div>
        </div>

        <div class="sektorel-source-grid">
            <div class="sektorel-source-field">
                <label for="source_status">Durum</label>
                <select id="source_status" name="source_status">
                    <option value="active" <?php selected( $source_status, 'active' ); ?>>Aktif</option>
                    <option value="paused" <?php selected( $source_status, 'paused' ); ?>>Pasif</option>
                    <option value="missing_url" <?php selected( $source_status, 'missing_url' ); ?>>Kaynak URL Eksik</option>
                    <option value="needs_adapter" <?php selected( $source_status, 'needs_adapter' ); ?>>Adapter Gerekli</option>
                    <option value="manual" <?php selected( $source_status, 'manual' ); ?>>Manuel Kontrol</option>
                </select>
            </div>

            <div class="sektorel-source-field">
                <label>Son Kontrol</label>
                <div class="sektorel-source-readonly">
                    <?php echo $last_checked_at ? esc_html( $last_checked_at ) : 'Henüz kontrol edilmedi'; ?>
                </div>
            </div>
        </div>

        <?php if ( $last_result || $last_error ) : ?>
            <hr />
            <?php if ( $last_result ) : ?>
                <div class="sektorel-source-field">
                    <label>Son Sonuç</label>
                    <div class="sektorel-source-readonly"><?php echo esc_html( $last_result ); ?></div>
                </div>
            <?php endif; ?>
            <?php if ( $last_error ) : ?>
                <div class="sektorel-source-field">
                    <label>Son Hata</label>
                    <div class="sektorel-source-readonly" style="border-left:4px solid #d63638;"><?php echo esc_html( $last_error ); ?></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    public static function save_source( $post_id, $post ) {
        if ( ! isset( $_POST['sektorel_event_source_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sektorel_event_source_nonce'] ) ), 'sektorel_event_source_save' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( ! $post || 'event_source' !== $post->post_type ) {
            return;
        }

        $source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
        $source_type = isset( $_POST['source_type'] ) ? sanitize_text_field( wp_unslash( $_POST['source_type'] ) ) : '';
        $parser_type = isset( $_POST['parser_type'] ) ? sanitize_key( wp_unslash( $_POST['parser_type'] ) ) : 'auto';
        $source_status = isset( $_POST['source_status'] ) ? sanitize_key( wp_unslash( $_POST['source_status'] ) ) : 'active';

        $allowed_parsers = array( 'auto', 'jsonld', 'rss', 'ics', 'html', 'adapter', 'manual' );
        $allowed_statuses = array( 'active', 'paused', 'missing_url', 'needs_adapter', 'manual' );

        if ( ! in_array( $parser_type, $allowed_parsers, true ) ) {
            $parser_type = 'auto';
        }

        if ( ! in_array( $source_status, $allowed_statuses, true ) ) {
            $source_status = 'active';
        }

        if ( ! $source_url && 'paused' !== $source_status ) {
            $source_status = 'missing_url';
        }

        update_post_meta( $post_id, 'source_url', $source_url );
        update_post_meta( $post_id, 'source_type', $source_type );
        update_post_meta( $post_id, 'parser_type', $parser_type );
        update_post_meta( $post_id, 'source_status', $source_status );
    }

    public static function columns( $columns ) {
        return array(
            'cb'              => $columns['cb'] ?? '<input type="checkbox" />',
            'title'           => 'Kaynak',
            'source_url'      => 'URL',
            'source_type'     => 'Tür',
            'parser_type'     => 'Parser',
            'source_status'   => 'Durum',
            'last_checked_at' => 'Son Kontrol',
            'date'            => 'Eklenme',
        );
    }

    public static function render_column( $column, $post_id ) {
        switch ( $column ) {
            case 'source_url':
                $url = (string) get_post_meta( $post_id, 'source_url', true );
                if ( $url ) {
                    echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( wp_parse_url( $url, PHP_URL_HOST ) ?: $url ) . '</a>';
                } else {
                    echo '<span style="color:#b32d2e;">Eksik</span>';
                }
                break;
            case 'source_type':
            case 'parser_type':
            case 'source_status':
            case 'last_checked_at':
                $value = (string) get_post_meta( $post_id, $column, true );
                echo $value ? esc_html( $value ) : '—';
                break;
        }
    }
}
