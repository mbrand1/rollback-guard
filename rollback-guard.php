<?php
/**
 * Plugin Name: Rollback Guard
 * Description: Automatically backs up plugin directories before updates with one-click restore.
 * Version:     1.0.0
 * Author:      Brandon
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rollback-guard
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RG_VERSION', '1.0.0' );
define( 'RG_PLUGIN_FILE', __FILE__ );
define( 'RG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Multisite is not supported.
if ( is_multisite() ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'Rollback Guard is not compatible with WordPress Multisite installations.', 'rollback-guard' );
		echo '</p></div>';
	} );
	return;
}

require_once RG_PLUGIN_DIR . 'includes/class-rg-manifest.php';
require_once RG_PLUGIN_DIR . 'includes/class-rg-backup-manager.php';
require_once RG_PLUGIN_DIR . 'includes/class-rg-upgrader-hooks.php';
require_once RG_PLUGIN_DIR . 'includes/class-rg-admin-page.php';

// Activation.
register_activation_hook( __FILE__, function () {
	if ( is_multisite() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( esc_html__( 'Rollback Guard is not compatible with WordPress Multisite installations.', 'rollback-guard' ) );
	}

	$manager = new RG_Backup_Manager();
	$manager->ensure_backup_directory();

	add_option( 'rg_max_versions', 3 );
	add_option( 'rg_abort_on_failure', 0 );
	add_option( 'rg_size_limit_mb', 25 );
	add_option( 'rg_storage_quota_mb', 500 );
	add_option( 'rg_large_plugin_allowlist', array() );
	add_option( 'rg_excluded_plugins', array() );
} );

// Initialize on plugins_loaded.
add_action( 'plugins_loaded', function () {
	$manager = new RG_Backup_Manager();

	$hooks = new RG_Upgrader_Hooks( $manager );
	$hooks->init();

	if ( is_admin() ) {
		$admin = new RG_Admin_Page( $manager );
		$admin->init();
	}
} );
