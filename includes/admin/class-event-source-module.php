<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Explicit bootstrap for the event-source ingestion module.
 *
 * Source Center infrastructure is loaded here in one place. Stage providers
 * initialize during plugin bootstrap; registry adoption is deferred until
 * WordPress init so pluggable nonce functions are available.
 */
class Sektorel_Event_Source_Module {

    private static $initialized = false;
    private static $registry_adoption_completed = false;

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

        // Provider registration methods generate WordPress nonces. During active
        // plugin loading wp_create_nonce() is not guaranteed to exist yet, so
        // adoption must not run synchronously from the plugin constructor.
        add_action( 'init', array( __CLASS__, 'adopt_stage_registry' ), 1 );

        require_once __DIR__ . '/class-event-pipeline-reporting-detail.php';
        Sektorel_Event_Pipeline_Reporting_Detail::init();
    }

    public static function adopt_stage_registry() {
        if ( self::$registry_adoption_completed ) {
            return;
        }

        self::$registry_adoption_completed = true;

        // Fail-closed migration: only after every internal stage provider has
        // initialized do we adopt its legacy definitions. If any provider cannot
        // be adopted, restore every internal legacy hook and keep the proven
        // production path active for this request.
        $adoption = Sektorel_Event_Source_Stage_Registry::adopt_internal_legacy_providers();
        if ( is_wp_error( $adoption ) ) {
            self::restore_legacy_stage_filters();
        }
    }

    private static function restore_legacy_stage_filters() {
        $providers = array(
            array( 'Sektorel_Event_Source_IFM', 20 ),
            array( 'Sektorel_Event_Source_Tuyap', 25 ),
            array( 'Sektorel_Event_Canonical_Draft_Stage', 40 ),
            array( 'Sektorel_Event_Candidate_Background_Matcher', 95 ),
            array( 'Sektorel_Event_Safe_Discovery_Draft_Stage', 105 ),
        );

        foreach ( $providers as $provider ) {
            $class    = $provider[0];
            $priority = $provider[1];

            if ( is_callable( array( $class, 'register_stage' ) ) ) {
                add_filter( 'sektorel_source_center_stages', array( $class, 'register_stage' ), $priority );
            }
            if ( is_callable( array( $class, 'register_background_actions' ) ) ) {
                add_filter( 'sektorel_source_background_action_map', array( $class, 'register_background_actions' ), $priority );
            }
            if ( is_callable( array( $class, 'register_nonce_actions' ) ) ) {
                add_filter( 'sektorel_source_background_nonce_actions', array( $class, 'register_nonce_actions' ), $priority );
            }
        }
    }
}
