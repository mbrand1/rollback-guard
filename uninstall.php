<?php
/**
 * Rollback Guard uninstall — clean up all data when the plugin is deleted.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove options.
delete_option( 'rg_max_versions' );
delete_option( 'rg_abort_on_failure' );
delete_option( 'rg_size_limit_mb' );
delete_option( 'rg_storage_quota_mb' );
delete_option( 'rg_large_plugin_allowlist' );
delete_option( 'rg_excluded_plugins' );

// Remove backup directory.
$upload_dir  = wp_upload_dir();
$backup_root = trailingslashit( $upload_dir['basedir'] ) . 'rg-backups';

if ( is_dir( $backup_root ) ) {
	// Recursive delete.
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $backup_root, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getRealPath() );
		} else {
			@unlink( $item->getRealPath() );
		}
	}
	@rmdir( $backup_root );
}

// Clean up any leftover transients.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rg_backup_results_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rg_backup_results_%'" );
