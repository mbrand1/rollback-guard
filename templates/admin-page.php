<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Variables: $backups, $total_size, $quota_mb, $current_tab, $manager, $rg_msg
$quota_bytes  = $quota_mb * MB_IN_BYTES;
$usage_pct    = $quota_bytes > 0 ? min( 100, round( ( $total_size / $quota_bytes ) * 100 ) ) : 0;
$all_plugins  = get_plugins();
$excluded     = (array) get_option( 'rg_excluded_plugins', array() );

// Count totals.
$backup_count = 0;
$plugin_with_backups = 0;
foreach ( $backups as $plugin_backups ) {
	$backup_count += count( $plugin_backups );
	++$plugin_with_backups;
}

// Collect installed plugin slugs so we can find orphaned backups.
$installed_slugs = array();
foreach ( $all_plugins as $pf => $pd ) {
	$installed_slugs[] = ( false !== strpos( $pf, '/' ) ) ? dirname( $pf ) : basename( $pf, '.php' );
}

// Trigger labels used in backup tables.
$trigger_labels = array(
	'auto_pre_update' => __( 'Pre-update', 'rollback-guard' ),
	'manual'          => __( 'Manual', 'rollback-guard' ),
	'pre_restore'     => __( 'Pre-restore', 'rollback-guard' ),
);
?>
<div class="wrap">
	<h1><img src="<?php echo esc_url( RG_PLUGIN_URL . 'assets/img/icon-32x32.png' ); ?>" alt="" style="vertical-align: middle; margin-right: 8px;"><?php esc_html_e( 'Plugin Rollback Guard', 'rollback-guard' ); ?></h1>

	<?php if ( 'allowlisted' === $rg_msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Plugin added to large-plugin allowlist.', 'rollback-guard' ); ?></p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=rollback-guard&tab=backups' ) ); ?>"
		   class="nav-tab <?php echo 'backups' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Backups', 'rollback-guard' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=rollback-guard&tab=settings' ) ); ?>"
		   class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Settings', 'rollback-guard' ); ?>
		</a>
	</nav>

	<!-- Storage summary -->
	<div class="rg-storage-summary">
		<div class="rg-storage-stats">
			<span>
				<?php
				printf(
					/* translators: 1: used space, 2: quota */
					esc_html__( '%1$s used of %2$s quota', 'rollback-guard' ),
					esc_html( size_format( $total_size ) ),
					esc_html( size_format( $quota_bytes ) )
				);
				?>
			</span>
			<span>
				<?php
				printf(
					/* translators: 1: backup count, 2: plugin count */
					esc_html__( '%1$d backups across %2$d plugins', 'rollback-guard' ),
					$backup_count,
					$plugin_with_backups
				);
				?>
			</span>
		</div>
		<div class="rg-storage-bar">
			<div class="rg-storage-bar-fill <?php echo $usage_pct > 90 ? 'rg-bar-warning' : ''; ?>"
				 style="width: <?php echo esc_attr( $usage_pct ); ?>%;"></div>
		</div>
	</div>

	<!-- Back Up All -->
	<div class="rg-backup-all-wrapper">
		<button type="button" class="button button-primary rg-backup-all">
			<?php esc_html_e( 'Back Up All Plugins', 'rollback-guard' ); ?>
		</button>
		<span class="rg-backup-all-progress" style="display: none;"></span>
	</div>

	<!-- Installed plugins -->
	<?php foreach ( $all_plugins as $plugin_file => $plugin_data ) :
		$slug            = ( false !== strpos( $plugin_file, '/' ) ) ? dirname( $plugin_file ) : basename( $plugin_file, '.php' );
		// Skip ourselves.
		if ( 'rollback-guard' === $slug ) {
			continue;
		}
		$plugin_backups  = isset( $backups[ $slug ] ) ? $backups[ $slug ] : array();
		$has_backups     = ! empty( $plugin_backups );
		$is_excluded     = in_array( $slug, $excluded, true );
		$backup_label    = sprintf(
			_n( '%d backup', '%d backups', count( $plugin_backups ), 'rollback-guard' ),
			count( $plugin_backups )
		);
	?>
	<details class="rg-plugin-section" <?php echo $has_backups ? 'open' : ''; ?>>
		<summary>
			<div class="rg-plugin-summary">
				<span class="rg-plugin-info">
					<strong><?php echo esc_html( $plugin_data['Name'] ); ?></strong>
					<span class="rg-plugin-version">v<?php echo esc_html( $plugin_data['Version'] ); ?></span>
					<?php if ( $is_excluded ) : ?>
						<span class="rg-badge rg-badge-excluded"><?php esc_html_e( 'Excluded', 'rollback-guard' ); ?></span>
					<?php elseif ( $has_backups ) : ?>
						<span class="rg-badge rg-badge-backed-up"><?php echo esc_html( $backup_label ); ?></span>
					<?php else : ?>
						<span class="rg-badge rg-badge-none"><?php esc_html_e( 'No backups', 'rollback-guard' ); ?></span>
					<?php endif; ?>
				</span>
				<?php if ( ! $is_excluded ) : ?>
					<button type="button" class="button button-small rg-manual-backup"
							data-plugin-file="<?php echo esc_attr( $plugin_file ); ?>"
							data-plugin-name="<?php echo esc_attr( $plugin_data['Name'] ); ?>">
						<?php esc_html_e( 'Back Up Now', 'rollback-guard' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</summary>

		<div class="rg-plugin-content">
			<?php if ( $has_backups ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Version', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Date', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Files', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Size', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'rollback-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php $rg_is_latest = true; ?>
						<?php foreach ( $plugin_backups as $backup ) : ?>
						<tr>
							<td><?php if ( $rg_is_latest ) : ?><span title="<?php esc_attr_e( 'Most recent backup', 'rollback-guard' ); ?>">&#9679;</span> <?php endif; ?><?php echo esc_html( $backup['version'] ); ?></td>
						<?php $rg_is_latest = false; ?>
							<td>
								<?php
								$date_obj = date_create( $backup['backup_date'] );
								echo $date_obj ? esc_html( $date_obj->format( 'M j, Y g:i A' ) ) : esc_html( $backup['backup_date'] );
								?>
							</td>
							<td>
								<?php
								$trigger = isset( $backup['backup_trigger'] ) ? $backup['backup_trigger'] : 'auto_pre_update';
								echo esc_html( isset( $trigger_labels[ $trigger ] ) ? $trigger_labels[ $trigger ] : $trigger );
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $backup['file_count'] ) ); ?></td>
							<td><?php echo esc_html( size_format( $backup['total_size_bytes'] ) ); ?></td>
							<td>
								<?php
								$restore_url = wp_nonce_url(
									add_query_arg(
										array(
											'page'      => 'rollback-guard',
											'rg_action' => 'confirm_rollback',
											'slug'      => $slug,
											'dir_name'  => $backup['dir_name'],
										),
										admin_url( 'admin.php' )
									),
									'rg_confirm_rollback'
								);
								?>
								<a href="<?php echo esc_url( $restore_url ); ?>" class="button button-small">
									<?php esc_html_e( 'Restore', 'rollback-guard' ); ?>
								</a>
								<button type="button" class="button button-small rg-delete-backup"
										data-slug="<?php echo esc_attr( $slug ); ?>"
										data-dir="<?php echo esc_attr( $backup['dir_name'] ); ?>">
									<?php esc_html_e( 'Delete', 'rollback-guard' ); ?>
								</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="rg-no-backups-inline">
					<?php if ( $is_excluded ) : ?>
						<?php esc_html_e( 'This plugin is excluded from backups. Change it in Settings.', 'rollback-guard' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'No backups yet. Use "Back Up Now" or backups will be created automatically before updates.', 'rollback-guard' ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
	</details>
	<?php endforeach; ?>

	<?php
	// Orphaned backups — plugins that have been removed but still have backups on disk.
	$orphaned = array();
	foreach ( $backups as $b_slug => $b_list ) {
		if ( 'rollback-guard' !== $b_slug && ! in_array( $b_slug, $installed_slugs, true ) ) {
			$orphaned[ $b_slug ] = $b_list;
		}
	}
	?>

	<?php if ( ! empty( $orphaned ) ) : ?>
		<h2 class="rg-section-heading"><?php esc_html_e( 'Removed Plugins', 'rollback-guard' ); ?></h2>

		<?php foreach ( $orphaned as $slug => $plugin_backups ) :
			$display_name = $plugin_backups[0]['plugin_name'];
			$backup_label = sprintf(
				_n( '%d backup', '%d backups', count( $plugin_backups ), 'rollback-guard' ),
				count( $plugin_backups )
			);
		?>
		<details class="rg-plugin-section" open>
			<summary>
				<div class="rg-plugin-summary">
					<span class="rg-plugin-info">
						<strong><?php echo esc_html( $display_name ); ?></strong>
						<span class="rg-badge rg-badge-removed"><?php esc_html_e( 'Not installed', 'rollback-guard' ); ?></span>
						<span class="rg-badge rg-badge-backed-up"><?php echo esc_html( $backup_label ); ?></span>
					</span>
				</div>
			</summary>

			<div class="rg-plugin-content">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Version', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Date', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Files', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Size', 'rollback-guard' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'rollback-guard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php $rg_is_latest = true; ?>
						<?php foreach ( $plugin_backups as $backup ) : ?>
						<tr>
							<td><?php if ( $rg_is_latest ) : ?><span title="<?php esc_attr_e( 'Most recent backup', 'rollback-guard' ); ?>">&#9679;</span> <?php endif; ?><?php echo esc_html( $backup['version'] ); ?></td>
						<?php $rg_is_latest = false; ?>
							<td>
								<?php
								$date_obj = date_create( $backup['backup_date'] );
								echo $date_obj ? esc_html( $date_obj->format( 'M j, Y g:i A' ) ) : esc_html( $backup['backup_date'] );
								?>
							</td>
							<td>
								<?php
								$trigger = isset( $backup['backup_trigger'] ) ? $backup['backup_trigger'] : 'auto_pre_update';
								echo esc_html( isset( $trigger_labels[ $trigger ] ) ? $trigger_labels[ $trigger ] : $trigger );
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $backup['file_count'] ) ); ?></td>
							<td><?php echo esc_html( size_format( $backup['total_size_bytes'] ) ); ?></td>
							<td>
								<?php
								$restore_url = wp_nonce_url(
									add_query_arg(
										array(
											'page'      => 'rollback-guard',
											'rg_action' => 'confirm_rollback',
											'slug'      => $slug,
											'dir_name'  => $backup['dir_name'],
										),
										admin_url( 'admin.php' )
									),
									'rg_confirm_rollback'
								);
								?>
								<a href="<?php echo esc_url( $restore_url ); ?>" class="button button-small">
									<?php esc_html_e( 'Restore', 'rollback-guard' ); ?>
								</a>
								<button type="button" class="button button-small rg-delete-backup"
										data-slug="<?php echo esc_attr( $slug ); ?>"
										data-dir="<?php echo esc_attr( $backup['dir_name'] ); ?>">
									<?php esc_html_e( 'Delete', 'rollback-guard' ); ?>
								</button>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</details>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
