<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Admin_Page {

	/** @var RG_Backup_Manager */
	private $manager;

	public function __construct( RG_Backup_Manager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Register hooks for the admin page.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_rg_delete_backup', array( $this, 'ajax_delete_backup' ) );
		add_action( 'wp_ajax_rg_manual_backup', array( $this, 'ajax_manual_backup' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Register the Tools → Plugin Backups page.
	 */
	public function add_menu_page() {
		add_management_page(
			__( 'Plugin Backups', 'rollback-guard' ),
			__( 'Plugin Backups', 'rollback-guard' ),
			'manage_options',
			'rollback-guard',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin CSS/JS only on our page.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_rollback-guard' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'rg-admin',
			RG_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			RG_VERSION
		);

		wp_enqueue_script(
			'rg-admin',
			RG_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			RG_VERSION,
			true
		);

		wp_localize_script( 'rg-admin', 'rgAdmin', array(
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'deleteNonce'   => wp_create_nonce( 'rg_delete_backup' ),
			'backupNonce'   => wp_create_nonce( 'rg_manual_backup' ),
			'confirmDelete' => __( 'Are you sure you want to delete this backup? This cannot be undone.', 'rollback-guard' ),
		) );
	}

	/**
	 * Handle form submissions and action links (settings save, allowlist, etc.).
	 */
	public function handle_actions() {
		// Allowlist action from admin notice or backup page.
		if ( isset( $_GET['rg_action'], $_GET['slug'] ) && 'allowlist' === $_GET['rg_action'] ) {
			if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'rg_allowlist' ) ) {
				return;
			}
			$this->manager->add_to_allowlist( sanitize_text_field( $_GET['slug'] ) );
			wp_safe_redirect( admin_url( 'tools.php?page=rollback-guard&rg_msg=allowlisted' ) );
			exit;
		}

		// Remove from allowlist.
		if ( isset( $_GET['rg_action'], $_GET['slug'] ) && 'remove_allowlist' === $_GET['rg_action'] ) {
			if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'rg_remove_allowlist' ) ) {
				return;
			}
			$this->manager->remove_from_allowlist( sanitize_text_field( $_GET['slug'] ) );
			wp_safe_redirect( admin_url( 'tools.php?page=rollback-guard&tab=settings&rg_msg=removed_allowlist' ) );
			exit;
		}

		// Settings save.
		if ( ! isset( $_POST['rg_save_settings_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['rg_save_settings_nonce'], 'rg_save_settings' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		update_option( 'rg_max_versions', max( 1, absint( $_POST['rg_max_versions'] ?? 3 ) ) );
		update_option( 'rg_abort_on_failure', ! empty( $_POST['rg_abort_on_failure'] ) ? 1 : 0 );
		update_option( 'rg_size_limit_mb', max( 1, absint( $_POST['rg_size_limit_mb'] ?? 25 ) ) );
		update_option( 'rg_storage_quota_mb', max( 10, absint( $_POST['rg_storage_quota_mb'] ?? 500 ) ) );

		// Excluded plugins — array of slugs from checkboxes.
		$excluded = isset( $_POST['rg_excluded_plugins'] ) && is_array( $_POST['rg_excluded_plugins'] )
			? array_map( 'sanitize_text_field', $_POST['rg_excluded_plugins'] )
			: array();
		update_option( 'rg_excluded_plugins', $excluded );

		wp_safe_redirect( admin_url( 'tools.php?page=rollback-guard&tab=settings&rg_msg=saved' ) );
		exit;
	}

	/**
	 * AJAX handler: delete a backup.
	 */
	public function ajax_delete_backup() {
		check_ajax_referer( 'rg_delete_backup', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rollback-guard' ) ) );
		}

		$slug     = sanitize_text_field( $_POST['slug'] ?? '' );
		$dir_name = sanitize_file_name( $_POST['dir_name'] ?? '' );

		if ( empty( $slug ) || empty( $dir_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'rollback-guard' ) ) );
		}

		$deleted = $this->manager->delete_backup( $slug, $dir_name );

		if ( $deleted ) {
			wp_send_json_success( array( 'message' => __( 'Backup deleted.', 'rollback-guard' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to delete backup.', 'rollback-guard' ) ) );
		}
	}

	/**
	 * AJAX handler: manually back up a plugin.
	 */
	public function ajax_manual_backup() {
		check_ajax_referer( 'rg_manual_backup', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'rollback-guard' ) ) );
		}

		$plugin_file = sanitize_text_field( $_POST['plugin_file'] ?? '' );
		if ( empty( $plugin_file ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid plugin file.', 'rollback-guard' ) ) );
		}

		// Verify the plugin exists.
		$all_plugins = get_plugins();
		if ( ! isset( $all_plugins[ $plugin_file ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin not found.', 'rollback-guard' ) ) );
		}

		$result = $this->manager->create_backup( $plugin_file, '', 'manual' );

		if ( 'success' === $result['status'] ) {
			wp_send_json_success( array(
				'message' => sprintf(
					/* translators: 1: plugin name, 2: version, 3: size */
					__( 'Backed up %1$s v%2$s (%3$s)', 'rollback-guard' ),
					$result['name'],
					$result['version'],
					size_format( $result['size'] )
				),
			) );
		}

		if ( 'skipped' === $result['status'] ) {
			$messages = array(
				'oversized'      => sprintf(
					/* translators: %s: plugin size */
					__( 'Plugin exceeds size limit (%s). Allow it in Settings first.', 'rollback-guard' ),
					isset( $result['size'] ) ? size_format( $result['size'] ) : ''
				),
				'quota_exceeded' => __( 'Storage quota exceeded. Free up space or increase the quota in Settings.', 'rollback-guard' ),
				'excluded'       => __( 'This plugin is excluded from backups. Change it in Settings.', 'rollback-guard' ),
				'low_disk_space' => __( 'Insufficient disk space.', 'rollback-guard' ),
			);
			$msg = $messages[ $result['reason'] ] ?? __( 'Backup skipped.', 'rollback-guard' );
			wp_send_json_error( array( 'message' => $msg ) );
		}

		$error_detail = isset( $result['error'] ) ? $result['error'] : ( $result['reason'] ?? '' );
		wp_send_json_error( array(
			'message' => sprintf(
				/* translators: %s: error detail */
				__( 'Backup failed: %s', 'rollback-guard' ),
				$error_detail
			),
		) );
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		$current_tab = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'backups';
		$backups     = $this->manager->get_all_backups();
		$total_size  = $this->manager->get_total_backup_size();
		$quota_mb    = (int) get_option( 'rg_storage_quota_mb', 500 );
		$manager     = $this->manager;
		$rg_msg      = isset( $_GET['rg_msg'] ) ? sanitize_text_field( $_GET['rg_msg'] ) : '';

		if ( 'settings' === $current_tab ) {
			include RG_PLUGIN_DIR . 'templates/settings-page.php';
		} else {
			include RG_PLUGIN_DIR . 'templates/admin-page.php';
		}
	}
}
