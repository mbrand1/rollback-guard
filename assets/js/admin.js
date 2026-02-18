/* Rollback Guard — Admin JS */
(function ($) {
	'use strict';

	// Prevent <details> toggle when clicking buttons inside <summary>.
	$(document).on('click', '.rg-plugin-section summary', function (e) {
		if ($(e.target).closest('.button').length) {
			e.preventDefault();
		}
	});

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
					var $tbody = $section.find('tbody');
					var remaining = $tbody.find('tr').length;
					if (remaining === 0) {
						$section.find('table').remove();
						$section.find('.rg-plugin-content').html(
							'<p class="rg-no-backups-inline">No backups yet. Use "Back Up Now" or backups will be created automatically before updates.</p>'
						);
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

		var $btn = $(this);
		var pluginFile = $btn.data('plugin-file');

		$btn.prop('disabled', true).text('Backing up\u2026');

		$.post(rgAdmin.ajaxUrl, {
			action:      'rg_manual_backup',
			plugin_file: pluginFile,
			_wpnonce:    rgAdmin.backupNonce
		}, function (response) {
			if (response.success) {
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

	// Restore a backup.
	$(document).on('click', '.rg-restore-backup', function (e) {
		e.preventDefault();

		var $btn    = $(this);
		var name    = $btn.data('name');
		var version = $btn.data('version');

		var msg = 'Restore ' + name + ' to v' + version + '?\n\n';
		msg += 'This will:\n';
		msg += '- Back up the current version first (if installed)\n';
		msg += '- Remove the existing plugin directory\n';
		msg += '- Copy the backed-up files into place\n';
		msg += '- Reactivate the plugin';

		if (!confirm(msg)) {
			return;
		}

		$btn.prop('disabled', true).text('Restoring\u2026');

		$.post(rgAdmin.ajaxUrl, {
			action:   'rg_restore_backup',
			slug:     $btn.data('slug'),
			dir_name: $btn.data('dir'),
			_wpnonce: rgAdmin.restoreNonce
		}, function (response) {
			if (response.success) {
				alert(response.data.message);
				window.location.reload();
			} else {
				alert(response.data && response.data.message ? response.data.message : 'Restore failed.');
				$btn.prop('disabled', false).text('Restore');
			}
		}).fail(function () {
			alert('Request failed. Please try again.');
			$btn.prop('disabled', false).text('Restore');
		});
	});
})(jQuery);
