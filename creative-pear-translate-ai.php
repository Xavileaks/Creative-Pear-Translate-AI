<?php
/**
 * Plugin Name: Creative Pear Translate AI
 * Plugin URI: https://github.com/Xavileaks/Creative-Pear-Translate-AI
 * Update URI: https://github.com/Xavileaks/Creative-Pear-Translate-AI
 * Description: Traduce páginas públicas con tu propia API de OpenAI o Claude y conserva las traducciones en WordPress.
 * Version: 1.7.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Creative Pear
 * License: GPL-2.0-or-later
 * Text Domain: creative-pear-translate-ai
 */
defined( 'ABSPATH' ) || exit;
define( 'CPTAI_VERSION', '1.7.2' );
define( 'CPTAI_FILE', __FILE__ );
foreach ( array( 'settings', 'store', 'breaks', 'provider', 'calendar', 'plan', 'html', 'routing', 'images', 'frontend', 'scanner', 'updater', 'admin', 'elementor' ) as $cptai_class ) {
    require_once __DIR__ . '/includes/class-' . $cptai_class . '.php';
}
register_activation_hook( __FILE__, array( 'CPTAI_Routing', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CPTAI_Routing', 'deactivate' ) );
register_activation_hook( __FILE__, array( 'CPTAI_Updater', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CPTAI_Updater', 'deactivate' ) );
add_action( 'plugins_loaded', static function () {
    if ( get_option( 'cptai_db_version' ) !== CPTAI_VERSION ) {
        CPTAI_Store::install();
    }
    CPTAI_Routing::boot();
    CPTAI_Frontend::boot();
    CPTAI_Admin::boot();
    CPTAI_Plan::boot();
    CPTAI_Elementor::boot();
    CPTAI_Updater::boot();
} );
