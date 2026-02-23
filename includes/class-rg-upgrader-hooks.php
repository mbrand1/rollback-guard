<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RG_Upgrader_Hooks {

	/** @var RG_Backup_Manager */
	private $manager;

	/** @var array Collected results during an update batch. */
	private $backup_results = array();

	public function __construct( RG_Backup_Manager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Register all upgrader hooks.
	 */
	public function init() {
		add_filter( 'upgrader_pre_install', array( $this, 'backup_before_update' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'after_updates_complete' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'display_backup_notice' ) );
	}

	/**
	 * Look up the incoming version from the update transient.
	 */
	private function get_new_version( $plugin_file ) {
		$update_info = get_site_transient( 'update_plugins' );
		if ( isset( $update_info->response[ $plugin_file ] ) ) {
			return $update_info->response[ $plugin_file ]->new_version;
		}
		return '';
	}

	/**
	 * Hook: upgrader_pre_install — back up a plugin right before its files are replaced.
	 */
	public function backup_before_update( $response, $hook_extra ) {
		// If already an error, pass through.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Only act on plugin updates.
		if ( ! isset( $hook_extra['plugin'] ) ) {
			return $response;
		}

		$plugin_file = $hook_extra['plugin'];
		$new_version = $this->get_new_version( $plugin_file );
		$result      = $this->manager->create_backup( $plugin_file, $new_version );

		$this->backup_results[] = $result;

		// If backup failed and "abort on failure" is enabled, halt the update.
		if ( 'error' === $result['status'] && get_option( 'rg_abort_on_failure', 0 ) ) {
			$label = isset( $result['name'] ) ? $result['name'] : $result['slug'];
			return new WP_Error(
				'rollback_guard_backup_failed',
				sprintf(
					/* translators: %s: plugin name */
					__( 'Rollback Guard: Backup failed for %s. Update aborted.', 'rollback-guard' ),
					$label
				)
			);
		}

		return $response;
	}

	/**
	 * Hook: upgrader_process_complete — store results for the admin notice.
	 */
	public function after_updates_complete( $upgrader, $hook_extra ) {
		if ( ! isset( $hook_extra['type'] ) || 'plugin' !== $hook_extra['type'] ) {
			return;
		}

		if ( empty( $this->backup_results ) ) {
			return;
		}

		// Store results as a user-specific transient (5 min expiry).
		$user_id = get_current_user_id();
		set_transient( 'rg_backup_results_' . $user_id, $this->backup_results, 5 * MINUTE_IN_SECONDS );

		$this->backup_results = array();
	}

	/**
	 * Hook: admin_notices — display a summary of what was backed up.
	 */
	public function display_backup_notice() {
		$user_id = get_current_user_id();
		$results = get_transient( 'rg_backup_results_' . $user_id );

		if ( empty( $results ) ) {
			return;
		}

		delete_transient( 'rg_backup_results_' . $user_id );

		$successes = array();
		$skipped   = array();
		$errors    = array();

		foreach ( $results as $r ) {
			switch ( $r['status'] ) {
				case 'success':
					$successes[] = $r;
					break;
				case 'skipped':
					$skipped[] = $r;
					break;
				case 'error':
					$errors[] = $r;
					break;
			}
		}

		if ( empty( $successes ) && empty( $skipped ) && empty( $errors ) ) {
			return;
		}

		$page_url = admin_url( 'admin.php?page=rollback-guard' );

		echo '<div class="notice notice-info is-dismissible"><p><strong>';
		esc_html_e( 'Rollback Guard', 'rollback-guard' );
		echo '</strong></p>';

		if ( ! empty( $successes ) ) {
			echo '<p>';
			printf(
				/* translators: %d: number of plugins */
				esc_html( _n(
					'Backed up %d plugin before update:',
					'Backed up %d plugins before update:',
					count( $successes ),
					'rollback-guard'
				) ),
				count( $successes )
			);
			echo '</p><ul style="list-style:disc;margin-left:20px;">';
			foreach ( $successes as $s ) {
				printf(
					'<li>%s %s (%s)</li>',
					esc_html( $s['name'] ),
					esc_html( $s['version'] ),
					esc_html( size_format( $s['size'] ) )
				);
			}
			echo '</ul>';
		}

		if ( ! empty( $skipped ) ) {
			foreach ( $skipped as $sk ) {
				echo '<p>';
				if ( 'oversized' === $sk['reason'] ) {
					$allowlist_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'      => 'rollback-guard',
								'rg_action' => 'allowlist',
								'slug'      => $sk['slug'],
							),
							admin_url( 'admin.php' )
						),
						'rg_allowlist'
					);
					printf(
						/* translators: 1: plugin name, 2: size, 3: allowlist URL */
						wp_kses(
							__( '&#9888; %1$s (%2$s) &mdash; skipped, exceeds size limit. <a href="%3$s">Allow this plugin</a>', 'rollback-guard' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_html( $sk['name'] ),
						esc_html( size_format( $sk['size'] ) ),
						esc_url( $allowlist_url )
					);
				} elseif ( 'quota_exceeded' === $sk['reason'] ) {
					echo '&#9888; ';
					printf(
						/* translators: %s: plugin name */
						esc_html__( '%s — skipped, storage quota exceeded.', 'rollback-guard' ),
						esc_html( $sk['name'] )
					);
				} elseif ( 'excluded' === $sk['reason'] ) {
					// Silently skip excluded plugins.
				} else {
					echo '&#9888; ';
					printf(
						/* translators: %s: plugin name */
						esc_html__( '%s — skipped.', 'rollback-guard' ),
						isset( $sk['name'] ) ? esc_html( $sk['name'] ) : esc_html( $sk['slug'] )
					);
				}
				echo '</p>';
			}
		}

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $e ) {
				echo '<p style="color:#d63638;">';
				printf(
					/* translators: 1: plugin name, 2: reason */
					esc_html__( 'Backup failed for %1$s: %2$s', 'rollback-guard' ),
					isset( $e['name'] ) ? esc_html( $e['name'] ) : esc_html( $e['slug'] ),
					esc_html( $e['reason'] )
				);
				echo '</p>';
			}
		}

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $page_url ),
			esc_html__( 'View Backups', 'rollback-guard' )
		);
		echo '</div>';
	}
}
