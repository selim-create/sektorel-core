<?php
/**
 * Plugin Name: Sektorel Core
 * Description: Sektörel Ajanda projesi için CPT, Taxonomy ve API tanımlarını içeren çekirdek eklenti.
 * Version: 1.75.0
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
        // Native WordPress authentication must stay completely independent from
        // the headless/runtime stack. Hostinger/FastCGI may expose wp-login.php
        // through REQUEST_URI while SCRIPT_NAME/PHP_SELF point at index.php, so
        // all three request surfaces are checked.
        if ( $this->is_native_login_request() ) {
            return;
        }

        $this->includes();

        add_action( 'init', array( $this, 'register_post_types' ) );
        add_action( 'init', array( $this, 'register_taxonomies' ) );
        add_action( 'init', array( $this, 'init_fields' ) );

        if ( is_admin() ) {
            // Ordinary Dashboard requests stay intentionally minimal. Company,
            // content and event operational services are initialized only by
            // bootstrap_admin() when their own screen/action is requested.
            $this->bootstrap_admin();
            return;
        }

        // Public/API/GraphQL/cron runtime.
        Sektorel_Event_Source_Module::init();
        Sektorel_Token_Service::init();
        Sektorel_Company_Media::init();
        Sektorel_Sitemap_Snapshot::init();
        Sektorel_Company_Ranking::init();
        Sektorel_Company_Candidates::init();
        Sektorel_Content_Candidates::init();
        Sektorel_Job_Application_Files::init();
        Sektorel_Job_Application_Access_Fix::init();
        Sektorel_Mail_Observability::init();
        Sektorel_Event_Reminders::init();
        Sektorel_Headless_Routing::init();

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

    private function is_native_login_request() {
        $script_name = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $php_self    = isset( $_SERVER['PHP_SELF'] ) ? (string) $_SERVER['PHP_SELF'] : '';
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $request_path = $request_uri ? parse_url( $request_uri, PHP_URL_PATH ) : '';

        return 'wp-login.php' === basename( $script_name ) ||
            'wp-login.php' === basename( $php_self ) ||
            ( is_string( $request_path ) && 'wp-login.php' === basename( $request_path ) );
    }

    /**
     * Keep the ordinary authenticated Dashboard lightweight.
     *
     * Before 1.69.4 every wp-admin request initialized the complete company,
     * content and event operations tree. 1.69.6 additionally removes candidate,
     * category-foundation, ranking and event-module initialization from unrelated
     * Dashboard requests. Operational groups now boot only for their own screens
     * or Sektorel AJAX/admin-post actions.
     */
    private function bootstrap_admin() {
        require_once SEKTOREL_CORE_PATH . 'includes/core/class-core-settings.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-core-console.php';

        add_action( 'plugins_loaded', array( 'Sektorel_Core_Settings', 'init' ), 1 );
        Sektorel_Core_Console::init();

        $scope = $this->admin_request_scope();

        if ( $scope['all'] || $scope['demo'] ) {
            $this->bootstrap_demo_admin();
        }
        if ( $scope['all'] || $scope['company'] ) {
            Sektorel_Company_Ranking::init();
            Sektorel_Company_Candidates::init();
            $this->bootstrap_company_admin();
        }
        if ( $scope['all'] || $scope['content'] ) {
            Sektorel_Content_Candidates::init();
            Sektorel_Content_Category_Foundation::init();
            $this->bootstrap_content_admin();
        }
        if ( $scope['all'] || $scope['event'] ) {
            Sektorel_Event_Source_Module::init();
            $this->bootstrap_event_admin();
        }
    }

    private function admin_request_scope() {
        $scope = array(
            'all'     => false,
            'demo'    => false,
            'company' => false,
            'content' => false,
            'event'   => false,
        );

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( $action && 0 === strpos( $action, 'sektorel_' ) ) {
            $scope['all'] = true;
            return $scope;
        }

        // Classic post.php updates submit post_type/post_ID via POST. Reading
        // request-scoped values here keeps the scoped admin modules available for
        // their save hooks without booting them on unrelated Dashboard requests.
        $page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
        $post_type = isset( $_REQUEST['post_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['post_type'] ) ) : '';

        if ( ! $post_type ) {
            $post_id = 0;
            if ( ! empty( $_REQUEST['post'] ) ) {
                $post_id = absint( $_REQUEST['post'] );
            } elseif ( ! empty( $_REQUEST['post_ID'] ) ) {
                $post_id = absint( $_REQUEST['post_ID'] );
            }

            if ( $post_id ) {
                $post_type = get_post_type( $post_id );
                $post_type = $post_type ? sanitize_key( $post_type ) : '';
            }
        }

        $scope['demo'] = 'sektorel-demo-import' === $page;

        $scope['company'] = 'company' === $post_type ||
            ( $page && false !== strpos( $page, 'sektorel-company' ) );

        $scope['content'] = 'content_source' === $post_type ||
            'sektorel-content-source-center' === $page ||
            ( $page && 0 === strpos( $page, 'sektorel-content-' ) );

        $scope['event'] = in_array( $post_type, array( 'event', 'event_source', 'event_candidate' ), true ) ||
            in_array( $page, array( 'sektorel-source-center', 'sektorel-event-source-center' ), true ) ||
            ( $page && 0 === strpos( $page, 'sektorel-event-' ) );

        return $scope;
    }

    private function bootstrap_demo_admin() {
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-demo-importer.php';
        Sektorel_Demo_Importer::init();
    }

    private function bootstrap_company_admin() {
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-company-importer.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-company-importer-shared-matcher.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-company-candidates-admin.php';

        Sektorel_Company_Importer::init();
        Sektorel_Company_Importer_Shared_Matcher::init();
        Sektorel_Company_Candidates_Admin::init();
    }

    private function bootstrap_content_admin() {
        require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-source-scanner.php';
        require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-candidate-triage.php';
        require_once SEKTOREL_CORE_PATH . 'includes/content/class-content-source-tobb-detail-date.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-content-source-admin.php';
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-content-source-center.php';

        Sektorel_Content_Source_Admin::init();
        Sektorel_Content_Source_Center::init();
        Sektorel_Content_Source_Scanner::init();
        Sektorel_Content_Candidate_Triage::init();
        Sektorel_Content_Source_TOBB_Detail_Date::init();
    }

    private function bootstrap_event_admin() {
        $files = array(
            'includes/admin/class-event-source-psb-anatolia.php',
            'includes/admin/class-event-source-lifecycle-repair.php',
            'includes/admin/class-event-source-admin.php',
            'includes/admin/class-event-source-role.php',
            'includes/admin/class-event-source-tobb.php',
            'includes/admin/class-event-source-tobb-taxonomy.php',
            'includes/admin/class-event-source-tobb-taxonomy-ui.php',
            'includes/admin/class-event-source-tobb-location-resolver.php',
            'includes/admin/class-event-source-center.php',
            'includes/admin/class-event-source-importer-fixed.php',
            'includes/admin/class-event-source-import-header-fix.php',
            'includes/admin/class-event-source-url-normalizer.php',
            'includes/admin/class-event-source-checker.php',
            'includes/admin/class-event-source-single-check-notice.php',
            'includes/admin/class-event-source-health.php',
            'includes/admin/class-event-source-target-discovery.php',
            'includes/admin/class-event-source-target-safety.php',
            'includes/admin/class-event-candidate-jsonld.php',
            'includes/admin/class-event-candidate-confidence.php',
            'includes/admin/class-event-html-safe-queue.php',
            'includes/admin/class-event-candidate-filter-safety.php',
            'includes/admin/class-event-candidate-html-container-filter.php',
            'includes/admin/class-event-candidate-html-stale-filter.php',
            'includes/admin/class-event-candidate-html-time-proximity.php',
            'includes/admin/class-event-candidate-html.php',
            'includes/admin/class-event-html-scan-observability.php',
            'includes/admin/class-event-html-new-candidate-panel.php',
            'includes/admin/class-event-html-final-guard.php',
            'includes/admin/class-event-html-unresolved-review.php',
            'includes/admin/class-event-html-review-hygiene.php',
            'includes/admin/class-event-html-review-safety.php',
            'includes/admin/class-event-html-review-triage.php',
            'includes/admin/class-event-html-safe-convert.php',
            'includes/admin/class-event-taxonomy-selector.php',
            'includes/admin/class-event-taxonomy-metabox-hotfix.php',
            'includes/admin/class-event-candidate-post-hardening.php',
            'includes/admin/class-event-candidate-listing-guard.php',
            'includes/admin/class-event-candidate-retro-cleanup.php',
            'includes/admin/class-event-title-casing-fix.php',
            'includes/admin/class-event-candidate-url-fix.php',
            'includes/admin/class-event-candidate-quality.php',
            'includes/admin/class-event-candidate-matcher.php',
            'includes/admin/class-event-source-evidence.php',
            'includes/admin/class-event-candidate-state-guard.php',
            'includes/admin/class-event-content-quality.php',
            'includes/admin/class-event-candidate-field-quality.php',
        );

        foreach ( $files as $file ) {
            require_once SEKTOREL_CORE_PATH . $file;
        }

        Sektorel_Event_Source_PSB_Anatolia::init();
        Sektorel_Event_Source_Lifecycle_Repair::init();
        Sektorel_Event_Source_Admin::init();
        Sektorel_Event_Source_Role::init();
        Sektorel_Event_Source_TOBB::init();
        Sektorel_Event_Source_TOBB_Taxonomy::init();
        remove_action( 'admin_menu', array( 'Sektorel_Event_Source_TOBB_Taxonomy', 'add_admin_menu' ), 46 );
        Sektorel_Event_Source_TOBB_Taxonomy_UI::init();
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

    private function includes() {
        $files = array(
            'includes/post-types/class-company.php',
            'includes/post-types/class-lead.php',
            'includes/post-types/class-event.php',
            'includes/post-types/class-event-reminder.php',
            'includes/post-types/class-event-source.php',
            'includes/post-types/class-event-candidate.php',
            'includes/post-types/class-content-source.php',
            'includes/post-types/class-career.php',
            'includes/post-types/class-offer.php',
            'includes/post-types/class-job-application.php',
            'includes/taxonomies/class-sector.php',
            'includes/taxonomies/class-location.php',
            'includes/company/class-company-ranking.php',
            'includes/company/class-company-matcher.php',
            'includes/company/class-company-candidates.php',
            'includes/company/class-company-candidate-lifecycle.php',
            'includes/fields/company-fields.php',
            'includes/fields/lead-fields.php',
            'includes/fields/event-fields.php',
            'includes/fields/sector-fields.php',
            'includes/fields/career-fields.php',
            'includes/fields/location-fields.php',
            'includes/fields/seo-fields.php',
            'includes/content/class-content-category-foundation.php',
            'includes/content/class-content-candidates.php',
            'includes/headless/class-headless-routing.php',
            'includes/admin/class-event-source-module.php',
            'includes/mail/class-mail-observability.php',
            'includes/auth/class-token-service.php',
            'includes/rest/class-company-media.php',
            'includes/rest/class-job-application-files.php',
            'includes/rest/class-sitemap-snapshot.php',
            'includes/graphql/types.php',
            'includes/graphql/class-auth-mutations.php',
            'includes/graphql/class-password-reset-mutations.php',
            'includes/graphql/class-company-mutations.php',
            'includes/graphql/class-company-profile.php',
            'includes/graphql/class-company-settings.php',
            'includes/graphql/class-profile-completion.php',
            'includes/graphql/class-company-directory.php',
            'includes/graphql/class-directory-facets.php',
            'includes/graphql/class-company-members.php',
            'includes/graphql/class-session-query.php',
            'includes/graphql/class-location-options.php',
            'includes/auth/class-company-access.php',
            'includes/graphql/class-owned-content.php',
            'includes/graphql/class-content-submissions.php',
            'includes/graphql/class-offers.php',
            'includes/graphql/class-job-applications.php',
            'includes/graphql/class-job-application-access-fix.php',
            'includes/graphql/class-event-reminders.php',
        );

        foreach ( $files as $file ) {
            require_once SEKTOREL_CORE_PATH . $file;
        }
    }

    public function register_post_types() {
        Sektorel_Company_CPT::register();
        Sektorel_Lead_CPT::register();
        Sektorel_Event_CPT::register();
        Sektorel_Event_Reminder_CPT::register();
        Sektorel_Event_Source_CPT::register();
        Sektorel_Event_Candidate_CPT::register();
        Sektorel_Content_Source_CPT::register();
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
        Sektorel_SEO_Fields::init();
    }

    public function register_graphql_types() {
        Sektorel_GraphQL_Types::register();
    }
}

Sektorel_Core::get_instance();
