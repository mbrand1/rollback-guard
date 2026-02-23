<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Variables: $slug, $backup, $backups, $manager, $comparison

// Determine where to send the user back to.
$referer  = wp_get_referer();
$back_url = ( $referer && false !== strpos( $referer, 'plugins.php' ) )
	? admin_url( 'plugins.php' )
	: admin_url( 'admin.php?page=rollback-guard' );

$plugin_name   = $backup['plugin_name'];
$version       = $backup['version'];
$backup_date   = date_create( $backup['backup_date'] );
$date_display  = $backup_date ? $backup_date->format( 'M j, Y g:i A' ) : $backup['backup_date'];
$file_count    = $backup['file_count'];
$size          = $backup['total_size_bytes'];
$dir_name      = $backup['dir_name'];

// Current installed version (if still installed).
$current_file    = $manager->find_plugin_file( $slug );
$current_version = '';
if ( $current_file ) {
	$current_data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $current_file );
	$current_version = $current_data['Version'];
}
?>
<div class="wrap">
	<h1><img src="<?php echo esc_url( RG_PLUGIN_URL . 'assets/img/icon-32x32.png' ); ?>" alt="" style="vertical-align: middle; margin-right: 8px;"><?php esc_html_e( 'Confirm Rollback', 'rollback-guard' ); ?></h1>

	<div class="notice notice-warning inline" style="margin-top: 15px;">
		<p>
			<?php
			printf(
				/* translators: 1: plugin name, 2: backup version */
				esc_html__( 'You are about to restore %1$s to version %2$s.', 'rollback-guard' ),
				'<strong>' . esc_html( $plugin_name ) . '</strong>',
				'<strong>' . esc_html( $version ) . '</strong>'
			);
			?>
		</p>
	</div>

	<table class="widefat striped" style="max-width: 600px; margin-top: 15px;">
		<tbody>
			<?php if ( $current_version ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Current version', 'rollback-guard' ); ?></th>
				<td><?php echo esc_html( $current_version ); ?></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Restore to', 'rollback-guard' ); ?></th>
				<td><?php echo esc_html( $version ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Backup date', 'rollback-guard' ); ?></th>
				<td><?php echo esc_html( $date_display ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Files', 'rollback-guard' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( $file_count ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Backup size', 'rollback-guard' ); ?></th>
				<td><?php echo esc_html( size_format( $size ) ); ?></td>
			</tr>
		</tbody>
	</table>

	<?php if ( $comparison ) : ?>
		<?php if ( $comparison['identical'] ) : ?>
			<div class="notice notice-warning inline" style="margin-top: 15px;">
				<p><strong><?php esc_html_e( 'No changes detected.', 'rollback-guard' ); ?></strong>
				<?php esc_html_e( 'This backup is identical to the currently installed version. Restoring will not change any files.', 'rollback-guard' ); ?></p>
			</div>
		<?php else : ?>
			<?php
			$mod_count = count( $comparison['modified'] );
			$add_count = count( $comparison['added'] );
			$rem_count = count( $comparison['removed'] );
			$unch_count = $comparison['unchanged'];
			$not_installed = ! empty( $comparison['not_installed'] );
			?>
			<div class="notice notice-info inline" style="margin-top: 15px;">
				<p><strong><?php esc_html_e( 'File comparison', 'rollback-guard' ); ?></strong></p>
				<ul style="list-style: disc; margin-left: 20px;">
					<?php if ( $mod_count > 0 ) : ?>
						<li><?php
							printf(
								/* translators: %d: number of files */
								esc_html( _n( '%d file modified', '%d files modified', $mod_count, 'rollback-guard' ) ),
								$mod_count
							);
						?></li>
					<?php endif; ?>
					<?php if ( $add_count > 0 ) : ?>
						<li><?php
							if ( $not_installed ) {
								printf(
									/* translators: %d: number of files */
									esc_html( _n( '%d file to install', '%d files to install', $add_count, 'rollback-guard' ) ),
									$add_count
								);
							} else {
								printf(
									/* translators: %d: number of files */
									esc_html( _n( '%d file added', '%d files added', $add_count, 'rollback-guard' ) ),
									$add_count
								);
							}
						?></li>
					<?php endif; ?>
					<?php if ( $rem_count > 0 ) : ?>
						<li><?php
							printf(
								/* translators: %d: number of files */
								esc_html( _n( '%d file removed', '%d files removed', $rem_count, 'rollback-guard' ) ),
								$rem_count
							);
						?></li>
					<?php endif; ?>
					<?php if ( $unch_count > 0 ) : ?>
						<li><?php
							printf(
								/* translators: %d: number of files */
								esc_html( _n( '%d file unchanged', '%d files unchanged', $unch_count, 'rollback-guard' ) ),
								$unch_count
							);
						?></li>
					<?php endif; ?>
				</ul>

				<?php if ( $mod_count > 0 || $add_count > 0 || $rem_count > 0 ) : ?>
				<details style="margin-bottom: 10px;">
					<summary style="cursor: pointer;"><?php esc_html_e( 'Show changed files', 'rollback-guard' ); ?></summary>
					<div style="max-height: 200px; overflow-y: auto; margin-top: 5px; font-size: 12px; font-family: monospace;">
						<?php if ( $mod_count > 0 ) : ?>
							<?php foreach ( $comparison['modified'] as $f ) : ?>
								<div style="color: #b26200;">~ <?php echo esc_html( $f ); ?></div>
							<?php endforeach; ?>
						<?php endif; ?>
						<?php if ( $add_count > 0 ) : ?>
							<?php foreach ( $comparison['added'] as $f ) : ?>
								<div style="color: #007017;">+ <?php echo esc_html( $f ); ?></div>
							<?php endforeach; ?>
						<?php endif; ?>
						<?php if ( $rem_count > 0 ) : ?>
							<?php foreach ( $comparison['removed'] as $f ) : ?>
								<div style="color: #d63638;">&minus; <?php echo esc_html( $f ); ?></div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</details>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( ! $comparison || ! $comparison['identical'] ) : ?>
	<p class="description" style="margin-top: 10px;">
		<?php esc_html_e( 'A backup of the current version will be created automatically before restoring.', 'rollback-guard' ); ?>
	</p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" id="rg-confirm-rollback-form" style="margin-top: 20px;">
		<input type="hidden" name="action" value="rg_restore_backup">
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'rg_restore_backup' ) ); ?>">
		<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>">
		<input type="hidden" name="dir_name" value="<?php echo esc_attr( $dir_name ); ?>">

		<?php
		submit_button(
			sprintf(
				/* translators: %s: version */
				__( 'Restore to v%s', 'rollback-guard' ),
				$version
			),
			'primary',
			'rg-do-rollback',
			false
		);
		?>
		<a href="<?php echo esc_url( $back_url ); ?>" class="button" style="margin-left: 10px;">
			<?php esc_html_e( 'Cancel', 'rollback-guard' ); ?>
		</a>
	</form>
