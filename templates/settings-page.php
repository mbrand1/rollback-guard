<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Variables: $backups, $total_size, $quota_mb, $current_tab, $manager, $rg_msg
$max_versions    = (int) get_option( 'rg_max_versions', 3 );
$abort_on_failure = (int) get_option( 'rg_abort_on_failure', 0 );
$size_limit_mb   = (int) get_option( 'rg_size_limit_mb', 25 );
$storage_quota   = (int) get_option( 'rg_storage_quota_mb', 500 );
$excluded        = (array) get_option( 'rg_excluded_plugins', array() );
$allowlist       = (array) get_option( 'rg_large_plugin_allowlist', array() );
$all_plugins     = get_plugins();
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Plugin Rollback Guard', 'rollback-guard' ); ?></h1>

	<?php if ( 'saved' === $rg_msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'rollback-guard' ); ?></p></div>
	<?php elseif ( 'removed_allowlist' === $rg_msg ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Plugin removed from allowlist.', 'rollback-guard' ); ?></p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=rollback-guard&tab=backups' ) ); ?>"
		   class="nav-tab <?php echo 'backups' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Backups', 'rollback-guard' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'tools.php?page=rollback-guard&tab=settings' ) ); ?>"
		   class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Settings', 'rollback-guard' ); ?>
		</a>
	</nav>

	<form method="post" action="">
		<?php wp_nonce_field( 'rg_save_settings', 'rg_save_settings_nonce' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="rg_max_versions"><?php esc_html_e( 'Max versions per plugin', 'rollback-guard' ); ?></label>
				</th>
				<td>
					<input type="number" id="rg_max_versions" name="rg_max_versions"
						   value="<?php echo esc_attr( $max_versions ); ?>" min="1" max="50" class="small-text">
					<p class="description"><?php esc_html_e( 'How many backup versions to retain per plugin. Oldest are pruned first.', 'rollback-guard' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Abort update on backup failure', 'rollback-guard' ); ?></th>
				<td>
					<label for="rg_abort_on_failure">
						<input type="checkbox" id="rg_abort_on_failure" name="rg_abort_on_failure" value="1"
							<?php checked( $abort_on_failure ); ?>>
						<?php esc_html_e( 'Cancel the plugin update if the backup fails', 'rollback-guard' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Not recommended. By default, a failed backup logs a warning and the update proceeds normally.', 'rollback-guard' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rg_size_limit_mb"><?php esc_html_e( 'Plugin size limit', 'rollback-guard' ); ?></label>
				</th>
				<td>
					<input type="number" id="rg_size_limit_mb" name="rg_size_limit_mb"
						   value="<?php echo esc_attr( $size_limit_mb ); ?>" min="1" class="small-text"> MB
					<p class="description"><?php esc_html_e( 'Plugins larger than this are skipped unless explicitly allowed below.', 'rollback-guard' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="rg_storage_quota_mb"><?php esc_html_e( 'Total storage quota', 'rollback-guard' ); ?></label>
				</th>
				<td>
					<input type="number" id="rg_storage_quota_mb" name="rg_storage_quota_mb"
						   value="<?php echo esc_attr( $storage_quota ); ?>" min="10" class="small-text"> MB
					<p class="description"><?php esc_html_e( 'Maximum total disk usage for all backups.', 'rollback-guard' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Large plugin allowlist', 'rollback-guard' ); ?></th>
				<td>
					<?php if ( empty( $allowlist ) ) : ?>
						<p class="description"><?php esc_html_e( 'No plugins have been allowlisted. Oversized plugins can be allowed from the post-update notice.', 'rollback-guard' ); ?></p>
					<?php else : ?>
						<ul class="rg-allowlist">
						<?php foreach ( $allowlist as $allowed_slug ) :
							$remove_url = wp_nonce_url(
								add_query_arg(
									array(
										'page'      => 'rollback-guard',
										'tab'       => 'settings',
										'rg_action' => 'remove_allowlist',
										'slug'      => $allowed_slug,
									),
									admin_url( 'tools.php' )
								),
								'rg_remove_allowlist'
							);
						?>
							<li>
								<code><?php echo esc_html( $allowed_slug ); ?></code>
								&mdash; <a href="<?php echo esc_url( $remove_url ); ?>"><?php esc_html_e( 'Remove', 'rollback-guard' ); ?></a>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Excluded plugins', 'rollback-guard' ); ?></th>
				<td>
					<fieldset>
					<?php foreach ( $all_plugins as $file => $data ) :
						$file_slug = ( false !== strpos( $file, '/' ) ) ? dirname( $file ) : basename( $file, '.php' );
						// Don't show ourselves.
						if ( 'rollback-guard' === $file_slug ) {
							continue;
						}
					?>
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" name="rg_excluded_plugins[]"
								   value="<?php echo esc_attr( $file_slug ); ?>"
								   <?php checked( in_array( $file_slug, $excluded, true ) ); ?>>
							<?php echo esc_html( $data['Name'] ); ?>
							<span class="description">(<?php echo esc_html( $file_slug ); ?>)</span>
						</label>
					<?php endforeach; ?>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Checked plugins will not be backed up before updates.', 'rollback-guard' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'rollback-guard' ) ); ?>
	</form>
</div>
