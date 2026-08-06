<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Records WordPress mail acceptance/failure events and exposes an admin-only
 * diagnostic screen. A successful wp_mail() result means PHPMailer accepted
 * the message for transport; it does not prove inbox delivery.
 */
class Sektorel_Mail_Observability {

    const LOG_OPTION = 'sektorel_mail_log';
    const MAX_LOG_ENTRIES = 100;

    private static $source = 'wordpress';

    public static function init() {
        add_action( 'wp_mail_succeeded', array( __CLASS__, 'record_success' ) );
        add_action( 'wp_mail_failed', array( __CLASS__, 'record_failure' ) );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'register_admin_page' ) );
            add_action( 'admin_post_sektorel_send_test_mail', array( __CLASS__, 'handle_test_mail' ) );
            add_action( 'admin_post_sektorel_clear_mail_log', array( __CLASS__, 'handle_clear_log' ) );
        }
    }

    /**
     * Sends a message while tagging the resulting log entry with a source.
     */
    public static function send( $to, $subject, $message, $headers = array(), $attachments = array(), $source = 'sektorel' ) {
        return self::with_source(
            $source,
            function() use ( $to, $subject, $message, $headers, $attachments ) {
                return wp_mail( $to, $subject, $message, $headers, $attachments );
            }
        );
    }

    /**
     * Runs any mail-producing WordPress flow with a diagnostic source label.
     */
    public static function with_source( $source, $callback ) {
        $previous = self::$source;
        self::$source = sanitize_key( $source ) ?: 'sektorel';

        try {
            return is_callable( $callback ) ? call_user_func( $callback ) : null;
        } finally {
            self::$source = $previous;
        }
    }

    public static function record_success( $mail_data ) {
        self::append_log(
            array(
                'status'  => 'success',
                'to'      => self::normalize_recipients( $mail_data['to'] ?? array() ),
                'subject' => sanitize_text_field( $mail_data['subject'] ?? '' ),
                'source'  => self::$source,
                'error'   => '',
            )
        );
    }

    public static function record_failure( $error ) {
        $data = $error instanceof WP_Error ? $error->get_error_data() : array();
        $data = is_array( $data ) ? $data : array();

        self::append_log(
            array(
                'status'  => 'failed',
                'to'      => self::normalize_recipients( $data['to'] ?? array() ),
                'subject' => sanitize_text_field( $data['subject'] ?? '' ),
                'source'  => self::$source,
                'error'   => $error instanceof WP_Error
                    ? sanitize_text_field( $error->get_error_message() )
                    : 'Bilinmeyen e-posta hatası.',
            )
        );
    }

    public static function register_admin_page() {
        add_management_page(
            'Sektörel E-posta Tanılama',
            'Sektörel E-posta',
            'manage_options',
            'sektorel-mail',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function handle_test_mail() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu işlem için yetkiniz bulunmuyor.', 'sektorel-core' ) );
        }

        check_admin_referer( 'sektorel_send_test_mail' );

        $email = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
        if ( ! is_email( $email ) ) {
            self::redirect_with_status( 'invalid' );
        }

        $subject = sprintf(
            'Sektörel Ajanda test e-postası – %s',
            wp_date( 'd.m.Y H:i' )
        );
        $message = "Bu e-posta Sektörel Core e-posta tanılama ekranından gönderildi.\n\n";
        $message .= "WordPress mesajı başarıyla kabul ederse yönetim ekranında 'Kabul edildi' kaydı oluşur. Gelen kutusu teslimatı ayrıca kontrol edilmelidir.";

        $sent = self::send(
            $email,
            $subject,
            $message,
            array( 'Content-Type: text/plain; charset=UTF-8' ),
            array(),
            'admin_test'
        );

        self::redirect_with_status( $sent ? 'success' : 'failed' );
    }

    public static function handle_clear_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu işlem için yetkiniz bulunmuyor.', 'sektorel-core' ) );
        }

        check_admin_referer( 'sektorel_clear_mail_log' );
        delete_option( self::LOG_OPTION );
        self::redirect_with_status( 'cleared' );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $current_user = wp_get_current_user();
        $logs = get_option( self::LOG_OPTION, array() );
        $logs = is_array( $logs ) ? $logs : array();
        $status = sanitize_key( $_GET['sektorel_mail_status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap">
            <h1>Sektörel E-posta Tanılama</h1>

            <p>
                Bu ekran WordPress/PHPMailer katmanının mesajı kabul edip etmediğini gösterir.
                <strong>“Kabul edildi” kaydı, e-postanın gelen kutusuna teslim edildiğini tek başına kanıtlamaz.</strong>
                Teslimat için SMTP sağlayıcı kayıtları ve alıcının gelen/spam klasörü ayrıca kontrol edilmelidir.
            </p>

            <?php if ( 'success' === $status ) : ?>
                <div class="notice notice-success is-dismissible"><p>Test e-postası WordPress tarafından kabul edildi. Gelen kutusunu ve spam klasörünü kontrol edin.</p></div>
            <?php elseif ( 'failed' === $status ) : ?>
                <div class="notice notice-error is-dismissible"><p>Test e-postası gönderilemedi. Aşağıdaki son hata kaydını inceleyin.</p></div>
            <?php elseif ( 'invalid' === $status ) : ?>
                <div class="notice notice-error is-dismissible"><p>Geçerli bir test e-posta adresi girin.</p></div>
            <?php elseif ( 'cleared' === $status ) : ?>
                <div class="notice notice-success is-dismissible"><p>E-posta kayıtları temizlendi.</p></div>
            <?php endif; ?>

            <div style="max-width:760px;background:#fff;border:1px solid #dcdcde;padding:20px;margin:20px 0;">
                <h2 style="margin-top:0;">Test e-postası gönder</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="sektorel_send_test_mail">
                    <?php wp_nonce_field( 'sektorel_send_test_mail' ); ?>
                    <label for="sektorel-test-email"><strong>Alıcı e-posta</strong></label><br>
                    <input
                        id="sektorel-test-email"
                        name="test_email"
                        type="email"
                        class="regular-text"
                        required
                        value="<?php echo esc_attr( $current_user->user_email ); ?>"
                        style="margin:8px 0 12px;"
                    >
                    <br>
                    <?php submit_button( 'Test e-postası gönder', 'primary', 'submit', false ); ?>
                </form>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:28px;">
                <h2 style="margin:0;">Son e-posta kayıtları</h2>
                <?php if ( ! empty( $logs ) ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="sektorel_clear_mail_log">
                        <?php wp_nonce_field( 'sektorel_clear_mail_log' ); ?>
                        <?php submit_button( 'Kayıtları temizle', 'secondary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            </div>

            <table class="widefat striped" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>Durum</th>
                        <th>Kaynak</th>
                        <th>Alıcı</th>
                        <th>Konu</th>
                        <th>Hata</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="6">Henüz e-posta kaydı bulunmuyor.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $entry ) : ?>
                            <tr>
                                <td><?php echo esc_html( self::format_log_date( $entry['timestamp'] ?? '' ) ); ?></td>
                                <td>
                                    <?php if ( 'success' === ( $entry['status'] ?? '' ) ) : ?>
                                        <span style="color:#008a20;font-weight:700;">Kabul edildi</span>
                                    <?php else : ?>
                                        <span style="color:#b32d2e;font-weight:700;">Başarısız</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html( $entry['source'] ?? 'wordpress' ); ?></code></td>
                                <td><?php echo esc_html( $entry['to'] ?? '' ); ?></td>
                                <td><?php echo esc_html( $entry['subject'] ?? '' ); ?></td>
                                <td><?php echo esc_html( $entry['error'] ?? '' ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function append_log( $entry ) {
        $logs = get_option( self::LOG_OPTION, array() );
        $logs = is_array( $logs ) ? $logs : array();

        array_unshift(
            $logs,
            array(
                'timestamp' => gmdate( 'c' ),
                'status'    => 'success' === ( $entry['status'] ?? '' ) ? 'success' : 'failed',
                'to'        => sanitize_text_field( $entry['to'] ?? '' ),
                'subject'   => sanitize_text_field( $entry['subject'] ?? '' ),
                'source'    => sanitize_key( $entry['source'] ?? 'wordpress' ) ?: 'wordpress',
                'error'     => sanitize_text_field( $entry['error'] ?? '' ),
            )
        );

        $logs = array_slice( $logs, 0, self::MAX_LOG_ENTRIES );
        update_option( self::LOG_OPTION, $logs, false );
    }

    private static function normalize_recipients( $recipients ) {
        if ( is_string( $recipients ) ) {
            $recipients = preg_split( '/\s*,\s*/', $recipients );
        }

        if ( ! is_array( $recipients ) ) {
            return '';
        }

        $normalized = array();
        foreach ( $recipients as $recipient ) {
            $recipient = trim( (string) $recipient );
            if ( preg_match( '/<([^>]+)>/', $recipient, $matches ) ) {
                $recipient = $matches[1];
            }
            $email = sanitize_email( $recipient );
            if ( $email ) {
                $normalized[] = $email;
            }
        }

        return implode( ', ', array_unique( $normalized ) );
    }

    private static function redirect_with_status( $status ) {
        $url = add_query_arg(
            array(
                'page'                  => 'sektorel-mail',
                'sektorel_mail_status' => sanitize_key( $status ),
            ),
            admin_url( 'tools.php' )
        );

        wp_safe_redirect( $url );
        exit;
    }

    private static function format_log_date( $timestamp ) {
        $unix = strtotime( (string) $timestamp );
        return $unix ? wp_date( 'd.m.Y H:i:s', $unix ) : '';
    }
}
