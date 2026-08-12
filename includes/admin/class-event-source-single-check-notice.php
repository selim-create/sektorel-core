<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Makes the existing single-source check flow visible to administrators.
 *
 * The checker already performs the request and redirects back to the source
 * list. This class only decorates that redirect with the checked source ID and
 * renders the stored health result as a WordPress admin notice. No remote
 * request or SSRF policy is changed here.
 */
class Sektorel_Event_Source_Single_Check_Notice {

    public static function init() {
        add_filter( 'wp_redirect', array( __CLASS__, 'decorate_redirect' ), 20, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
    }

    public static function decorate_redirect( $location, $status ) {
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( 'sektorel_check_event_source' !== $action ) {
            return $location;
        }

        $source_id = isset( $_REQUEST['source_id'] ) ? absint( $_REQUEST['source_id'] ) : 0;
        if ( ! $source_id || 'event_source' !== get_post_type( $source_id ) ) {
            return $location;
        }

        $state = (string) get_post_meta( $source_id, 'check_state', true );
        if ( ! in_array( $state, array( 'ok', 'error', 'skipped' ), true ) ) {
            $state = isset( $_GET['sektorel_check'] ) ? sanitize_key( wp_unslash( $_GET['sektorel_check'] ) ) : '';
        }

        $args = array(
            'sektorel_source_id' => $source_id,
        );
        if ( in_array( $state, array( 'ok', 'error', 'skipped' ), true ) ) {
            $args['sektorel_check'] = $state;
        }

        return add_query_arg( $args, $location );
    }

    public static function render_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'event_source' !== $screen->post_type || 'edit' !== $screen->base ) {
            return;
        }

        $status = isset( $_GET['sektorel_check'] ) ? sanitize_key( wp_unslash( $_GET['sektorel_check'] ) ) : '';
        if ( ! $status ) {
            return;
        }

        if ( 'invalid' === $status ) {
            self::notice( 'error', 'Kaynak kontrolü yapılamadı: geçersiz kaynak kaydı.' );
            return;
        }

        $source_id = isset( $_GET['sektorel_source_id'] ) ? absint( $_GET['sektorel_source_id'] ) : 0;
        if ( ! $source_id || 'event_source' !== get_post_type( $source_id ) ) {
            self::notice( 'info', 'Kaynak kontrolü tamamlandı. Sonuç için Kontrol sütununu inceleyebilirsiniz.' );
            return;
        }

        $title       = get_the_title( $source_id );
        $state       = (string) get_post_meta( $source_id, 'check_state', true );
        $http_status = absint( get_post_meta( $source_id, 'last_http_status', true ) );
        $parser      = sanitize_key( (string) get_post_meta( $source_id, 'detected_parser', true ) );
        $error       = sanitize_text_field( (string) get_post_meta( $source_id, 'last_error', true ) );

        if ( 'ok' === $state ) {
            $parts = array( 'Erişilebilir' );
            if ( $http_status ) {
                $parts[] = 'HTTP ' . $http_status;
            }
            if ( $parser ) {
                $parts[] = strtoupper( $parser );
            }
            self::notice( 'success', '<strong>' . esc_html( $title ) . '</strong> kontrol edildi: ' . esc_html( implode( ' / ', $parts ) ) . '.' );
            return;
        }

        if ( 'skipped' === $state ) {
            $message = $error ? $error : 'Kaynak kontrolü atlandı.';
            self::notice( 'warning', '<strong>' . esc_html( $title ) . '</strong>: ' . esc_html( $message ) );
            return;
        }

        $message = $error ? $error : 'Kaynak kontrolü başarısız oldu.';
        self::notice( 'error', '<strong>' . esc_html( $title ) . '</strong>: ' . esc_html( $message ) );
    }

    private static function notice( $type, $message ) {
        $allowed = array( 'success', 'error', 'warning', 'info' );
        if ( ! in_array( $type, $allowed, true ) ) {
            $type = 'info';
        }
        echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses( $message, array( 'strong' => array() ) ) . '</p></div>';
    }
}

// Keep Source Center reporting / background orchestration isolated from the
// source checker itself while loading them on every WordPress request. The
// background runner must register its WP-Cron hook even outside wp-admin.
require_once __DIR__ . '/class-event-source-center-reporting.php';
Sektorel_Event_Source_Center_Reporting::init();

require_once __DIR__ . '/class-event-source-background-run.php';
Sektorel_Event_Source_Background_Run::init();

require_once __DIR__ . '/class-event-source-background-run-callback-fix.php';
Sektorel_Event_Source_Background_Run_Callback_Fix::init();

require_once __DIR__ . '/class-event-source-background-nonce-compat.php';
Sektorel_Event_Source_Background_Nonce_Compat::init();

// Enrichment adapters must also be loaded on WP-Cron/loopback requests so
// their pipeline and nonce/action-map filters are available to the worker.
require_once __DIR__ . '/class-event-source-ifm.php';
Sektorel_Event_Source_IFM::init();

require_once __DIR__ . '/class-event-source-tuyap.php';
Sektorel_Event_Source_Tuyap::init();

require_once __DIR__ . '/class-event-source-tuyap-conflict-review.php';
Sektorel_Event_Source_Tuyap_Conflict_Review::init();

// Canonical draft conversion must be available on both loopback and WP-Cron
// ticks so canonical candidates can become draft events before enrichment.
require_once __DIR__ . '/class-event-canonical-draft-stage.php';
Sektorel_Event_Canonical_Draft_Stage::init();

// Candidate records stay in storage for provenance, but the daily admin UI
// should behave like a review inbox rather than a historical candidate dump.
require_once __DIR__ . '/class-event-candidate-inbox.php';
Sektorel_Event_Candidate_Inbox::init();
