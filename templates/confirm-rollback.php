<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Variables: $slug, $backup, $backups, $manager

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

	<p class="description" style="margin-top: 10px;">
		<?php esc_html_e( 'A backup of the current version will be created automatically before restoring.', 'rollback-guard' ); ?>
	</p>

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
	$('#rg-confirm-rollback-form').on('submit', function(e) {
		e.preventDefault();
		var $form   = $(this);
		var $btn    = $form.find('#rg-do-rollback');
		var btnText = $btn.val();

		$btn.prop('disabled', true).val('<?php echo esc_js( __( 'Restoring…', 'rollback-guard' ) ); ?>');

		$.post($form.attr('action'), $form.serialize(), function(response) {
			if (response.success) {
				$form.replaceWith(
					'<div class="notice notice-success inline"><p>' + response.data.message + '</p>' +
					'<p><a href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_js( __( 'Go back', 'rollback-guard' ) ); ?></a></p></div>'
				);
			} else {
				$btn.prop('disabled', false).val(btnText);
				$form.before('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
			}
		}).fail(function() {
			$btn.prop('disabled', false).val(btnText);
			$form.before('<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Request failed. Please try again.', 'rollback-guard' ) ); ?></p></div>');
		});
	});
});
</script>
