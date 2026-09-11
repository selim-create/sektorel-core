<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Content_Source_Center {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 45 );
    }

    public static function add_menu() {
        add_submenu_page(
            'edit.php',
            'İçerik Kaynak Merkezi',
            'Kaynak Merkezi',
            'manage_options',
            'sektorel-content-source-center',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'sektorel-core' ) );
        }

        $stats = Sektorel_Content_Candidates::stats();
        $sources = get_posts( array(
            'post_type'      => 'content_source',
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        $recent = Sektorel_Content_Candidates::recent( 20 );
        ?>
        <div class="wrap">
            <h1>İçerik Kaynak Merkezi</h1>
            <p>Bu ilk foundation sürümünde kaynak tanımı ve candidate gözlemlenebilirliği aktiftir. Tarama, AI işleme ve otomatik yayın henüz kapalıdır.</p>

            <style>
                .sektorel-content-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:18px 0 24px;max-width:1100px}
                .sektorel-content-card{background:#fff;border:1px solid #dcdcde;padding:16px}
                .sektorel-content-card strong{display:block;font-size:24px;margin-top:5px}
                .sektorel-content-muted{color:#646970}
                .sektorel-content-table{max-width:1200px}
                .sektorel-status-pill{display:inline-block;padding:2px 8px;border-radius:999px;background:#f0f0f1;font-size:12px}
            </style>

            <div class="sektorel-content-cards">
                <?php
                $cards = array(
                    'Kaynak'     => count( $sources ),
                    'Candidate'  => $stats['total'],
                    'Yeni'       => $stats['new'],
                    'Duplicate'  => $stats['duplicate'],
                    'İnceleme'   => $stats['review'],
                    'Hazır'      => $stats['ready'],
                    'İşlendi'    => $stats['processed'],
                    'Hata'       => $stats['error'],
                );
                foreach ( $cards as $label => $value ) : ?>
                    <div class="sektorel-content-card"><span><?php echo esc_html( $label ); ?></span><strong><?php echo (int) $value; ?></strong></div>
                <?php endforeach; ?>
            </div>

            <p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=content_source' ) ); ?>">Yeni İçerik Kaynağı</a>
                <a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=content_source' ) ); ?>">Kaynakları Yönet</a>
            </p>

            <h2>Kaynaklar</h2>
            <table class="widefat striped sektorel-content-table">
                <thead><tr><th>Kaynak</th><th>Key</th><th>Tip</th><th>Rol</th><th>Güven</th><th>Durum</th><th>Son Tarama</th></tr></thead>
                <tbody>
                <?php if ( empty( $sources ) ) : ?>
                    <tr><td colspan="7">Henüz içerik kaynağı tanımlanmadı.</td></tr>
                <?php else : foreach ( $sources as $source ) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url( get_edit_post_link( $source->ID ) ); ?>"><?php echo esc_html( get_the_title( $source ) ); ?></a></td>
                        <td><code><?php echo esc_html( get_post_meta( $source->ID, 'source_key', true ) ?: '—' ); ?></code></td>
                        <td><?php echo esc_html( get_post_meta( $source->ID, 'source_type', true ) ?: '—' ); ?></td>
                        <td><?php echo esc_html( get_post_meta( $source->ID, 'role', true ) ?: '—' ); ?></td>
                        <td><?php echo esc_html( get_post_meta( $source->ID, 'trust_level', true ) ?: '—' ); ?></td>
                        <td><?php echo '1' === (string) get_post_meta( $source->ID, 'enabled', true ) ? 'Aktif' : 'Pasif'; ?></td>
                        <td><?php echo esc_html( get_post_meta( $source->ID, 'last_scan', true ) ?: '—' ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:28px">Son Candidate Kayıtları</h2>
            <table class="widefat striped sektorel-content-table">
                <thead><tr><th>ID</th><th>Kaynak</th><th>Başlık</th><th>Durum</th><th>Duplicate</th><th>AI</th><th>İlk Görülme</th></tr></thead>
                <tbody>
                <?php if ( empty( $recent ) ) : ?>
                    <tr><td colspan="7">Henüz candidate yok. Bir sonraki fazda source scanner bağlanacak.</td></tr>
                <?php else : foreach ( $recent as $candidate ) : ?>
                    <tr>
                        <td><?php echo (int) $candidate['id']; ?></td>
                        <td><code><?php echo esc_html( $candidate['source_key'] ); ?></code></td>
                        <td>
                            <?php if ( ! empty( $candidate['source_url'] ) ) : ?>
                                <a href="<?php echo esc_url( $candidate['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $candidate['title'] ?: $candidate['source_url'] ); ?></a>
                            <?php else : echo esc_html( $candidate['title'] ?: '—' ); endif; ?>
                        </td>
                        <td><span class="sektorel-status-pill"><?php echo esc_html( $candidate['status'] ); ?></span></td>
                        <td><?php echo ! empty( $candidate['duplicate_method'] ) ? esc_html( $candidate['duplicate_method'] . ' #' . (int) $candidate['duplicate_candidate_id'] ) : '—'; ?></td>
                        <td><?php echo esc_html( $candidate['ai_status'] ?: 'pending' ); ?></td>
                        <td><?php echo esc_html( $candidate['first_seen_at'] ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
