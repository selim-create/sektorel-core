<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Explicit bootstrap for the event-source ingestion module.
 *
 * Provider classes own parser/import behavior only. Pipeline stage metadata,
 * callbacks, order and nonce actions are owned centrally by Stage Registry.
 */
class Sektorel_Event_Source_Module {

    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }

        self::$initialized = true;

        require_once __DIR__ . '/class-event-source-stage-registry.php';

        require_once __DIR__ . '/class-event-source-center-reporting.php';
        Sektorel_Event_Source_Center_Reporting::init();

        require_once __DIR__ . '/class-event-source-background-run.php';
        Sektorel_Event_Source_Background_Run::init();

        require_once __DIR__ . '/class-event-official-calendar-stage.php';
        Sektorel_Event_Official_Calendar_Stage::init();

        require_once __DIR__ . '/class-event-official-calendar-admin.php';
        Sektorel_Event_Official_Calendar_Admin::init();

        require_once __DIR__ . '/class-event-official-conditional-rules.php';
        Sektorel_Event_Official_Conditional_Rules::init();

        Sektorel_Event_Source_Stage_Registry::register( array(
            'key'              => 'official_calendar',
            'order'            => 15,
            'label'            => 'Resmî Takvimi Güncelle',
            'description'      => 'GİB, SGK ve Ticaret Bakanlığı kaynaklı çekirdek şirket yükümlülüklerini idempotent taslak Event kayıtlarına dönüştürür.',
            'prepare_action'   => 'sektorel_official_calendar_prepare',
            'prepare_callback' => array( 'Sektorel_Event_Official_Calendar_Stage', 'ajax_prepare' ),
            'batch_action'     => 'sektorel_official_calendar_batch',
            'batch_callback'   => array( 'Sektorel_Event_Official_Calendar_Stage', 'ajax_batch' ),
            'nonce_action'     => Sektorel_Event_Official_Calendar_Stage::NONCE_ACTION,
            'prepare_payload'  => array( __CLASS__, 'official_calendar_payload' ),
        ) );

        require_once __DIR__ . '/class-event-official-calendar-phase2-stage.php';
        Sektorel_Event_Official_Calendar_Phase2_Stage::init();
        Sektorel_Event_Source_Stage_Registry::register( array(
            'key'              => 'official_calendar_phase2',
            'order'            => 16,
            'label'            => 'Sektörel Resmî Yükümlülükleri Güncelle',
            'description'      => 'Çevre Bakanlığı, EPDK ve SPK kaynaklı sektör/şirket tipine özel deterministic resmî son tarihleri taslak Event olarak günceller.',
            'prepare_action'   => 'sektorel_official_calendar_phase2_prepare',
            'prepare_callback' => array( 'Sektorel_Event_Official_Calendar_Phase2_Stage', 'ajax_prepare' ),
            'batch_action'     => 'sektorel_official_calendar_phase2_batch',
            'batch_callback'   => array( 'Sektorel_Event_Official_Calendar_Phase2_Stage', 'ajax_batch' ),
            'nonce_action'     => Sektorel_Event_Official_Calendar_Phase2_Stage::NONCE_ACTION,
            'prepare_payload'  => array( __CLASS__, 'official_calendar_payload' ),
        ) );

        // Keep the 1.50 verified catalogue loaded as fallback data, but make
        // the 1.51 live adapter the direct runtime callback registered in the
        // Source Center. This avoids relying on a later action-map override.
        require_once __DIR__ . '/class-event-public-opportunity-stage.php';
        Sektorel_Event_Public_Opportunity_Stage::init();

        // KOSGEB's landing page does not always expose every still-active
        // programme in the cards scanned by the live adapter. Seed verified
        // official detail URLs into discovery only; the detail page must still
        // be fetched and its deadline re-validated before live evidence exists.
        require_once __DIR__ . '/class-event-public-opportunity-live-probe.php';
        Sektorel_Event_Public_Opportunity_Live_Probe::init();

        require_once __DIR__ . '/class-event-public-opportunity-live-stage.php';
        Sektorel_Event_Public_Opportunity_Live_Stage::init();

        require_once __DIR__ . '/class-event-public-opportunity-admin.php';
        Sektorel_Event_Public_Opportunity_Admin::init();

        Sektorel_Event_Source_Stage_Registry::register( array(
            'key'              => 'public_opportunities',
            'order'            => 17,
            'label'            => 'Kamu Destekleri ve Son Başvuruları Güncelle',
            'description'      => 'KOSGEB ve İŞKUR resmî sayfalarını kaynağa özel canlı adapterlarla tarar; doğrulanmış açık/yaklaşan fırsatları ayrı fırsat semantiğiyle taslak Event olarak günceller.',
            'prepare_action'   => 'sektorel_public_opportunities_prepare',
            'prepare_callback' => array( 'Sektorel_Event_Public_Opportunity_Live_Stage', 'ajax_prepare' ),
            'batch_action'     => 'sektorel_public_opportunities_batch',
            'batch_callback'   => array( 'Sektorel_Event_Public_Opportunity_Live_Stage', 'ajax_batch' ),
            'nonce_action'     => Sektorel_Event_Public_Opportunity_Live_Stage::NONCE_ACTION,
            'prepare_payload'  => array( __CLASS__, 'public_opportunity_payload' ),
        ) );

        require_once __DIR__ . '/class-event-source-ifm.php';
        Sektorel_Event_Source_IFM::init();

        require_once __DIR__ . '/class-event-source-tuyap.php';
        Sektorel_Event_Source_Tuyap::init();

        require_once __DIR__ . '/class-event-source-tuyap-conflict-review.php';
        Sektorel_Event_Source_Tuyap_Conflict_Review::init();

        require_once __DIR__ . '/class-event-canonical-draft-stage.php';
        Sektorel_Event_Canonical_Draft_Stage::init();

        require_once __DIR__ . '/class-event-source-trusted-discovery.php';
        Sektorel_Event_Source_Trusted_Discovery::init();
        Sektorel_Event_Source_Stage_Registry::register( array(
            'key'              => 'trusted_discovery',
            'order'            => 55,
            'label'            => 'Güvenilir Kaynak Keşfi',
            'description'      => 'Webrazzi ve TEKNOFEST resmî etkinlik sayfalarını kaynağa özel deterministic adapterlarla tarar.',
            'prepare_action'   => 'sektorel_trusted_discovery_prepare',
            'prepare_callback' => array( 'Sektorel_Event_Source_Trusted_Discovery', 'ajax_prepare' ),
            'batch_action'     => 'sektorel_trusted_discovery_batch',
            'batch_callback'   => array( 'Sektorel_Event_Source_Trusted_Discovery', 'ajax_batch' ),
            'nonce_action'     => Sektorel_Event_Source_Trusted_Discovery::NONCE_ACTION,
            'prepare_payload'  => array( __CLASS__, 'trusted_discovery_payload' ),
        ) );

        require_once __DIR__ . '/class-event-candidate-inbox.php';
        Sektorel_Event_Candidate_Inbox::init();

        require_once __DIR__ . '/class-event-review-queue-audit.php';
        Sektorel_Event_Review_Queue_Audit::init();

        require_once __DIR__ . '/class-event-candidate-enrichment-actions.php';
        Sektorel_Event_Candidate_Enrichment_Actions::init();

        require_once __DIR__ . '/class-event-candidate-manual-match.php';
        Sektorel_Event_Candidate_Manual_Match::init();

        require_once __DIR__ . '/class-event-source-title-repair-stage.php';
        Sektorel_Event_Source_Title_Repair_Stage::init();

        require_once __DIR__ . '/class-event-candidate-background-matcher.php';
        Sektorel_Event_Candidate_Background_Matcher::init();

        require_once __DIR__ . '/class-event-review-expiry.php';

        require_once __DIR__ . '/class-event-review-queue-reducer.php';
        Sektorel_Event_Review_Queue_Reducer::init();

        require_once __DIR__ . '/class-event-safe-discovery-draft-stage.php';
        Sektorel_Event_Safe_Discovery_Draft_Stage::init();

        require_once __DIR__ . '/class-event-data-health.php';
        Sektorel_Event_Data_Health::init();
        Sektorel_Event_Source_Stage_Registry::register( array(
            'key'              => 'event_data_health',
            'order'            => 95,
            'label'            => 'Etkinlik Veri Sağlığını Kontrol Et',
            'description'      => 'Gelecek ve aktif Event kayıtlarında completeness, eksik alanlar ve kaynak çakışmalarını hesaplar; hiçbir alanı overwrite etmez.',
            'prepare_action'   => 'sektorel_event_data_health_prepare',
            'prepare_callback' => array( 'Sektorel_Event_Data_Health', 'ajax_prepare' ),
            'batch_action'     => 'sektorel_event_data_health_batch',
            'batch_callback'   => array( 'Sektorel_Event_Data_Health', 'ajax_batch' ),
            'nonce_action'     => Sektorel_Event_Data_Health::NONCE_ACTION,
            'prepare_payload'  => array(),
        ) );

        require_once __DIR__ . '/class-event-ai-assistant.php';
        Sektorel_Event_AI_Assistant::init();

        Sektorel_Event_Source_Stage_Registry::init();

        require_once __DIR__ . '/class-event-pipeline-reporting-detail.php';
        Sektorel_Event_Pipeline_Reporting_Detail::init();
    }

    public static function official_calendar_payload() {
        return array( 'year' => (int) current_time( 'Y' ) );
    }

    public static function public_opportunity_payload() {
        return array( 'year' => (int) current_time( 'Y' ) );
    }

    public static function trusted_discovery_payload() {
        return array( 'year' => (int) current_time( 'Y' ) );
    }
}
