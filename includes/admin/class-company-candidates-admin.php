<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Candidates_Admin {

    const ACTION = 'sektorel_company_candidate_apply';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 35 );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_apply' ) );
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

    public static function handle_apply() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu işlemi yapma yetkiniz yok.', 'sektorel-core' ) );
        }

        $candidate_id = isset( $_POST['candidate_id'] ) ? absint( $_POST['candidate_id'] ) : 0;
        check_admin_referer( self::ACTION . '_' . $candidate_id );

        $result = Sektorel_Company_Candidate_Lifecycle::apply( $candidate_id );
        $args = array( 'post_type' => 'company', 'page' => 'sektorel-company-candidates' );
        if ( is_wp_error( $result ) ) {
            $args['candidate_error'] = $result->get_error_message();
        } else {
            $args['candidate_done'] = sanitize_key( $result['action'] ?? 'done' );
            $args['company_id'] = (int) ( $result['company_id'] ?? 0 );
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
        exit;
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
            "SELECT id, source_key, normalized_name, domain, status, matched_company_id, match_method, lifecycle_status, applied_company_id, last_seen_at
             FROM {$table}
             ORDER BY last_seen_at DESC, id DESC
             LIMIT 100",
            ARRAY_A
        );

        $error = isset( $_GET['candidate_error'] ) ? sanitize_text_field( wp_unslash( $_GET['candidate_error'] ) ) : '';
        $done  = isset( $_GET['candidate_done'] ) ? sanitize_key( wp_unslash( $_GET['candidate_done'] ) ) : '';
        $company_id = isset( $_GET['company_id'] ) ? absint( $_GET['company_id'] ) : 0;
        ?>
        <div class="wrap">
            <h1>Aday Firmalar</h1>
            <p>Harici firma kaynaklarının canonical firma kayıtlarına geçmeden önce toplandığı ve deterministic matcher ile kontrol edildiği katman.</p>

            <?php if ( $error ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
            <?php elseif ( $done ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    İşlem tamamlandı: <strong><?php echo esc_html( $done ); ?></strong>
                    <?php if ( $company_id ) : ?>
                        · <a href="<?php echo esc_url( get_edit_post_link( $company_id ) ); ?>">Firma #<?php echo (int) $company_id; ?></a>
                    <?php endif; ?>
                </p></div>
            <?php endif; ?>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin:18px 0 24px;">
                <?php foreach ( array( 'total' => 'Toplam', 'new' => 'Yeni', 'matched' => 'Eşleşen', 'review' => 'İnceleme' ) as $key => $label ) : ?>
                    <div class="card" style="min-width:150px;padding:16px;margin:0;">
                        <div style="font-size:12px;color:#646970;text-transform:uppercase;font-weight:600;"><?php echo esc_html( $label ); ?></div>
                        <div style="font-size:28px;font-weight:700;margin-top:4px;"><?php echo esc_html( number_format_i18n( (int) ( $stats[ $key ] ?? 0 ) ) ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="notice notice-info inline">
                <p><strong>Fail-closed:</strong> Yeni aday yalnız <strong>taslak firma</strong> oluşturabilir. Eşleşen aday yalnız mevcut firmanın boş alanlarını doldurabilir. <code>review</code> adayları otomatik ilerlemez.</p>
            </div>

            <table class="widefat striped" style="margin-top:18px;">
                <thead><tr><th>ID</th><th>Kaynak</th><th>Firma</th><th>Domain</th><th>Durum</th><th>Eşleşme</th><th>Lifecycle</th><th>İşlem</th><th>Son Görülme</th></tr></thead>
                <tbody>
                <?php if ( empty( $rows ) ) : ?>
                    <tr><td colspan="9">Henüz firma adayı yok. İlk source entegrasyonu geldiğinde kayıtlar burada görünecek.</td></tr>
                <?php else : ?>
                    <?php foreach ( $rows as $row ) :
                        $pending = 'pending' === ( $row['lifecycle_status'] ?: 'pending' );
                        $can_apply = $pending && in_array( $row['status'], array( 'new', 'matched' ), true );
                        ?>
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
                                <?php else : echo esc_html( $row['match_method'] ?: '—' ); endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html( $row['lifecycle_status'] ?: 'pending' ); ?>
                                <?php if ( ! empty( $row['applied_company_id'] ) ) : ?>
                                    · <a href="<?php echo esc_url( get_edit_post_link( (int) $row['applied_company_id'] ) ); ?>">#<?php echo (int) $row['applied_company_id']; ?></a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $can_apply ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Bu aday işlensin mi? Yeni adaylarda yalnız taslak firma oluşturulur.');">
                                        <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                                        <input type="hidden" name="candidate_id" value="<?php echo (int) $row['id']; ?>">
                                        <?php wp_nonce_field( self::ACTION . '_' . (int) $row['id'] ); ?>
                                        <button type="submit" class="button button-small"><?php echo 'new' === $row['status'] ? 'Taslak Firma Oluştur' : 'Mevcut Firmayı Zenginleştir'; ?></button>
                                    </form>
                                <?php elseif ( 'review' === $row['status'] ) : ?>
                                    <span style="color:#b32d2e;font-weight:600;">Manuel inceleme gerekli</span>
                                <?php else : ?>—<?php endif; ?>
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
