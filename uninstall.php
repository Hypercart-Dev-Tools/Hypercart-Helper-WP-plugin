<?php
/**
 * Hypercart Helper Uninstall
 *
 * Removes log files and cleans up when plugin is deleted.
 *
 * @package Hypercart_Helper
 */

// Prevent direct access
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove log directory and all contents
$log_dir = WP_CONTENT_DIR . '/hypercart-logs';

if ( is_dir( $log_dir ) ) {
	$files = glob( $log_dir . '/*' );
	
	if ( $files ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
	}
	
	rmdir( $log_dir );
}

// Clear any scheduled events (in case deactivation didn't run)
wp_clear_scheduled_hook( 'hypercart_daily_cleanup' );

