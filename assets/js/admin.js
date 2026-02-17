/* Rollback Guard — Admin JS */
(function ($) {
	'use strict';

	// Delete a backup.
	$(document).on('click', '.rg-delete-backup', function (e) {
		e.preventDefault();

		if (!confirm(rgAdmin.confirmDelete)) {
			return;
		}

		var $btn = $(this);
		var $row = $btn.closest('tr');
		var $section = $btn.closest('.rg-plugin-section');

		$btn.addClass('rg-deleting').prop('disabled', true);

		$.post(rgAdmin.ajaxUrl, {
			action:   'rg_delete_backup',
			slug:     $btn.data('slug'),
			dir_name: $btn.data('dir'),
			_wpnonce: rgAdmin.deleteNonce
		}, function (response) {
			if (response.success) {
				$row.fadeOut(300, function () {
					$(this).remove();
					// Update badge and check if table is now empty.
					var $tbody = $section.find('tbody');
					var remaining = $tbody.find('tr').length;
					if (remaining === 0) {
						$section.find('table').remove();
						$section.find('.rg-plugin-content').html(
							'<p class="rg-no-backups-inline">No backups yet. Use "Back Up Now" or backups will be created automatically before updates.</p>'
						);
						// Update badge.
						var $badge = $section.find('.rg-badge');
						$badge.removeClass('rg-badge-backed-up').addClass('rg-badge-none').text('No backups');
					} else {
						$section.find('.rg-badge-backed-up').text(
							remaining === 1 ? '1 backup' : remaining + ' backups'
						);
					}
				});
			} else {
				alert(response.data && response.data.message ? response.data.message : 'Failed to delete backup.');
				$btn.removeClass('rg-deleting').prop('disabled', false);
			}
		}).fail(function () {
			alert('Request failed. Please try again.');
			$btn.removeClass('rg-deleting').prop('disabled', false);
		});
	});

	// Manual backup.
	$(document).on('click', '.rg-manual-backup', function (e) {
		e.preventDefault();
		e.stopPropagation();

		var $btn = $(this);
		var pluginFile = $btn.data('plugin-file');
		var pluginName = $btn.data('plugin-name');

		$btn.prop('disabled', true).text('Backing up…');

		$.post(rgAdmin.ajaxUrl, {
			action:      'rg_manual_backup',
			plugin_file: pluginFile,
			_wpnonce:    rgAdmin.backupNonce
		}, function (response) {
			if (response.success) {
				// Reload to show the new backup in the list.
				window.location.reload();
			} else {
				alert(response.data && response.data.message ? response.data.message : 'Backup failed.');
				$btn.prop('disabled', false).text('Back Up Now');
			}
		}).fail(function () {
			alert('Request failed. Please try again.');
			$btn.prop('disabled', false).text('Back Up Now');
		});
	});
})(jQuery);
