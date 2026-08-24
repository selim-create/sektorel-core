<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Candidates_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 35 );
    }

    public static function add_menu() {
        add_submenu_page(
            'edit.php?post_type=company',
            'Aday Firmalar',
            'Aday Firmalar',
            'manage_options',
            'sektorel-company-candidates',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'sektorel-core' ) );
        }

        global $wpdb;
        Sektorel_Company_Candidates::maybe_install();
        $table = Sektorel_Company_Candidates::table_name();
        $stats = Sektorel_Company_Candidates::stats();
        $rows  = $wpdb->get_results(
            "SELECT id, source_key, normalized_name, domain, status, matched_company_id, match_method, last_seen_at
             FROM {$table}
             ORDER BY last_seen_at DESC, id DESC
             LIMIT 100",
            ARRAY_A
        );
        ?>
        <div class="wrap">
            <h1>Aday Firmalar</h1>
            <p>Otomatik ve harici firma kaynaklarının canonical firma oluşturmadan önce geçtiği gözlem katmanı. Bu ekran yalnız tanı/inceleme amaçlıdır.</p>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0 24px;">
                <?php
                $cards = array(
                    'total'   => 'Toplam',
                    'new'     => 'Yeni',
                    'matched' => 'Eşleşen',
                    'review'  => 'İnceleme',
                );
                foreach ( $cards as $key => $label ) :
                    ?>
                    <div class="card" style="min-width:150px;padding:16px;margin:0;">
                        <div style="font-size:12px;color:#646970;text-transform:uppercase;font-weight:600;"><?php echo esc_html( $label ); ?></div>
                        <div style="font-size:28px;font-weight:700;margin-top:4px;"><?php echo esc_html( number_format_i18n( (int) ( $stats[ $key ] ?? 0 ) ) ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="notice notice-info inline">
                <p><strong>Fail-closed:</strong> Bu foundation candidate'lardan otomatik firma oluşturmaz veya yayınlamaz. <code>review</code> kayıtları özellikle manuel/sonraki aşama incelemesi için tutulur.</p>
            </div>

            <table class="widefat striped" style="margin-top:18px;">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Kaynak</th>
                        <th>Firma</th>
                        <th>Domain</th>
                        <th>Durum</th>
                        <th>Eşleşme</th>
                        <th>Son Görülme</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="7">Henüz firma adayı yok. İlk source entegrasyonu geldiğinde kayıtlar burada görünecek.</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td><?php echo (int) $row['id']; ?></td>
                            <td><code><?php echo esc_html( $row['source_key'] ); ?></code></td>
                            <td><?php echo esc_html( $row['normalized_name'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $row['domain'] ?: '—' ); ?></td>
                            <td><strong><?php echo esc_html( $row['status'] ); ?></strong></td>
                            <td>
                                <?php if ( ! empty( $row['matched_company_id'] ) ) : ?>
                                    <a href="<?php echo esc_url( get_edit_post_link( (int) $row['matched_company_id'] ) ); ?>">#<?php echo (int) $row['matched_company_id']; ?></a>
                                    <small><?php echo esc_html( $row['match_method'] ? ' · ' . $row['match_method'] : '' ); ?></small>
                                <?php else : ?>
                                    <?php echo esc_html( $row['match_method'] ?: '—' ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $row['last_seen_at'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
