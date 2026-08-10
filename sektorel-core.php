<?php
/**
 * Plugin Name: Sektorel Core
 * Description: Sektörel Ajanda projesi için CPT, Taxonomy ve API tanımlarını içeren çekirdek eklenti.
 * Version: 1.27.0
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
            Sektorel_Event_Source_Importer_Fixed::init();
            Sektorel_Event_Source_Import_Header_Fix::init();
            Sektorel_Event_Source_Checker::init();
            Sektorel_Event_Source_Health::init();
            Sektorel_Event_Candidate_JSONLD::init();
            Sektorel_Event_Candidate_HTML::init();
            Sektorel_Event_Candidate_URL_Fix::init();
            Sektorel_Event_Candidate_Quality::init();
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
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-importer-fixed.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-import-header-fix.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-checker.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-health.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-jsonld.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-html.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-url-fix.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-candidate-quality.php';
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
