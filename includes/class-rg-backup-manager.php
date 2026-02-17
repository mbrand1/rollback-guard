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

		// Size gate — skip for manual backups (user explicitly requested it).
		$size_limit = (int) get_option( 'rg_size_limit_mb', 25 );
		if ( 'manual' !== $trigger && $plugin_size > $size_limit * MB_IN_BYTES && ! $this->is_allowlisted( $slug ) ) {
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
		if ( false === $real_backup || false === $real_root || 0 !== strpos( $real_backup, $real_root ) ) {
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
	 * Append a message to the error log.
	 */
	public function log_error( $message ) {
		$log_file  = trailingslashit( $this->get_backup_root() ) . 'error.log';
		$timestamp = current_time( 'Y-m-d H:i:s' );
		@file_put_contents( $log_file, "[{$timestamp}] {$message}\n", FILE_APPEND );
	}
}
