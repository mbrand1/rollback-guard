<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Backup_Manager {

	private $backup_root = null;

	/**
	 * Get the root backup storage path.
	 */
	public function get_backup_root() {
		if ( null === $this->backup_root ) {
			$upload_dir        = wp_upload_dir();
			$this->backup_root = trailingslashit( $upload_dir['basedir'] ) . 'rg-backups';
		}
		return $this->backup_root;
	}

	/**
	 * Ensure backup directory exists with security files.
	 */
	public function ensure_backup_directory() {
		$root = $this->get_backup_root();

		if ( ! is_dir( $root ) ) {
			wp_mkdir_p( $root );
		}

		$htaccess = trailingslashit( $root ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "deny from all\n" );
		}

		$index = trailingslashit( $root ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Extract plugin slug from plugin file path.
	 *
	 * @param string $plugin_file e.g. "akismet/akismet.php" or "hello.php"
	 */
	public function extract_slug( $plugin_file ) {
		if ( false !== strpos( $plugin_file, '/' ) ) {
			return dirname( $plugin_file );
		}
		return basename( $plugin_file, '.php' );
	}

	/**
	 * Check if a plugin is a single-file plugin (no subdirectory).
	 */
	public function is_single_file_plugin( $plugin_file ) {
		return false === strpos( $plugin_file, '/' );
	}

	/**
	 * Get the source path for a plugin's files.
	 */
	public function get_plugin_source_path( $plugin_file ) {
		if ( $this->is_single_file_plugin( $plugin_file ) ) {
			return WP_PLUGIN_DIR . '/' . $plugin_file;
		}
		return WP_PLUGIN_DIR . '/' . $this->extract_slug( $plugin_file );
	}

	/**
	 * Get the size of a plugin in bytes.
	 */
	public function get_plugin_size( $plugin_file ) {
		$source = $this->get_plugin_source_path( $plugin_file );

		if ( $this->is_single_file_plugin( $plugin_file ) ) {
			return file_exists( $source ) ? filesize( $source ) : 0;
		}

		return is_dir( $source ) ? recurse_dirsize( $source ) : 0;
	}

	/**
	 * Check if a plugin slug is in the exclusion list.
	 */
	public function is_excluded( $slug ) {
		$excluded = get_option( 'rg_excluded_plugins', array() );
		return in_array( $slug, (array) $excluded, true );
	}

	/**
	 * Check if a plugin exceeds the size limit.
	 */
	public function is_oversized( $plugin_file ) {
		$limit = (int) get_option( 'rg_size_limit_mb', 25 );
		$size  = $this->get_plugin_size( $plugin_file );
		return $size > ( $limit * MB_IN_BYTES );
	}

	/**
	 * Check if a plugin slug is in the large-plugin allowlist.
	 */
	public function is_allowlisted( $slug ) {
		$allowlist = get_option( 'rg_large_plugin_allowlist', array() );
		return in_array( $slug, (array) $allowlist, true );
	}

	/**
	 * Add a plugin slug to the large-plugin allowlist.
	 */
	public function add_to_allowlist( $slug ) {
		$allowlist = get_option( 'rg_large_plugin_allowlist', array() );
		if ( ! in_array( $slug, $allowlist, true ) ) {
			$allowlist[] = $slug;
			update_option( 'rg_large_plugin_allowlist', $allowlist );
		}
	}

	/**
	 * Remove a plugin slug from the large-plugin allowlist.
	 */
	public function remove_from_allowlist( $slug ) {
		$allowlist = get_option( 'rg_large_plugin_allowlist', array() );
		$allowlist = array_values( array_diff( $allowlist, array( $slug ) ) );
		update_option( 'rg_large_plugin_allowlist', $allowlist );
	}

	/**
	 * Get total size of all backups in bytes.
	 */
	public function get_total_backup_size() {
		$root = $this->get_backup_root();
		if ( ! is_dir( $root ) ) {
			return 0;
		}
		return recurse_dirsize( $root );
	}

	/**
	 * Check whether an additional backup would stay within the storage quota.
	 */
	public function check_storage_quota( $additional_bytes = 0 ) {
		$quota_mb = (int) get_option( 'rg_storage_quota_mb', 500 );
		$current  = $this->get_total_backup_size();
		return ( $current + $additional_bytes ) <= ( $quota_mb * MB_IN_BYTES );
	}

	/**
	 * Check whether the filesystem has enough free space.
	 */
	public function check_disk_space( $needed_bytes ) {
		$root = $this->get_backup_root();
		$free = @disk_free_space( dirname( $root ) );
		if ( false === $free ) {
			return true; // Cannot determine — proceed.
		}
		return $free > ( $needed_bytes + 10 * MB_IN_BYTES );
	}

	/**
	 * Create a backup of a plugin before it is updated.
	 *
	 * @param string $plugin_file  Plugin file path (e.g. "akismet/akismet.php").
	 * @param string $new_version  The version being updated to.
	 * @param string $trigger      What initiated the backup ('auto_pre_update' or 'manual').
	 * @return array Result array with 'status' key ('success', 'skipped', or 'error').
	 */
	public function create_backup( $plugin_file, $new_version = '', $trigger = 'auto_pre_update' ) {
		$slug      = $this->extract_slug( $plugin_file );
		$is_single = $this->is_single_file_plugin( $plugin_file );

		// Never back up ourselves.
		if ( 'rollback-guard' === $slug ) {
			return array(
				'status' => 'skipped',
				'reason' => 'self',
				'slug'   => $slug,
			);
		}

		// Check exclusion list.
		if ( $this->is_excluded( $slug ) ) {
			return array(
				'status' => 'skipped',
				'reason' => 'excluded',
				'slug'   => $slug,
			);
		}

		// Read current plugin data.
		$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! file_exists( $plugin_path ) ) {
			return array(
				'status' => 'error',
				'reason' => 'not_found',
				'slug'   => $slug,
			);
		}

		$plugin_data = get_plugin_data( $plugin_path );
		$version     = $plugin_data['Version'];
		$name        = $plugin_data['Name'];

		// Calculate source size.
		$source      = $this->get_plugin_source_path( $plugin_file );
		$plugin_size = $is_single ? filesize( $source ) : recurse_dirsize( $source );

		// Size gate — only enforced for automatic pre-update backups.
		$size_limit = (int) get_option( 'rg_size_limit_mb', 25 );
		if ( 'auto_pre_update' === $trigger && $plugin_size > $size_limit * MB_IN_BYTES && ! $this->is_allowlisted( $slug ) ) {
			return array(
				'status'  => 'skipped',
				'reason'  => 'oversized',
				'slug'    => $slug,
				'name'    => $name,
				'version' => $version,
				'size'    => $plugin_size,
				'limit'   => $size_limit,
			);
		}

		// Storage quota.
		if ( ! $this->check_storage_quota( $plugin_size ) ) {
			return array(
				'status'  => 'skipped',
				'reason'  => 'quota_exceeded',
				'slug'    => $slug,
				'name'    => $name,
				'version' => $version,
			);
		}

		// Disk space.
		if ( ! $this->check_disk_space( $plugin_size ) ) {
			return array(
				'status'  => 'skipped',
				'reason'  => 'low_disk_space',
				'slug'    => $slug,
				'name'    => $name,
				'version' => $version,
			);
		}

		// Ensure backup root exists.
		$this->ensure_backup_directory();

		// Build backup path.
		$timestamp     = current_time( 'Y-m-d_His' );
		$backup_dirname = $version . '_' . $timestamp;
		$backup_dir    = trailingslashit( $this->get_backup_root() ) . $slug . '/' . $backup_dirname;
		$files_dir     = $backup_dir . '/files';

		// Acquire lock.
		$lock_file   = trailingslashit( $this->get_backup_root() ) . '.lock';
		$lock_handle = @fopen( $lock_file, 'w' );
		if ( $lock_handle && ! @flock( $lock_handle, LOCK_EX | LOCK_NB ) ) {
			@fclose( $lock_handle );
			return array(
				'status'  => 'error',
				'reason'  => 'locked',
				'slug'    => $slug,
				'name'    => $name,
				'version' => $version,
			);
		}

		try {
			wp_mkdir_p( $files_dir );

			// Copy files.
			if ( $is_single ) {
				if ( ! @copy( $source, $files_dir . '/' . basename( $plugin_file ) ) ) {
					$this->delete_directory( $backup_dir );
					$this->log_error( "Failed to copy single-file plugin {$slug}." );
					return array(
						'status'  => 'error',
						'reason'  => 'copy_failed',
						'slug'    => $slug,
						'name'    => $name,
						'version' => $version,
					);
				}
				$file_count = 1;
			} else {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				global $wp_filesystem;
				if ( ! $wp_filesystem ) {
					WP_Filesystem();
				}

				$copy_result = copy_dir( $source, $files_dir );
				if ( is_wp_error( $copy_result ) ) {
					$this->delete_directory( $backup_dir );
					$this->log_error( "Backup copy failed for {$slug}: " . $copy_result->get_error_message() );
					return array(
						'status'  => 'error',
						'reason'  => 'copy_failed',
						'slug'    => $slug,
						'name'    => $name,
						'version' => $version,
						'error'   => $copy_result->get_error_message(),
					);
				}
				$file_count = $this->count_files( $files_dir );
			}

			// Calculate actual backup size.
			$total_size = $is_single
				? filesize( $files_dir . '/' . basename( $plugin_file ) )
				: recurse_dirsize( $files_dir );

			// Write manifest.
			$manifest = array(
				'plugin_slug'         => $slug,
				'plugin_name'         => $name,
				'version'             => $version,
				'new_version'         => $new_version,
				'backup_date'         => current_time( 'c' ),
				'wp_version'          => get_bloginfo( 'version' ),
				'php_version'         => PHP_VERSION,
				'file_count'          => $file_count,
				'total_size_bytes'    => $total_size,
				'db_snapshot_included' => false,
				'backup_trigger'      => $trigger,
				'size_limit_applied'  => false,
			);
			RG_Manifest::write( $backup_dir . '/manifest.json', $manifest );

			// Verify backup.
			if ( ! $this->verify_backup( $backup_dir, $file_count ) ) {
				$this->delete_directory( $backup_dir );
				$this->log_error( "Backup verification failed for {$slug}." );
				return array(
					'status'  => 'error',
					'reason'  => 'verification_failed',
					'slug'    => $slug,
					'name'    => $name,
					'version' => $version,
				);
			}

			// Prune old backups.
			$max_versions = (int) get_option( 'rg_max_versions', 3 );
			$this->prune_backups( $slug, $max_versions );

		} finally {
			if ( $lock_handle ) {
				@flock( $lock_handle, LOCK_UN );
				@fclose( $lock_handle );
			}
		}

		return array(
			'status'     => 'success',
			'slug'       => $slug,
			'name'       => $name,
			'version'    => $version,
			'size'       => $total_size,
			'backup_dir' => $backup_dir,
		);
	}

	/**
	 * Quick integrity check on a completed backup.
	 */
	private function verify_backup( $backup_dir, $expected_count ) {
		if ( ! file_exists( $backup_dir . '/manifest.json' ) ) {
			return false;
		}
		if ( ! is_dir( $backup_dir . '/files' ) ) {
			return false;
		}
		$actual = $this->count_files( $backup_dir . '/files' );
		return ! ( 0 === $actual && $expected_count > 0 );
	}

	/**
	 * Count files recursively in a directory.
	 */
	public function count_files( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$count = 0;
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Get all backups grouped by plugin slug.
	 *
	 * @return array slug => array of backup data.
	 */
	public function get_all_backups() {
		$root = $this->get_backup_root();
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$backups     = array();
		$plugin_dirs = glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR );

		if ( ! $plugin_dirs ) {
			return array();
		}

		foreach ( $plugin_dirs as $plugin_dir ) {
			$slug           = basename( $plugin_dir );
			$plugin_backups = $this->get_plugin_backups( $slug );
			if ( ! empty( $plugin_backups ) ) {
				$backups[ $slug ] = $plugin_backups;
			}
		}

		return $backups;
	}

	/**
	 * Get all backups for a specific plugin slug, newest first.
	 */
	public function get_plugin_backups( $slug ) {
		$plugin_dir = trailingslashit( $this->get_backup_root() ) . $slug;
		if ( ! is_dir( $plugin_dir ) ) {
			return array();
		}

		$backups      = array();
		$version_dirs = glob( trailingslashit( $plugin_dir ) . '*', GLOB_ONLYDIR );

		if ( ! $version_dirs ) {
			return array();
		}

		foreach ( $version_dirs as $version_dir ) {
			$manifest_file = trailingslashit( $version_dir ) . 'manifest.json';
			if ( ! file_exists( $manifest_file ) ) {
				continue;
			}
			$manifest = RG_Manifest::read( $manifest_file );
			if ( $manifest ) {
				$manifest['backup_dir'] = $version_dir;
				$manifest['dir_name']   = basename( $version_dir );
				$backups[]              = $manifest;
			}
		}

		// Newest first.
		usort( $backups, function ( $a, $b ) {
			return strcmp( $b['backup_date'], $a['backup_date'] );
		} );

		return $backups;
	}

	/**
	 * Delete a specific backup.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $dir_name Backup directory name (e.g. "4.2.1_2025-02-17_143022").
	 * @return bool
	 */
	public function delete_backup( $slug, $dir_name ) {
		$root       = $this->get_backup_root();
		$backup_dir = trailingslashit( $root ) . $slug . '/' . $dir_name;

		// Security: ensure path is within backup root.
		$real_backup = realpath( $backup_dir );
		$real_root   = realpath( $root );
		if ( false === $real_backup || false === $real_root || 0 !== strpos( $real_backup, $real_root . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		$deleted = $this->delete_directory( $backup_dir );

		// If no backups remain for this plugin, remove the slug directory.
		$slug_dir = trailingslashit( $root ) . $slug;
		if ( is_dir( $slug_dir ) ) {
			$remaining = glob( trailingslashit( $slug_dir ) . '*', GLOB_ONLYDIR );
			if ( empty( $remaining ) ) {
				@rmdir( $slug_dir );
			}
		}

		return $deleted;
	}

	/**
	 * Prune backups for a plugin, keeping only the most recent $max_versions.
	 */
	public function prune_backups( $slug, $max_versions ) {
		$backups = $this->get_plugin_backups( $slug );
		if ( count( $backups ) <= $max_versions ) {
			return;
		}

		$to_remove = array_slice( $backups, $max_versions );
		foreach ( $to_remove as $backup ) {
			$this->delete_directory( $backup['backup_dir'] );
		}

		// Clean up empty slug directory.
		$slug_dir = trailingslashit( $this->get_backup_root() ) . $slug;
		if ( is_dir( $slug_dir ) ) {
			$remaining = glob( trailingslashit( $slug_dir ) . '*', GLOB_ONLYDIR );
			if ( empty( $remaining ) ) {
				@rmdir( $slug_dir );
			}
		}
	}

	/**
	 * Recursively delete a directory.
	 */
	public function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getRealPath() );
			} else {
				@unlink( $item->getRealPath() );
			}
		}

		return @rmdir( $dir );
	}

	/**
	 * Restore a plugin from a backup.
	 *
	 * Always wipes the existing plugin directory before copying backup files.
	 * If the plugin is currently installed, backs it up first (backup-before-restore).
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $dir_name Backup directory name.
	 * @return array Result with 'status' key.
	 */
	public function restore_backup( $slug, $dir_name ) {
		$root       = $this->get_backup_root();
		$backup_dir = trailingslashit( $root ) . $slug . '/' . $dir_name;
		$files_dir  = $backup_dir . '/files';

		// Security: ensure path is within backup root.
		$real_backup = realpath( $backup_dir );
		$real_root   = realpath( $root );
		if ( false === $real_backup || false === $real_root || 0 !== strpos( $real_backup, $real_root . DIRECTORY_SEPARATOR ) ) {
			return array( 'status' => 'error', 'reason' => 'invalid_path' );
		}

		if ( ! is_dir( $files_dir ) ) {
			return array( 'status' => 'error', 'reason' => 'backup_not_found' );
		}

		$manifest = RG_Manifest::read( $backup_dir . '/manifest.json' );
		if ( ! $manifest ) {
			return array( 'status' => 'error', 'reason' => 'manifest_not_found' );
		}

		$is_single = $this->is_backup_single_file( $files_dir );

		// If the plugin is currently installed, back it up and deactivate.
		$current_plugin_file = $this->find_plugin_file( $slug );
		$pre_restore_error   = '';

		if ( $current_plugin_file ) {
			// Backup current version first (best effort — don't block restore on failure).
			$pre_backup = $this->create_backup( $current_plugin_file, '', 'pre_restore' );
			if ( 'success' !== $pre_backup['status'] ) {
				$pre_restore_error = $pre_backup['reason'] ?? 'unknown';
			}

			if ( is_plugin_active( $current_plugin_file ) ) {
				deactivate_plugins( $current_plugin_file );
			}
		}

		// Wipe current plugin directory / file.
		if ( $is_single ) {
			$dest_file = WP_PLUGIN_DIR . '/' . $slug . '.php';
			if ( file_exists( $dest_file ) ) {
				@unlink( $dest_file );
			}
		} else {
			$dest_dir = WP_PLUGIN_DIR . '/' . $slug;
			if ( is_dir( $dest_dir ) ) {
				$this->delete_directory( $dest_dir );
			}
		}

		// Copy backup files into place.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		if ( $is_single ) {
			$backup_files = glob( trailingslashit( $files_dir ) . '*' );
			if ( empty( $backup_files ) ) {
				$this->log_error( "Restore failed for {$slug}: empty backup." );
				return array( 'status' => 'error', 'reason' => 'empty_backup' );
			}
			if ( ! @copy( $backup_files[0], WP_PLUGIN_DIR . '/' . basename( $backup_files[0] ) ) ) {
				$this->log_error( "Restore copy failed for single-file plugin {$slug}." );
				return array( 'status' => 'error', 'reason' => 'copy_failed' );
			}
		} else {
			$dest_dir = WP_PLUGIN_DIR . '/' . $slug;
			wp_mkdir_p( $dest_dir );
			$result = copy_dir( $files_dir, $dest_dir );
			if ( is_wp_error( $result ) ) {
				$this->log_error( "Restore copy failed for {$slug}: " . $result->get_error_message() );
				return array(
					'status' => 'error',
					'reason' => 'copy_failed',
					'error'  => $result->get_error_message(),
				);
			}
		}

		// Clear plugin cache so WordPress sees the restored files.
		wp_clean_plugins_cache();

		// Find and activate the restored plugin.
		$restored_file    = $this->find_plugin_file( $slug );
		$activation_error = '';

		if ( $restored_file ) {
			$activate = activate_plugin( $restored_file );
			if ( is_wp_error( $activate ) ) {
				$activation_error = $activate->get_error_message();
			}
		}

		return array(
			'status'             => 'success',
			'slug'               => $slug,
			'version'            => $manifest['version'],
			'plugin_name'        => $manifest['plugin_name'],
			'activation_error'   => $activation_error,
			'pre_restore_error'  => $pre_restore_error,
		);
	}

	/**
	 * Find the main plugin file for a given slug from the installed plugins list.
	 *
	 * @return string Plugin file path (e.g. "akismet/akismet.php") or empty string.
	 */
	public function find_plugin_file( $slug ) {
		$all_plugins = get_plugins();
		foreach ( $all_plugins as $file => $data ) {
			$file_slug = ( false !== strpos( $file, '/' ) ) ? dirname( $file ) : basename( $file, '.php' );
			if ( $file_slug === $slug ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Check whether a backup's files/ directory contains a single file (single-file plugin).
	 */
	private function is_backup_single_file( $files_dir ) {
		$items = @scandir( $files_dir );
		if ( ! $items ) {
			return false;
		}
		$items = array_diff( $items, array( '.', '..' ) );
		if ( 1 !== count( $items ) ) {
			return false;
		}
		$item = reset( $items );
		return is_file( trailingslashit( $files_dir ) . $item );
	}

	/**
	 * Compare a backup against the currently installed plugin files.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $dir_name Backup directory name.
	 * @return array|null Comparison result, or null if comparison is not possible.
	 */
	public function compare_backup_to_installed( $slug, $dir_name ) {
		$root       = $this->get_backup_root();
		$backup_dir = trailingslashit( $root ) . $slug . '/' . $dir_name;
		$files_dir  = $backup_dir . '/files';

		if ( ! is_dir( $files_dir ) ) {
			return null;
		}

		// Determine installed plugin path.
		$plugin_file = $this->find_plugin_file( $slug );
		if ( ! $plugin_file ) {
			// Plugin is not installed — everything in backup would be added.
			$backup_hashes = $this->hash_directory( $files_dir );
			return array(
				'identical'  => false,
				'added'      => array_keys( $backup_hashes ),
				'removed'    => array(),
				'modified'   => array(),
				'unchanged'  => 0,
				'not_installed' => true,
			);
		}

		$installed_path = $this->get_plugin_source_path( $plugin_file );
		if ( $this->is_single_file_plugin( $plugin_file ) ) {
			// Single-file: compare the one file.
			$backup_files = glob( trailingslashit( $files_dir ) . '*' );
			if ( empty( $backup_files ) ) {
				return null;
			}
			$backup_hash   = md5_file( $backup_files[0] );
			$installed_hash = file_exists( $installed_path ) ? md5_file( $installed_path ) : null;
			if ( null === $installed_hash ) {
				return array(
					'identical'  => false,
					'added'      => array( basename( $backup_files[0] ) ),
					'removed'    => array(),
					'modified'   => array(),
					'unchanged'  => 0,
					'not_installed' => true,
				);
			}
			$same = ( $backup_hash === $installed_hash );
			return array(
				'identical'  => $same,
				'added'      => array(),
				'removed'    => array(),
				'modified'   => $same ? array() : array( basename( $backup_files[0] ) ),
				'unchanged'  => $same ? 1 : 0,
				'not_installed' => false,
			);
		}

		// Directory plugin: hash both sides and compare.
		$backup_hashes    = $this->hash_directory( $files_dir );
		$installed_hashes = $this->hash_directory( $installed_path );

		$added     = array_diff_key( $backup_hashes, $installed_hashes );
		$removed   = array_diff_key( $installed_hashes, $backup_hashes );
		$common    = array_intersect_key( $backup_hashes, $installed_hashes );
		$modified  = array();
		$unchanged = 0;

		foreach ( $common as $rel_path => $backup_hash ) {
			if ( $backup_hash !== $installed_hashes[ $rel_path ] ) {
				$modified[] = $rel_path;
			} else {
				++$unchanged;
			}
		}

		return array(
			'identical'      => empty( $added ) && empty( $removed ) && empty( $modified ),
			'added'          => array_keys( $added ),
			'removed'        => array_keys( $removed ),
			'modified'       => $modified,
			'unchanged'      => $unchanged,
			'not_installed'  => false,
		);
	}

	/**
	 * Build a map of relative_path => md5 hash for all files in a directory.
	 *
	 * @param string $dir Absolute directory path.
	 * @return array Relative path => md5 hash.
	 */
	private function hash_directory( $dir ) {
		$hashes = array();
		if ( ! is_dir( $dir ) ) {
			return $hashes;
		}
		$base = trailingslashit( realpath( $dir ) );
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $items as $item ) {
			if ( $item->isFile() ) {
				$real     = realpath( $item->getPathname() );
				$rel_path = substr( $real, strlen( $base ) );
				$hashes[ $rel_path ] = md5_file( $real );
			}
		}
		return $hashes;
	}

	/**
	 * Append a message to the error log.
	 */
	public function log_error( $message ) {
		$log_file  = trailingslashit( $this->get_backup_root() ) . 'error.log';
		$timestamp = current_time( 'Y-m-d H:i:s' );
		@file_put_contents( $log_file, "[{$timestamp}] {$message}\n", FILE_APPEND );
	}
}
