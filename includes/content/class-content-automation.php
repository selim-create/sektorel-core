<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Safe cron orchestrator for the Content Engine.
 *
 * Pipeline per tick:
 * 1) scan a bounded number of due, enabled content sources,
 * 2) deterministically triage new candidates,
 * 3) create a bounded number of AI drafts from contract-valid ready candidates.
 *
 * The runner never publishes posts automatically. It only creates WordPress drafts.
 */
class Sektorel_Content_Automation {

    const CRON_HOOK = 'sektorel_content_automation_tick';
    const CRON_SCHEDULE = 'sektorel_every_15_minutes';
    const ENABLED_OPTION = 'sektorel_content_automation_enabled';
    const LAST_RUN_OPTION = 'sektorel_content_automation_last_run';
    const LOCK_KEY = 'sektorel_content_automation_lock';

    const SCAN_SOURCES_PER_TICK = 2;
    const TRIAGE_ITEMS_PER_TICK = 25;
    const AI_ITEMS_PER_TICK = 2;
    const LOCK_TTL = 10 * MINUTE_IN_SECONDS;

    public static function init() {
        add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
        add_action( 'init', array( __CLASS__, 'ensure_cron' ), 20 );
        add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
    }

    public static function cron_schedules( $schedules ) {
        if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
            $schedules[ self::CRON_SCHEDULE ] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => 'Sektörel Ajanda — 15 dakika',
            );
        }
        return $schedules;
    }

    public static function ensure_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 300, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    public static function enabled() {
        return '1' === (string) get_option( self::ENABLED_OPTION, '0' );
    }

    public static function run() {
        if ( ! self::enabled() ) {
            return;
        }

        if ( get_transient( self::LOCK_KEY ) ) {
            return;
        }

        set_transient( self::LOCK_KEY, (string) time(), self::LOCK_TTL );
        $started_at = gmdate( 'c' );

        try {
            self::bootstrap_pipeline();

            $scan = self::scan_due_sources( self::SCAN_SOURCES_PER_TICK );
            $triage = Sektorel_Content_Candidate_Triage::process_new_batch( self::TRIAGE_ITEMS_PER_TICK );
            $ai = Sektorel_Content_AI_Draft_Processor::process_ready_batch( self::AI_ITEMS_PER_TICK );

            $summary = array(
                'status'      => 'completed',
                'started_at'  => $started_at,
                'finished_at' => gmdate( 'c' ),
                'scan'        => $scan,
                'triage'      => is_wp_error( $triage ) ? self::error_payload( $triage ) : $triage,
                'ai'          => is_wp_error( $ai ) ? self::error_payload( $ai ) : $ai,
            );
        } catch ( Throwable $e ) {
            $summary = array(
                'status'      => 'error',
                'started_at'  => $started_at,
                'finished_at' => gmdate( 'c' ),
                'error'       => sanitize_text_field( $e->getMessage() ),
            );
        } finally {
            delete_transient( self::LOCK_KEY );
        }

        update_option( self::LAST_RUN_OPTION, $summary, false );
    }

    private static function bootstrap_pipeline() {
        require_once SEKTOREL_CORE_PATH . 'includes/core/class-core-settings.php';
        Sektorel_Core_Settings::define_runtime_credentials();

        require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-source-scanner.php';
        require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-source-custom-bridge.php';
        require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-candidate-triage.php';
        require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-source-tobb-detail-date.php';
        require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-detail-extractor.php';
        require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-ai-draft-processor.php';
    }

    private static function scan_due_sources( $limit ) {
        $limit = max( 1, absint( $limit ) );
        $ids = get_posts( array(
            'post_type'      => 'content_source',
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'   => 'enabled',
                    'value' => '1',
                ),
            ),
        ) );

        $due = array();
        $now = time();

        foreach ( (array) $ids as $source_id ) {
            $source_id = absint( $source_id );
            $interval = sanitize_key( (string) get_post_meta( $source_id, 'scan_interval', true ) );
            $seconds = self::scan_interval_seconds( $interval );
            if ( ! $seconds ) {
                continue;
            }

            $last_scan = trim( (string) get_post_meta( $source_id, 'last_scan', true ) );
            $last_timestamp = $last_scan ? strtotime( $last_scan . ' UTC' ) : false;
            if ( false !== $last_timestamp && ( $now - $last_timestamp ) < $seconds ) {
                continue;
            }

            $due[] = array(
                'source_id' => $source_id,
                'last_scan' => false === $last_timestamp ? 0 : (int) $last_timestamp,
            );
        }

        usort( $due, static function ( $a, $b ) {
            return (int) $a['last_scan'] <=> (int) $b['last_scan'];
        } );

        $due = array_slice( $due, 0, $limit );
        $result = array(
            'attempted'  => 0,
            'succeeded'  => 0,
            'errors'     => 0,
            'created'    => 0,
            'existing'   => 0,
            'duplicates' => 0,
            'sources'    => array(),
        );

        foreach ( $due as $item ) {
            $source_id = absint( $item['source_id'] );
            $source_key = sanitize_key( (string) get_post_meta( $source_id, 'source_key', true ) );
            $result['attempted']++;

            $scan = Sektorel_Content_Source_Custom_Bridge::scan_source( $source_id );
            if ( is_wp_error( $scan ) ) {
                $result['errors']++;
                $result['sources'][] = array(
                    'source_id'  => $source_id,
                    'source_key' => $source_key,
                    'status'     => 'error',
                    'code'       => $scan->get_error_code(),
                    'message'    => $scan->get_error_message(),
                );
                continue;
            }

            $result['succeeded']++;
            $result['created'] += absint( $scan['created'] ?? 0 );
            $result['existing'] += absint( $scan['existing'] ?? 0 );
            $result['duplicates'] += absint( $scan['duplicates'] ?? 0 );
            $result['sources'][] = array(
                'source_id'  => $source_id,
                'source_key' => $source_key,
                'status'     => 'ok',
                'parsed'     => absint( $scan['parsed'] ?? 0 ),
                'created'    => absint( $scan['created'] ?? 0 ),
                'existing'   => absint( $scan['existing'] ?? 0 ),
                'duplicates' => absint( $scan['duplicates'] ?? 0 ),
            );
        }

        return $result;
    }

    private static function scan_interval_seconds( $interval ) {
        switch ( sanitize_key( $interval ) ) {
            case 'hourly':
                return HOUR_IN_SECONDS;
            case 'twicedaily':
                return 12 * HOUR_IN_SECONDS;
            case 'daily':
                return DAY_IN_SECONDS;
            default:
                return 0;
        }
    }

    private static function error_payload( $error ) {
        return array(
            'status'  => 'error',
            'code'    => $error->get_error_code(),
            'message' => $error->get_error_message(),
        );
    }
}