</div>

<script>
jQuery(function($) {
	var rgBackUrl  = <?php echo wp_json_encode( esc_url( $back_url ) ); ?>;
	var rgGoBack   = <?php echo wp_json_encode( __( 'Go back', 'rollback-guard' ) ); ?>;
	var rgFailed   = <?php echo wp_json_encode( __( 'Request failed. Please try again.', 'rollback-guard' ) ); ?>;

	$('#rg-confirm-rollback-form').on('submit', function(e) {
		e.preventDefault();
		var $form   = $(this);
		var $btn    = $form.find('#rg-do-rollback');
		var btnText = $btn.val();

		$btn.prop('disabled', true).val(<?php echo wp_json_encode( __( 'Restoring…', 'rollback-guard' ) ); ?>);

		$.post($form.attr('action'), $form.serialize(), function(response) {
			if (response.success) {
				var $notice = $('<div class="notice notice-success inline"></div>');
				$notice.append($('<p></p>').text(response.data.message));
				$notice.append($('<p></p>').append($('<a></a>').attr('href', rgBackUrl).text(rgGoBack)));
				$form.replaceWith($notice);
			} else {
				$btn.prop('disabled', false).val(btnText);
				var $err = $('<div class="notice notice-error inline"></div>');
				$err.append($('<p></p>').text(response.data && response.data.message ? response.data.message : rgFailed));
				$form.before($err);
			}
		}).fail(function() {
			$btn.prop('disabled', false).val(btnText);
			var $err = $('<div class="notice notice-error inline"></div>');
			$err.append($('<p></p>').text(rgFailed));
			$form.before($err);
		});
	});
});
</script>
