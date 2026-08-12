<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Source_Background_Ajax_Exit extends Exception {}

/**
 * Browser-independent Source Center runner.
 *
 * The existing source checker / TOBB / JSON-LD / HTML batch endpoints remain
 * the source of truth. This worker invokes those endpoint callbacks inside a
 * protected background request, persists run state, and advances one batch per
 * tick. A non-blocking loopback keeps the run moving immediately; WP-Cron is a
 * durable fallback if the loopback chain is interrupted.
 */
class Sektorel_Event_Source_Background_Run {

    const VERSION          = 1;
    const NONCE_ACTION     = 'sektorel_source_background_run';
    const CRON_HOOK        = 'sektorel_source_background_tick';
    const RUN_PREFIX       = 'sektorel_source_run_';
    const LOCK_PREFIX      = 'sektorel_source_run_lock_';
    const ACTIVE_OPTION    = 'sektorel_source_active_run';
    const HISTORY_OPTION   = 'sektorel_source_run_history';
    const HISTORY_LIMIT    = 10;
    const STALE_SECONDS    = 1800;
    const FALLBACK_SECONDS = 60;
    const MAX_LOG_LINES    = 180;

    public static function init() {
        add_action( 'wp_ajax_sektorel_source_background_start', array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_sektorel_source_background_status', array( __CLASS__, 'ajax_status' ) );
        add_action( 'wp_ajax_sektorel_source_background_kick', array( __CLASS__, 'ajax_kick' ) );
        add_action( 'wp_ajax_nopriv_sektorel_source_background_kick', array( __CLASS__, 'ajax_kick' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'cron_tick' ), 10, 1 );

        if ( is_admin() ) {
            add_action( 'admin_init', array( __CLASS__, 'disable_legacy_reporting_footer' ), 999 );
            add_action( 'admin_footer', array( __CLASS__, 'render_source_center_script' ), 120 );
        }
    }

    public static function enabled() {
        return true;
    }

    public static function disable_legacy_reporting_footer() {
        if ( class_exists( 'Sektorel_Event_Source_Center_Reporting' ) ) {
            remove_action( 'admin_footer', array( 'Sektorel_Event_Source_Center_Reporting', 'render_footer_script' ), 99 );
        }
    }

    public static function ajax_start() {
        self::require_admin_ajax();

        $active_id = sanitize_key( (string) get_option( self::ACTIVE_OPTION, '' ) );
        if ( $active_id ) {
            $active = self::get_run( $active_id );
            if ( self::is_active_run( $active ) ) {
                if ( ! self::is_stale( $active ) ) {
                    self::kick( $active_id );
                    wp_send_json_success( array( 'run' => self::public_run( $active ), 'reused' => true ) );
                }

                $active['status']       = 'failed';
                $active['error']        = 'Run uzun süre güncellenmediği için stale kabul edildi.';
                $active['completed_at'] = current_time( 'mysql' );
                $active['updated_at']   = current_time( 'mysql' );
                self::append_log( $active, 'Önceki run stale olduğu için kapatıldı.' );
                self::save_run( $active );
            }
            delete_option( self::ACTIVE_OPTION );
        }

        $user_id = get_current_user_id();
        $run_id  = 'run_' . gmdate( 'Ymd_His' ) . '_' . strtolower( wp_generate_password( 8, false, false ) );
        $stages  = self::pipeline_stages();

        if ( ! $stages ) {
            wp_send_json_error( array( 'message' => 'Çalıştırılabilir kaynak aşaması bulunamadı.' ) );
        }

        $run = array(
            'version'          => self::VERSION,
            'id'               => $run_id,
            'secret'           => strtolower( wp_generate_password( 48, false, false ) ),
            'user_id'          => $user_id,
            'status'           => 'queued',
            'current_stage'    => 0,
            'started_at'       => current_time( 'mysql' ),
            'updated_at'       => current_time( 'mysql' ),
            'completed_at'     => '',
            'candidate_before' => self::candidate_ids(),
            'stages'           => array(),
            'logs'             => array(),
            'summary'          => array(),
            'error'            => '',
        );

        foreach ( $stages as $stage ) {
            $run['stages'][] = array(
                'key'             => $stage['key'],
                'label'           => $stage['label'],
                'description'     => $stage['description'],
                'prepare_action'  => $stage['prepare_action'],
                'batch_action'    => $stage['batch_action'],
                'nonce'           => $stage['nonce'],
                'prepare_payload' => $stage['prepare_payload'],
                'status'          => 'pending',
                'token'           => '',
                'total'           => 0,
                'offset'          => 0,
                'stats'           => self::empty_stats(),
                'message'         => '',
            );
        }

        self::append_log( $run, 'Background kaynak run kuyruğa alındı.' );
        self::save_run( $run );
        update_option( self::ACTIVE_OPTION, $run_id, false );
        self::remember_run( $run_id );
        self::schedule_fallback( $run_id );
        self::kick( $run_id );

        wp_send_json_success( array( 'run' => self::public_run( $run ), 'reused' => false ) );
    }

    public static function ajax_status() {
        self::require_admin_ajax();

        $run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
        if ( ! $run_id ) {
            $run_id = sanitize_key( (string) get_option( self::ACTIVE_OPTION, '' ) );
        }
        if ( ! $run_id ) {
            $history = get_option( self::HISTORY_OPTION, array() );
            if ( is_array( $history ) && ! empty( $history[0] ) ) {
                $run_id = sanitize_key( (string) $history[0] );
            }
        }

        if ( ! $run_id ) {
            wp_send_json_success( array( 'run' => null ) );
        }

        $run = self::get_run( $run_id );
        if ( ! $run ) {
            wp_send_json_success( array( 'run' => null ) );
        }

        if ( self::is_active_run( $run ) && self::is_stale( $run ) ) {
            self::kick( $run_id );
        }

        wp_send_json_success( array( 'run' => self::public_run( $run ) ) );
    }

    public static function ajax_kick() {
        $run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
        $secret = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
        $run    = $run_id ? self::get_run( $run_id ) : array();

        if ( ! $run || ! $secret || empty( $run['secret'] ) || ! hash_equals( (string) $run['secret'], $secret ) ) {
            status_header( 403 );
            wp_die( 'Forbidden' );
        }

        self::process_tick( $run_id );
        wp_die( 'OK' );
    }

    public static function cron_tick( $run_id ) {
        $run_id = sanitize_key( (string) $run_id );
        if ( $run_id ) {
            self::process_tick( $run_id );
        }
    }

    private static function process_tick( $run_id ) {
        if ( ! self::acquire_lock( $run_id ) ) {
            return;
        }

        try {
            $run = self::get_run( $run_id );
            if ( ! self::is_active_run( $run ) ) {
                self::clear_fallback( $run_id );
                return;
            }

            wp_set_current_user( absint( $run['user_id'] ) );
            $run['status']     = 'running';
            $run['updated_at'] = current_time( 'mysql' );

            $stage_index = absint( $run['current_stage'] );
            if ( $stage_index >= count( $run['stages'] ) ) {
                self::finalize_run( $run );
                return;
            }

            $stage = $run['stages'][ $stage_index ];

            if ( 'pending' === $stage['status'] ) {
                self::prepare_stage( $run, $stage_index );
            } elseif ( 'running' === $stage['status'] ) {
                self::process_stage_batch( $run, $stage_index );
            } else {
                $run['current_stage'] = $stage_index + 1;
                $run['updated_at']    = current_time( 'mysql' );
                self::save_run( $run );
            }
        } catch ( Throwable $e ) {
            $run = self::get_run( $run_id );
            if ( $run ) {
                $run['status']     = 'failed';
                $run['error']      = sanitize_text_field( $e->getMessage() );
                $run['updated_at'] = current_time( 'mysql' );
                $run['completed_at'] = current_time( 'mysql' );
                self::append_log( $run, 'Background worker fatal: ' . $e->getMessage() );
                self::save_run( $run );
                self::deactivate_run( $run_id );
            }
            self::clear_fallback( $run_id );
            return;
        } finally {
            self::release_lock( $run_id );
        }

        $run = self::get_run( $run_id );
        if ( self::is_active_run( $run ) ) {
            self::schedule_fallback( $run_id );
            self::kick( $run_id );
        }
    }

    private static function prepare_stage( &$run, $stage_index ) {
        $stage = $run['stages'][ $stage_index ];
        $run['stages'][ $stage_index ]['status'] = 'preparing';
        self::append_log( $run, $stage['label'] . ' hazırlanıyor…' );
        self::save_run( $run );

        $payload = array_merge(
            (array) $stage['prepare_payload'],
            array( 'nonce' => $stage['nonce'] )
        );
        $response = self::dispatch_ajax_callback( $stage['prepare_action'], $payload, absint( $run['user_id'] ) );

        if ( is_wp_error( $response ) || empty( $response['success'] ) ) {
            $message = is_wp_error( $response )
                ? $response->get_error_message()
                : self::response_message( $response, 'Hazırlık aşaması çalıştırılamadı.' );
            $run['stages'][ $stage_index ]['status']  = 'skipped';
            $run['stages'][ $stage_index ]['message'] = sanitize_text_field( $message );
            self::append_log( $run, $stage['label'] . ' atlandı: ' . $message );
            $run['current_stage'] = $stage_index + 1;
            $run['updated_at']    = current_time( 'mysql' );
            self::save_run( $run );
            return;
        }

        $data  = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
        $token = isset( $data['token'] ) ? sanitize_key( (string) $data['token'] ) : '';
        $total = isset( $data['total'] ) ? absint( $data['total'] ) : 0;

        if ( ! $token || $total < 1 ) {
            $run['stages'][ $stage_index ]['status']  = 'skipped';
            $run['stages'][ $stage_index ]['message'] = 'İşlenecek kayıt yok.';
            self::append_log( $run, $stage['label'] . ': işlenecek kayıt yok.' );
            $run['current_stage'] = $stage_index + 1;
            $run['updated_at']    = current_time( 'mysql' );
            self::save_run( $run );
            return;
        }

        $run['stages'][ $stage_index ]['status'] = 'running';
        $run['stages'][ $stage_index ]['token']  = $token;
        $run['stages'][ $stage_index ]['total']  = $total;
        $run['stages'][ $stage_index ]['offset'] = 0;
        $run['updated_at'] = current_time( 'mysql' );
        self::append_log( $run, $stage['label'] . ': ' . $total . ' kayıt kuyruğa alındı.' );
        self::save_run( $run );
    }

    private static function process_stage_batch( &$run, $stage_index ) {
        $stage = $run['stages'][ $stage_index ];
        $payload = array(
            'nonce'  => $stage['nonce'],
            'token'  => $stage['token'],
            'offset' => absint( $stage['offset'] ),
        );

        $response = self::dispatch_ajax_callback( $stage['batch_action'], $payload, absint( $run['user_id'] ) );
        if ( is_wp_error( $response ) || empty( $response['success'] ) ) {
            $message = is_wp_error( $response )
                ? $response->get_error_message()
                : self::response_message( $response, 'Batch çalıştırılamadı.' );
            $run['stages'][ $stage_index ]['status']  = 'failed';
            $run['stages'][ $stage_index ]['message'] = sanitize_text_field( $message );
            self::append_log( $run, $stage['label'] . ' hata: ' . $message );
            $run['current_stage'] = $stage_index + 1;
            $run['updated_at']    = current_time( 'mysql' );
            self::save_run( $run );
            return;
        }

        $data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
        foreach ( array_keys( self::empty_stats() ) as $key ) {
            if ( isset( $data[ $key ] ) ) {
                $run['stages'][ $stage_index ]['stats'][ $key ] += absint( $data[ $key ] );
            }
        }

        if ( ! empty( $data['messages'] ) && is_array( $data['messages'] ) ) {
            foreach ( array_slice( $data['messages'], 0, 30 ) as $message ) {
                self::append_log( $run, $stage['label'] . ': ' . sanitize_text_field( (string) $message ) );
            }
        }

        $next = isset( $data['next_offset'] ) ? absint( $data['next_offset'] ) : absint( $stage['total'] );
        $run['stages'][ $stage_index ]['offset'] = min( absint( $stage['total'] ), $next );
        $run['updated_at'] = current_time( 'mysql' );

        if ( ! empty( $data['done'] ) ) {
            $run['stages'][ $stage_index ]['status'] = 'completed';
            $run['stages'][ $stage_index ]['offset'] = absint( $stage['total'] );
            self::append_log( $run, $stage['label'] . ' tamamlandı.' );
            $run['current_stage'] = $stage_index + 1;
        }

        self::save_run( $run );
    }

    private static function finalize_run( &$run ) {
        $run['summary']      = self::build_summary( $run );
        $run['status']       = 'completed';
        $run['updated_at']   = current_time( 'mysql' );
        $run['completed_at'] = current_time( 'mysql' );
        self::append_log( $run, 'Tüm background kaynak aşamaları tamamlandı.' );
        self::save_run( $run );
        self::deactivate_run( $run['id'] );
        self::clear_fallback( $run['id'] );
    }

    private static function build_summary( $run ) {
        $before = isset( $run['candidate_before'] ) && is_array( $run['candidate_before'] )
            ? array_values( array_unique( array_map( 'absint', $run['candidate_before'] ) ) )
            : array();
        $after   = self::candidate_ids();
        $new_ids = array_values( array_diff( $after, $before ) );

        $new_reviewable = 0;
        $new_ignored    = 0;
        $new_imported   = 0;
        if ( $new_ids ) {
            update_meta_cache( 'post', $new_ids );
            foreach ( $new_ids as $candidate_id ) {
                $status = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
                if ( in_array( $status, array( 'ignored', 'rejected' ), true ) ) {
                    $new_ignored++;
                } elseif ( 'imported' === $status ) {
                    $new_imported++;
                } else {
                    $new_reviewable++;
                }
            }
        }

        $updated = 0;
        $quiet   = 0;
        $parser_errors = 0;
        foreach ( (array) $run['stages'] as $stage ) {
            if ( 'source_check' === $stage['key'] ) {
                continue;
            }
            $stats = isset( $stage['stats'] ) && is_array( $stage['stats'] ) ? $stage['stats'] : array();
            $updated += absint( isset( $stats['updated'] ) ? $stats['updated'] : 0 );
            $quiet += absint( isset( $stats['unchanged'] ) ? $stats['unchanged'] : 0 );
            $quiet += absint( isset( $stats['skipped'] ) ? $stats['skipped'] : 0 );
            $parser_errors += absint( isset( $stats['error'] ) ? $stats['error'] : 0 );
            $parser_errors += absint( isset( $stats['failed'] ) ? $stats['failed'] : 0 );
            if ( 'failed' === $stage['status'] ) {
                $parser_errors++;
            }
        }

        $source = self::source_check_stats();

        return array(
            'new_total'          => count( $new_ids ),
            'new_reviewable'     => $new_reviewable,
            'new_ignored'        => $new_ignored,
            'new_imported'       => $new_imported,
            'updated'            => $updated,
            'unchanged_skipped'  => $quiet,
            'parser_errors'      => $parser_errors,
            'source_ok'          => $source['ok'],
            'source_issues'      => $source['issues'],
            'source_skipped'     => $source['skipped'],
            'source_issue_types' => $source['types'],
        );
    }

    private static function source_check_stats() {
        $ids = get_posts(
            array(
                'post_type'      => 'event_source',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'   => 'source_status',
                        'value' => 'active',
                    ),
                ),
            )
        );

