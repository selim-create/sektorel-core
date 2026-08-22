<?php
/**
 * Plugin Name: Sektorel Core
 * Description: Sektörel Ajanda projesi için CPT, Taxonomy ve API tanımlarını içeren çekirdek eklenti.
 * Version: 1.58.9
 * Author: Sektörel Ajanda Dev Team
 * Text Domain: sektorel-core
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SEKTOREL_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'SEKTOREL_CORE_URL', plugin_dir_url( __FILE__ ) );

if ( is_admin() ) {
    require_once SEKTOREL_CORE_PATH . 'includes/admin/class-event-source-psb-anatolia.php';
    Sektorel_Event_Source_PSB_Anatolia::init();
