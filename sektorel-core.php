<?php
/**
 * Plugin Name: Sektorel Core
 * Description: Sektörel Ajanda projesi için CPT, Taxonomy ve API tanımlarını içeren çekirdek eklenti.
 * Version: 1.5.0
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
        add_action( 'init', array( $this, 'register_post_types' ) );
        add_action( 'init', array( $this, 'register_taxonomies' ) );
        
        // Native Metabox başlatıcıları
        add_action( 'init', array( $this, 'init_fields' ) ); 
        
        // Demo Importer
        if ( is_admin() ) {
            Sektorel_Demo_Importer::init();
        }
        // Mutation'ı başlat
        if ( class_exists( 'Sektorel_Company_Mutations' ) ) {
            Sektorel_Company_Mutations::init();
        }
        // Auth Mutations (Kritik nokta burası)
        Sektorel_Auth_Mutations::init();

        add_action( 'graphql_register_types', array( $this, 'register_graphql_types' ) );
    }

    private function includes() {
        // CPT
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-company.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-lead.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-event.php';
        require_once SEKTOREL_CORE_PATH . 'includes/post-types/class-career.php';
        
        // Taxonomy
        require_once SEKTOREL_CORE_PATH . 'includes/taxonomies/class-sector.php';
        require_once SEKTOREL_CORE_PATH . 'includes/taxonomies/class-location.php';

        // Fields
        require_once SEKTOREL_CORE_PATH . 'includes/fields/company-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/lead-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/event-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/sector-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/career-fields.php';
        require_once SEKTOREL_CORE_PATH . 'includes/fields/location-fields.php'; 

        
        // Admin
        require_once SEKTOREL_CORE_PATH . 'includes/admin/class-demo-importer.php'; 
        
        // GraphQL
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/types.php';
        
        // *** DİKKAT: Bu satırın çalıştığından emin olun ***
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-auth-mutations.php'; 
        require_once SEKTOREL_CORE_PATH . 'includes/graphql/class-company-mutations.php'; 
    }

    public function register_post_types() {
        Sektorel_Company_CPT::register();
        Sektorel_Lead_CPT::register();
        Sektorel_Event_CPT::register();
        Sektorel_Career_CPT::register();
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
        
        // YENİ EKLENEN SATIR:
        Sektorel_Location_Fields::init(); 
    }

    public function register_graphql_types() {
        Sektorel_GraphQL_Types::register();
    }
}

Sektorel_Core::get_instance();