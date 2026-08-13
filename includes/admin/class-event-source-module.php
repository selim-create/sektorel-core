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

        // Load the registry class early, but initialize it only after every
        // internal provider class below is available for callback validation.
        // Registry initialization itself does not create nonces; runtime_stages()
        // creates fresh nonces only when Source Center/background consumers ask.
        require_once __DIR__ . '/class-event-source-stage-registry.php';

        require_once __DIR__ . '/class-event-source-center-reporting.php';
        Sektorel_Event_Source_Center_Reporting::init();

        require_once __DIR__ . '/class-event-source-background-run.php';
        Sektorel_Event_Source_Background_Run::init();

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

        // All internal stage providers now expose only parser/AJAX behavior.
        // Stage Registry is the sole internal stage/action/nonce source.
        Sektorel_Event_Source_Stage_Registry::init();

        require_once __DIR__ . '/class-event-pipeline-reporting-detail.php';
        Sektorel_Event_Pipeline_Reporting_Detail::init();
    }
}
