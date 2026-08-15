<?php
/**
 * Plugin Name: Sektorel Core
 * Description: Sektörel Ajanda projesi için CPT, Taxonomy ve API tanımlarını içeren çekirdek eklenti.
 * Version: 1.50.0
 * Author: Sektörel Ajanda Dev Team
 * Text Domain: sektorel-core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SEKTOREL_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SEKTOREL_CORE_URL', plugin_dir_url( __FILE__ ) );

class Sektorel_Core {
    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->includes();
        Sektorel_Event_Source_Module::init();
        Sektorel_Token_Service::init();
        Sektorel_Company_Media::init();
        Sektorel_Job_Application_Files::init();
        Sektorel_Job_Application_Access_Fix::init();
        Sektorel_Mail_Observability::init();
        Sektorel_Event_Reminders::init();
        add_action( 'init', array( $this, 'register_post_types' ) );
        add_action( 'init', array( $this, 'register_taxonomies' ) );
        add_action( 'init', array( $this, 'init_fields' ) );
        if ( is_admin() ) {
            Sektorel_Demo_Importer::init();
            Sektorel_Event_Source_Admin::init();
            Sektorel_Event_Source_Role::init();
            Sektorel_Event_Source_TOBB::init();
            Sektorel_Event_Source_TOBB_Taxonomy::init();
            Sektorel_Event_Source_TOBB_Location_Resolver::init();
            Sektorel_Event_Source_Center::init();
            Sektorel_Event_Source_Importer_Fixed::init();
            Sektorel_Event_Source_Import_Header_Fix::init();
            Sektorel_Event_Source_URL_Normalizer::init();
            Sektorel_Event_Source_Checker::init();
            Sektorel_Event_Source_Single_Check_Notice::init();
            Sektorel_Event_Source_Health::init();
            Sektorel_Event_Source_Target_Discovery::init();
            Sektorel_Event_Source_Target_Safety::init();
            Sektorel_Event_Candidate_JSONLD::init();
            Sektorel_Event_Candidate_Confidence::init();
            Sektorel_Event_HTML_Safe_Queue::init();
            Sektorel_Event_Candidate_Filter_Safety::init();
            Sektorel_Event_Candidate_HTML_Container_Filter::init();
            Sektorel_Event_Candidate_HTML_Stale_Filter::init();
            Sektorel_Event_Candidate_HTML_Time_Proximity::init();
            Sektorel_Event_Candidate_HTML::init();
            Sektorel_Event_HTML_Scan_Observability::init();
            Sektorel_Event_HTML_New_Candidate_Panel::init();
            Sektorel_Event_HTML_Final_Guard::init();
            Sektorel_Event_HTML_Unresolved_Review::init();
            Sektorel_Event_HTML_Review_Hygiene::init();
            Sektorel_Event_HTML_Review_Safety::init();
            Sektorel_Event_HTML_Review_Triage::init();
            Sektorel_Event_HTML_Safe_Convert::init();
            Sektorel_Event_Taxonomy_Selector::init();
            Sektorel_Event_Taxonomy_Metabox_Hotfix::init();
            Sektorel_Event_Candidate_Post_Hardening::init();
            Sektorel_Event_Candidate_Listing_Guard::init();
            Sektorel_Event_Candidate_Retro_Cleanup::init();
            Sektorel_Event_Title_Casing_Fix::init();
            Sektorel_Event_Candidate_URL_Fix::init();
            Sektorel_Event_Candidate_Quality::init();
            Sektorel_Event_Candidate_Matcher::init();
            Sektorel_Event_Source_Evidence::init();
            Sektorel_Event_Candidate_State_Guard::init();
            Sektorel_Event_Content_Quality::init();
            Sektorel_Event_Candidate_Field_Quality::init();
        }
        Sektorel_Company_Mutations::init();
        Sektorel_Company_Profile::init();
        Sektorel_Company_Settings::init();
        Sektorel_Profile_Completion::init();
        Sektorel_Company_Directory::init();
        Sektorel_Directory_Facets::init();
        Sektorel_Company_Members::init();
        Sektorel_Auth_Mutations::init();
        Sektorel_Password_Reset_Mutations::init();
        Sektorel_Session_Query::init();
        Sektorel_Location_Options::init();
        Sektorel_Owned_Content::init();
        Sektorel_Content_Submissions::init();
        Sektorel_Offers::init();
        Sektorel_Job_Applications::init();
        add_action( 'graphql_register_types', array( $this, 'register_graphql_types' ) );
    }

    private function includes() {
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-company.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-lead.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-event.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-event-reminder.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-event-source.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-event-candidate.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-career.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-offer.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-job-application.php';
        require_once SEKTOREL_CORE_PATH . 'includes/taxonomies/class-sector.php';
        require_once SEKTOREL_CORE_PATH . 'includes/taxonomies/class-location.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/company-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/lead-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/event-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/sector-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/career-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/location-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-demo-importer.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-admin.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-role.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-tobb.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-tobb-taxonomy.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-tobb-location-resolver.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-center.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-importer-fixed.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-import-header-fix.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-url-normalizer.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-checker.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-single-check-notice.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-module.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-health.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-target-discovery.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-target-safety.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-jsonld.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-confidence.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-html-safe-queue.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-filter-safety.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-html-container-filter.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-html-stale-filter.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-html-time-proximity.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-html.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-html-scan-observability.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-html-new-candidate-panel.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-html-final-guard.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-html-unresolved-review.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-html-review-hygiene.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-html-review-safety.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-html-review-triage.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-html-safe-convert.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-taxonomy-selector.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-taxonomy-metabox-hotfix.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-post-hardening.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-listing-guard.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-retro-cleanup.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-title-casing-fix.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-url-fix.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-quality.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-matcher.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-evidence.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-state-guard.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-content-quality.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-field-quality.php';
        require_once SEKTOREL_CORE_PATH . 'includes/mail/class-mail-observability.php';
        require_once SEKTOREL_CORE_PATH . 'includes/auth/class-token-service.php';
        require_once SEKTOREL_CORE_PATH . 'includes/rest/class-company-media.php';
        require_once SEKTOREL_CORE_PATH . 'includes/rest/class-job-application-files.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/types.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-auth-mutations.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-password-reset-mutations.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-company-mutations.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-company-profile.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-company-settings.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-profile-completion.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-company-directory.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-directory-facets.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-company-members.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-session-query.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-location-options.php';
        require_once SEKTOREL_CORE_PATH . 'includes/auth/class-company-access.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-owned-content.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-content-submissions.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-offers.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-job-applications.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-job-application-access-fix.php';
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-event-reminders.php';
    }

    public function register_post_types() {
        Sektorel_Company_CPT::register();
        Sektorel_Lead_CPT::register();
        Sektorel_Event_CPT::register();
        Sektorel_Event_Reminder_CPT::register();
        Sektorel_Event_Source_CPT::register();
        Sektorel_Event_Candidate_CPT::register();
        Sektorel_Career_CPT::register();
        Sektorel_Offer_CPT::register();
        Sektorel_Job_Application_CPT::register();
    }

    public function register_taxonomies() {
        Sektorel_Sector_Taxonomy::register();
        Sektorel_Location_Taxonomy::register();
    }

    public function init_fields() {
        Sektorel_Company_Fields::init();
        Sektorel_Lead_Fields::init();
        Sektorel_Event_Fields::init();
        Sektorel_Sector_Fields::init();
        Sektorel_Career_Fields::init();
        Sektorel_Location_Fields::init();
    }

    public function register_graphql_types() {
        Sektorel_GraphQL_Types::register();
    }
}

Sektorel_Core::get_instance();