        $stats = array(
            'ok'      => 0,
            'issues'  => 0,
            'skipped' => 0,
            'types'   => array(
                'unsafe'     => 0,
                'ssl_tls'    => 0,
                'forbidden'  => 0,
                'timeout'    => 0,
                'http'       => 0,
                'connection' => 0,
                'other'      => 0,
            ),
        );

        if ( ! $ids ) {
            return $stats;
        }

        update_meta_cache( 'post', $ids );
        foreach ( $ids as $source_id ) {
            $state = sanitize_key( (string) get_post_meta( $source_id, 'check_state', true ) );
            if ( 'ok' === $state ) {
                $stats['ok']++;
                continue;
            }
            if ( 'skipped' === $state ) {
                $stats['skipped']++;
            }
            if ( in_array( $state, array( 'error', 'skipped' ), true ) ) {
                $stats['issues']++;
                $type = self::issue_type( (string) get_post_meta( $source_id, 'last_error', true ) );
                $stats['types'][ $type ]++;
            }
        }
        return $stats;
    }

    private static function issue_type( $message ) {
        $message = strtolower( remove_accents( (string) $message ) );
        if ( false !== strpos( $message, 'private ag' ) || false !== strpos( $message, 'guvenli olmayan' ) ) return 'unsafe';
        if ( false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'tls' ) || false !== strpos( $message, 'certificate' ) ) return 'ssl_tls';
        if ( false !== strpos( $message, 'http 403' ) ) return 'forbidden';
        if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) return 'timeout';
        if ( preg_match( '/http\s+\d{3}/', $message ) ) return 'http';
        if ( false !== strpos( $message, 'connect' ) || false !== strpos( $message, 'connection' ) || false !== strpos( $message, 'curl error 7' ) ) return 'connection';
        return 'other';
    }

    private static function dispatch_ajax_callback( $action, $payload, $user_id ) {
        $map = self::action_map();
        if ( empty( $map[ $action ] ) || ! is_callable( $map[ $action ] ) ) {
            return new WP_Error( 'background_action_missing', 'Background action callback bulunamadı: ' . $action );
        }

        $old_post    = $_POST;
        $old_get     = $_GET;
        $old_request = $_REQUEST;
        $old_user_id = get_current_user_id();
        $handler_filter = 'wp_die_ajax_handler';

        if ( ! defined( 'DOING_AJAX' ) ) {
            define( 'DOING_AJAX', true );
        }

        wp_set_current_user( $user_id );
        $_POST = array_merge( (array) $payload, array( 'action' => $action ) );
        $_GET = array();
        $_REQUEST = $_POST;

        add_filter( $handler_filter, array( __CLASS__, 'ajax_die_handler' ), 999 );
        ob_start();
        $throwable = null;
        try {
            call_user_func( $map[ $action ] );
        } catch ( Sektorel_Source_Background_Ajax_Exit $e ) {
            // Expected: wp_send_json_* terminates through wp_die in AJAX mode.
        } catch ( Throwable $e ) {
            $throwable = $e;
        }
        $output = ob_get_clean();
        remove_filter( $handler_filter, array( __CLASS__, 'ajax_die_handler' ), 999 );

        $_POST = $old_post;
        $_GET = $old_get;
        $_REQUEST = $old_request;
        wp_set_current_user( $old_user_id );

        if ( $throwable ) {
            return new WP_Error( 'background_callback_exception', $throwable->getMessage() );
        }

        $decoded = json_decode( trim( (string) $output ), true );
        if ( ! is_array( $decoded ) ) {
            $position = strrpos( (string) $output, '{"success"' );
            if ( false !== $position ) {
                $decoded = json_decode( substr( (string) $output, $position ), true );
            }
        }

        if ( ! is_array( $decoded ) || ! array_key_exists( 'success', $decoded ) ) {
            return new WP_Error( 'background_invalid_json', 'Background callback geçerli JSON yanıtı üretmedi.' );
        }

        return $decoded;
    }

    public static function ajax_die_handler() {
        return array( __CLASS__, 'throw_ajax_exit' );
    }

    public static function throw_ajax_exit( $message = '', $title = '', $args = array() ) {
        throw new Sektorel_Source_Background_Ajax_Exit( is_scalar( $message ) ? (string) $message : '' );
    }

    private static function action_map() {
        $map = array(
            'sektorel_event_source_prepare_checks' => array( 'Sektorel_Event_Source_Checker', 'ajax_prepare_checks' ),
            'sektorel_event_source_check_batch'    => array( 'Sektorel_Event_Source_Checker', 'ajax_check_batch' ),
            'sektorel_tobb_prepare'                => array( 'Sektorel_Event_Source_TOBB', 'ajax_prepare' ),
            'sektorel_tobb_import_batch'           => array( 'Sektorel_Event_Source_TOBB', 'ajax_import_batch' ),
            'sektorel_prepare_jsonld_scan'         => array( 'Sektorel_Event_Candidate_JSONLD', 'ajax_prepare_scan' ),
            'sektorel_jsonld_scan_batch'           => array( 'Sektorel_Event_Candidate_JSONLD', 'ajax_scan_batch' ),
            'sektorel_prepare_html_event_scan'     => array( 'Sektorel_Event_Candidate_HTML', 'ajax_prepare_scan' ),
            'sektorel_html_event_scan_batch'       => array( 'Sektorel_Event_Candidate_HTML', 'ajax_scan_batch' ),
        );
        return apply_filters( 'sektorel_source_background_action_map', $map );
    }

    private static function pipeline_stages() {
        $year = (int) current_time( 'Y' );
        $stages = array(
            array(
                'key'             => 'source_check',
                'label'           => 'Kaynakları Doğrula',
                'description'     => 'Aktif kaynakların erişilebilirlik ve parser sinyalini kontrol eder.',
                'prepare_action'  => 'sektorel_event_source_prepare_checks',
                'batch_action'    => 'sektorel_event_source_check_batch',
                'nonce'           => wp_create_nonce( 'sektorel_event_source_check' ),
                'prepare_payload' => array(),
            ),
            array(
                'key'             => 'tobb',
                'label'           => 'TOBB Fuar Takvimi',
                'description'     => 'Canonical fuar occurrence kayıtlarını aday havuzuna günceller.',
                'prepare_action'  => 'sektorel_tobb_prepare',
                'batch_action'    => 'sektorel_tobb_import_batch',
                'nonce'           => wp_create_nonce( 'sektorel_tobb_fair_calendar' ),
                'prepare_payload' => array( 'year' => $year, 'upcoming_only' => 1 ),
            ),
            array(
                'key'             => 'jsonld',
                'label'           => 'JSON-LD Kaynakları',
                'description'     => 'Adapter olmayan yapılandırılmış Event verilerini adaylara işler.',
                'prepare_action'  => 'sektorel_prepare_jsonld_scan',
                'batch_action'    => 'sektorel_jsonld_scan_batch',
                'nonce'           => wp_create_nonce( 'sektorel_event_candidate_jsonld' ),
                'prepare_payload' => array(),
            ),
            array(
                'key'             => 'html',
                'label'           => 'HTML Kaynakları',
                'description'     => 'Güvenli generic HTML kaynaklarında aday etkinlik keşfi yapar.',
                'prepare_action'  => 'sektorel_prepare_html_event_scan',
                'batch_action'    => 'sektorel_html_event_scan_batch',
                'nonce'           => wp_create_nonce( 'sektorel_event_candidate_html' ),
                'prepare_payload' => array(),
            ),
        );

        $stages = apply_filters( 'sektorel_source_center_stages', $stages );
        $clean = array();
        foreach ( (array) $stages as $stage ) {
            if ( empty( $stage['key'] ) || empty( $stage['label'] ) || empty( $stage['prepare_action'] ) || empty( $stage['batch_action'] ) || empty( $stage['nonce'] ) ) {
                continue;
            }
            $clean[] = array(
                'key'             => sanitize_key( $stage['key'] ),
                'label'           => sanitize_text_field( $stage['label'] ),
                'description'     => isset( $stage['description'] ) ? sanitize_text_field( $stage['description'] ) : '',
                'prepare_action'  => sanitize_key( $stage['prepare_action'] ),
                'batch_action'    => sanitize_key( $stage['batch_action'] ),
                'nonce'           => sanitize_text_field( $stage['nonce'] ),
                'prepare_payload' => isset( $stage['prepare_payload'] ) && is_array( $stage['prepare_payload'] ) ? $stage['prepare_payload'] : array(),
            );
        }
        return $clean;
    }

    private static function render_stage_result( $stage ) {
        $parts = array();
        if ( ! empty( $stage['total'] ) ) {
            $parts[] = 'Kayıt: ' . absint( $stage['total'] );
        }
        $labels = array(
            'created'   => 'Yeni',
            'updated'   => 'Güncel',
            'unchanged' => 'Değişmedi',
            'changed'   => 'Değişti',
            'ok'        => 'OK',
            'skipped'   => 'Atlandı',
            'error'     => 'Hata',
            'failed'    => 'Başarısız',
        );
        foreach ( $labels as $key => $label ) {
            $value = isset( $stage['stats'][ $key ] ) ? absint( $stage['stats'][ $key ] ) : 0;
            if ( $value ) {
                $parts[] = $label . ': ' . $value;
            }
        }
        if ( ! $parts && ! empty( $stage['message'] ) ) {
            $parts[] = $stage['message'];
        }
        return $parts ? implode( ' · ', $parts ) : '—';
    }

    private static function public_run( $run ) {
        if ( ! $run ) return null;
        $stages = array();
        foreach ( (array) $run['stages'] as $stage ) {
            $stages[] = array(
                'key'         => $stage['key'],
                'label'       => $stage['label'],
                'description' => $stage['description'],
                'status'      => $stage['status'],
                'total'       => absint( $stage['total'] ),
                'offset'      => absint( $stage['offset'] ),
                'stats'       => $stage['stats'],
                'message'     => $stage['message'],
                'result'      => self::render_stage_result( $stage ),
            );
        }
        return array(
            'id'            => $run['id'],
            'status'        => $run['status'],
            'started_at'    => $run['started_at'],
            'updated_at'    => $run['updated_at'],
            'completed_at'  => $run['completed_at'],
            'current_stage' => absint( $run['current_stage'] ),
            'stages'        => $stages,
            'logs'          => array_values( array_slice( (array) $run['logs'], -80 ) ),
            'summary'       => isset( $run['summary'] ) ? $run['summary'] : array(),
            'error'         => isset( $run['error'] ) ? $run['error'] : '',
        );
    }

    private static function render_source_center_script() {
        if ( ! self::is_source_center_page() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <script>
        jQuery(function($){
            var bgNonce='<?php echo esc_js( $nonce ); ?>';
            var runId='';
            var polling=false;
            var replay=false;

            function esc(text){return $('<div>').text(String(text||'')).html();}
            function terminal(status){return status==='completed'||status==='failed'||status==='cancelled';}
            function stageClass(status){if(status==='completed')return 'done';if(status==='failed')return 'failed';if(status==='skipped')return 'skipped';if(status==='running'||status==='preparing')return 'running';return '';}
            function stageLabel(status){var labels={pending:'Bekliyor',preparing:'Hazırlanıyor',running:'Çalışıyor',completed:'Tamamlandı',failed:'Hata',skipped:'Atlandı'};return labels[status]||status;}
            function issueBreakdown(types){types=types||{};var labels={unsafe:'Güvenlik/private',ssl_tls:'SSL/TLS',forbidden:'HTTP 403',timeout:'Timeout',http:'Diğer HTTP',connection:'Bağlantı',other:'Diğer'},parts=[];Object.keys(labels).forEach(function(k){var v=Number(types[k]||0);if(v)parts.push(labels[k]+': <strong>'+v+'</strong>');});return parts.join(' &nbsp; ');}
            function progress(run){var stages=run.stages||[],score=0,total=stages.length||1;stages.forEach(function(s){if(s.status==='completed'||s.status==='failed'||s.status==='skipped')score+=1;else if((s.status==='running'||s.status==='preparing')&&Number(s.total||0)>0)score+=Math.min(1,Number(s.offset||0)/Number(s.total||1));});return Math.min(100,Math.round((score/total)*100));}
            function renderSummary(run){var s=run.summary||{};if(run.status!=='completed'){if(run.status==='failed')$('#ssc-summary').show().html('<strong>Background run durdu.</strong><br>'+esc(run.error||'Bilinmeyen hata.'));return;}var breakdown=issueBreakdown(s.source_issue_types);var html='<strong>Kaynak taraması tamamlandı.</strong><br>'+'Yeni inceleme adayı: <strong>'+Number(s.new_reviewable||0)+'</strong> &nbsp; '+'Ignored / gürültü: <strong>'+Number(s.new_ignored||0)+'</strong> &nbsp; '+'Güncellendi: <strong>'+Number(s.updated||0)+'</strong> &nbsp; '+'Değişmedi / atlandı: <strong>'+Number(s.unchanged_skipped||0)+'</strong><br>'+'Erişilebilir kaynak: <strong>'+Number(s.source_ok||0)+'</strong> &nbsp; '+'Kaynak erişim sorunu: <strong>'+Number(s.source_issues||0)+'</strong> &nbsp; '+'Parser / tarama hatası: <strong>'+Number(s.parser_errors||0)+'</strong>';if(breakdown)html+='<br><span style="color:#646970;">Kaynak sorun dağılımı: '+breakdown+'</span>';html+='<br><br><a class="button" href="edit.php?post_type=event_candidate">Aday Etkinlikleri Gör</a>';$('#ssc-summary').show().html(html);}
            function render(run){if(!run)return;runId=run.id||runId;(run.stages||[]).forEach(function(s){var $row=$('.ssc-stage[data-stage="'+s.key+'"]');$row.find('.ssc-status').removeClass('running done failed skipped').addClass(stageClass(s.status)).text(stageLabel(s.status));$row.find('.ssc-result').text(s.result||'—');});var pct=progress(run);$('#ssc-progress').show();$('#ssc-progress-bar').css('width',pct+'%').css('background',run.status==='completed'?'#00a32a':'#2271b1');var logs=run.logs||[];if(logs.length){$('#ssc-log').show().html(logs.map(function(line){return '<div>'+esc(line)+'</div>';}).join(''));var el=$('#ssc-log')[0];if(el)el.scrollTop=el.scrollHeight;}if(terminal(run.status)){polling=false;$('#ssc-start').prop('disabled',false).text('Tüm Kaynakları Yeniden Tara');renderSummary(run);}else{$('#ssc-start').prop('disabled',true).text('Arka Planda Taranıyor…');$('#ssc-summary').show().html('<strong>Tarama arka planda devam ediyor.</strong> Bu sayfadan ayrılabilirsin; geri döndüğünde ilerleme burada görünür.');}}
            function poll(){if(polling)return;polling=true;function tick(){$.post(ajaxurl,{action:'sektorel_source_background_status',nonce:bgNonce,run_id:runId}).done(function(r){if(r&&r.success&&r.data&&r.data.run){render(r.data.run);if(!terminal(r.data.run.status)){window.setTimeout(tick,2500);}else{polling=false;}}else{polling=false;}}).fail(function(){polling=false;window.setTimeout(function(){poll();},5000);});}tick();}
            function start(){if(replay)return;$('#ssc-start').prop('disabled',true).text('Background Run Başlatılıyor…');$('#ssc-summary').hide().empty();$.post(ajaxurl,{action:'sektorel_source_background_start',nonce:bgNonce}).done(function(r){if(!r||!r.success||!r.data||!r.data.run){$('#ssc-start').prop('disabled',false).text('Tekrar Dene');$('#ssc-summary').show().text(r&&r.data&&r.data.message?r.data.message:'Background run başlatılamadı.');return;}render(r.data.run);poll();}).fail(function(){$('#ssc-start').prop('disabled',false).text('Tekrar Dene');$('#ssc-summary').show().text('Background run başlatma isteği başarısız.');});}

            document.addEventListener('click',function(event){var target=event.target&&event.target.closest?event.target.closest('#ssc-start'):null;if(!target||replay||target.disabled)return;event.preventDefault();event.stopPropagation();if(event.stopImmediatePropagation)event.stopImmediatePropagation();start();},true);

            var $intro=$('.ssc-main > p').first();if($intro.length)$intro.text('Tek buton tüm kaynak aşamalarını backend kuyruğunda sırayla çalıştırır. Tarama başladıktan sonra bu sekmeyi kapatabilir veya başka bir sayfaya geçebilirsin.');

            $.post(ajaxurl,{action:'sektorel_source_background_status',nonce:bgNonce}).done(function(r){if(r&&r.success&&r.data&&r.data.run){render(r.data.run);if(!terminal(r.data.run.status))poll();}});
        });
        </script>
        <?php
    }

    private static function schedule_fallback( $run_id ) {
        if ( ! wp_next_scheduled( self::CRON_HOOK, array( $run_id ) ) ) {
            wp_schedule_single_event( time() + self::FALLBACK_SECONDS, self::CRON_HOOK, array( $run_id ) );
        }
    }

    private static function clear_fallback( $run_id ) {
        wp_clear_scheduled_hook( self::CRON_HOOK, array( $run_id ) );
    }

    private static function kick( $run_id ) {
        $run = self::get_run( $run_id );
        if ( ! self::is_active_run( $run ) ) return;
        wp_remote_post(
            admin_url( 'admin-ajax.php' ),
            array(
                'timeout'   => 0.1,
                'blocking'  => false,
                'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
                'body'      => array(
                    'action' => 'sektorel_source_background_kick',
                    'run_id' => $run_id,
                    'secret' => $run['secret'],
                ),
            )
        );
    }

    private static function acquire_lock( $run_id ) {
        $key = self::LOCK_PREFIX . sanitize_key( $run_id );
        if ( add_option( $key, time(), '', false ) ) return true;
        $started = absint( get_option( $key, 0 ) );
        if ( $started && ( time() - $started ) > 120 ) {
            delete_option( $key );
            return add_option( $key, time(), '', false );
        }
        return false;
    }

    private static function release_lock( $run_id ) {
        delete_option( self::LOCK_PREFIX . sanitize_key( $run_id ) );
    }

    private static function save_run( $run ) {
        if ( empty( $run['id'] ) ) return;
        update_option( self::RUN_PREFIX . sanitize_key( $run['id'] ), $run, false );
    }

    private static function get_run( $run_id ) {
        $run = get_option( self::RUN_PREFIX . sanitize_key( $run_id ), array() );
        return is_array( $run ) ? $run : array();
    }

    private static function remember_run( $run_id ) {
        $history = get_option( self::HISTORY_OPTION, array() );
        $history = is_array( $history ) ? array_values( array_filter( array_map( 'sanitize_key', $history ) ) ) : array();
        $history = array_values( array_diff( $history, array( $run_id ) ) );
        array_unshift( $history, $run_id );
        if ( count( $history ) > self::HISTORY_LIMIT ) {
            $remove = array_slice( $history, self::HISTORY_LIMIT );
            $history = array_slice( $history, 0, self::HISTORY_LIMIT );
            foreach ( $remove as $old_id ) {
                delete_option( self::RUN_PREFIX . sanitize_key( $old_id ) );
                delete_option( self::LOCK_PREFIX . sanitize_key( $old_id ) );
            }
        }
        update_option( self::HISTORY_OPTION, $history, false );
    }

    private static function deactivate_run( $run_id ) {
        if ( sanitize_key( (string) get_option( self::ACTIVE_OPTION, '' ) ) === sanitize_key( $run_id ) ) {
            delete_option( self::ACTIVE_OPTION );
        }
    }

    private static function is_active_run( $run ) {
        return is_array( $run ) && ! empty( $run['id'] ) && in_array( isset( $run['status'] ) ? $run['status'] : '', array( 'queued', 'running' ), true );
    }

    private static function is_stale( $run ) {
        if ( empty( $run['updated_at'] ) ) return false;
        $timestamp = strtotime( $run['updated_at'] );
        return $timestamp && ( current_time( 'timestamp' ) - $timestamp ) > self::STALE_SECONDS;
    }

    private static function append_log( &$run, $message ) {
        $run['logs'][] = current_time( 'H:i:s' ) . ' — ' . sanitize_text_field( (string) $message );
        if ( count( $run['logs'] ) > self::MAX_LOG_LINES ) {
            $run['logs'] = array_slice( $run['logs'], -self::MAX_LOG_LINES );
        }
    }

    private static function empty_stats() {
        return array(
            'created'   => 0,
            'updated'   => 0,
            'unchanged' => 0,
            'skipped'   => 0,
            'error'     => 0,
            'failed'    => 0,
            'ok'        => 0,
            'changed'   => 0,
        );
    }

    private static function response_message( $response, $fallback ) {
        if ( isset( $response['data']['message'] ) ) return sanitize_text_field( (string) $response['data']['message'] );
        if ( isset( $response['data'] ) && is_string( $response['data'] ) ) return sanitize_text_field( $response['data'] );
        return $fallback;
    }

    private static function candidate_ids() {
        return array_values(
            array_map(
                'absint',
                get_posts(
                    array(
                        'post_type'      => 'event_candidate',
                        'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'orderby'        => 'ID',
                        'order'          => 'ASC',
                        'no_found_rows'  => true,
                    )
                )
            )
        );
    }

    private static function require_admin_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function is_source_center_page() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return 'event' === $post_type && 'sektorel-source-center' === $page;
    }
}
