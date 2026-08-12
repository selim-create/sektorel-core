<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Explicit bootstrap for the event-source ingestion module.
 *
 * Source Center infrastructure used to be loaded as a side effect of
 * class-event-source-single-check-notice.php. Keep the exact class load/init
 * order here, but make module ownership explicit from the Core bootstrap.
 */
class Sektorel_Event_Source_Module {

    private static $initialized = false;

    public static function init() {
        if ( self::$initialized ) {
            return;
        }

        self::$initialized = true;

        require_once __DIR__ . '/class-event-source-stage-registry.php';
        Sektorel_Event_Source_Stage_Registry::init();

        require_once __DIR__ . '/class-event-source-center-reporting.php';
        Sektorel_Event_Source_Center_Reporting::init();

        require_once __DIR__ . '/class-event-source-background-run.php';
        Sektorel_Event_Source_Background_Run::init();

        require_once __DIR__ . '/class-event-source-background-run-callback-fix.php';
        Sektorel_Event_Source_Background_Run_Callback_Fix::init();

        require_once __DIR__ . '/class-event-source-background-nonce-compat.php';
        Sektorel_Event_Source_Background_Nonce_Compat::init();

        require_once __DIR__ . '/class-event-source-ifm.php';
        Sektorel_Event_Source_IFM::init();

        require_once __DIR__ . '/class-event-source-tuyap.php';
        Sektorel_Event_Source_Tuyap::init();

        require_once __DIR__ . '/class-event-source-tuyap-conflict-review.php';
        Sektorel_Event_Source_Tuyap_Conflict_Review::init();

        require_once __DIR__ . '/class-event-canonical-draft-stage.php';
        Sektorel_Event_Canonical_Draft_Stage::init();

        require_once __DIR__ . '/class-event-candidate-inbox.php';
        Sektorel_Event_Candidate_Inbox::init();

        require_once __DIR__ . '/class-event-candidate-enrichment-actions.php';
        Sektorel_Event_Candidate_Enrichment_Actions::init();

        require_once __DIR__ . '/class-event-candidate-manual-match.php';
        Sektorel_Event_Candidate_Manual_Match::init();

        require_once __DIR__ . '/class-event-candidate-background-matcher.php';
        Sektorel_Event_Candidate_Background_Matcher::init();

        require_once __DIR__ . '/class-event-safe-discovery-draft-stage.php';
        Sektorel_Event_Safe_Discovery_Draft_Stage::init();

        require_once __DIR__ . '/class-event-pipeline-reporting-detail.php';
        Sektorel_Event_Pipeline_Reporting_Detail::init();
    }
}
