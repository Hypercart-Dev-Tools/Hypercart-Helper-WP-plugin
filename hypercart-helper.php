<?php
/**
 * Plugin Name: Hypercart Helper
 * Plugin URI:  https://github.com/neochrome/hypercart-helper
 * Description: Shared utilities for the Hypercart plugin suite. Provides centralized time handling (UTC storage, local display) and structured file-based logging.
 * Version:     1.1.13
 * Author:      Neochrome
 * Author URI:  https://neochrome.dev
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hypercart-helper
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Hypercart_Helper
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'HYPERCART_HELPER_VERSION', '1.1.13' );
define( 'HYPERCART_HELPER_FILE', __FILE__ );
define( 'HYPERCART_HELPER_DIR', plugin_dir_path( __FILE__ ) );
define( 'HYPERCART_HELPER_URL', plugin_dir_url( __FILE__ ) );

// Include the Plugin Update Checker
require_once HYPERCART_HELPER_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/Hypercart-Dev-Tools/Hypercart-Helper-WP-plugin',
    __FILE__,
    'hypercart-helper'
);
// Optional: Set the branch that contains the stable release.
$update_checker->setBranch( 'main' );

// Load utility classes
require_once HYPERCART_HELPER_DIR . 'includes/class-hypercart-time.php';
require_once HYPERCART_HELPER_DIR . 'includes/class-hypercart-logger.php';
require_once HYPERCART_HELPER_DIR . 'includes/class-hypercart-charts.php';
require_once HYPERCART_HELPER_DIR . 'includes/class-hypercart-markdown-viewer.php';
require_once HYPERCART_HELPER_DIR . 'includes/class-hypercart-admin-tabs.php';
require_once HYPERCART_HELPER_DIR . 'includes/class-hypercart-admin.php';

/**
 * Activation hook - schedule log cleanup
 */
function hypercart_helper_activate() {
	if ( ! wp_next_scheduled( 'hypercart_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'hypercart_daily_cleanup' );
	}
}
register_activation_hook( __FILE__, 'hypercart_helper_activate' );

/**
 * Deactivation hook - clear scheduled events
 */
function hypercart_helper_deactivate() {
	wp_clear_scheduled_hook( 'hypercart_daily_cleanup' );
}
register_deactivation_hook( __FILE__, 'hypercart_helper_deactivate' );

/**
 * Daily cleanup cron handler
 */
add_action( 'hypercart_daily_cleanup', function() {
	$days_to_keep = apply_filters( 'hypercart_log_retention_days', 30 );
	$deleted = Hypercart_Logger::cleanup_old_logs( $days_to_keep );
	
	if ( $deleted > 0 ) {
		Hypercart_Logger::info( 'helper', 'Log cleanup completed', array(
			'files_deleted' => $deleted,
			'retention_days' => $days_to_keep,
		) );
	}
} );

/**
 * Register shared assets.
 */
add_action( 'init', array( 'Hypercart_Charts', 'register_assets' ) );

/**
 * Register Markdown Viewer shortcode.
 */
add_action( 'init', array( 'Hypercart_Markdown_Viewer', 'init' ) );

/**
 * Initialize admin interface
 */
if ( is_admin() ) {
	Hypercart_Admin::init();
}

/**
 * Log when Helper loads (debug only)
 */
add_action( 'plugins_loaded', function() {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		Hypercart_Logger::debug( 'helper', 'Hypercart Helper loaded', array(
			'version' => HYPERCART_HELPER_VERSION,
		) );
	}
}, 5 ); // Priority 5 = load early so dependents can use it
