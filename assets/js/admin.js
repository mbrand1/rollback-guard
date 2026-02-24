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
							'<p class="rg-no-backups-inline">' + rgAdmin.noBackupsYet + '</p>'
						);
						var $badge = $section.find('.rg-badge');
						$badge.removeClass('rg-badge-backed-up').addClass('rg-badge-none').text(rgAdmin.noBackups);
					} else {
						$section.find('.rg-badge-backed-up').text(
							remaining === 1 ? rgAdmin.backupSingular : rgAdmin.backupPlural.replace('%d', remaining)
						);
					}
				});
			} else {
				alert(response.data && response.data.message ? response.data.message : rgAdmin.deleteFailed);
				$btn.removeClass('rg-deleting').prop('disabled', false);
			}
		}).fail(function () {
			alert(rgAdmin.requestFailed);
			$btn.removeClass('rg-deleting').prop('disabled', false);
		});
	});

	// Manual backup.
	$(document).on('click', '.rg-manual-backup', function (e) {
		e.preventDefault();

		var $btn = $(this);
		var pluginFile = $btn.data('plugin-file');

		$btn.prop('disabled', true).text(rgAdmin.backingUp);

		$.post(rgAdmin.ajaxUrl, {
			action:      'rg_manual_backup',
			plugin_file: pluginFile,
			_wpnonce:    rgAdmin.backupNonce
		}, function (response) {
			if (response.success) {
				window.location.reload();
			} else {
				alert(response.data && response.data.message ? response.data.message : rgAdmin.backupFailed);
				$btn.prop('disabled', false).text(rgAdmin.backUpNow);
			}
		}).fail(function () {
			alert(rgAdmin.requestFailed);
			$btn.prop('disabled', false).text(rgAdmin.backUpNow);
		});
	});
	// Back up all plugins.
	$(document).on('click', '.rg-backup-all', function (e) {
		e.preventDefault();

		var $btn = $(this);
		var $progress = $('.rg-backup-all-progress');
		var plugins = [];

		// Collect all eligible plugins from existing Back Up Now buttons.
		$('.rg-manual-backup').each(function () {
			plugins.push({
				file: $(this).data('plugin-file'),
				name: $(this).data('plugin-name')
			});
		});

		if (plugins.length === 0) {
			alert(rgAdmin.noPluginsToBackup);
			return;
		}

		$btn.prop('disabled', true);
		$progress.show();

		var total = plugins.length;
		var current = 0;
		var successes = 0;
		var failures = [];

		function backupNext() {
			if (current >= total) {
				// All done — show summary and reload.
				var msg = rgAdmin.backupAllDone
					.replace('%1$d', successes)
					.replace('%2$d', total);
				if (failures.length > 0) {
					msg += '\n\n' + rgAdmin.backupAllFailed + '\n' + failures.join('\n');
				}
				alert(msg);
				window.location.reload();
				return;
			}

			var plugin = plugins[current];
			$progress.text(
				rgAdmin.backupAllProgress
					.replace('%1$d', current + 1)
					.replace('%2$d', total)
					.replace('%3$s', plugin.name)
			);

			$.post(rgAdmin.ajaxUrl, {
				action:      'rg_manual_backup',
				plugin_file: plugin.file,
				_wpnonce:    rgAdmin.backupNonce
			}, function (response) {
				if (response.success) {
					successes++;
				} else {
					var reason = response.data && response.data.message ? response.data.message : rgAdmin.backupFailed;
					failures.push(plugin.name + ': ' + reason);
				}
				current++;
				backupNext();
			}).fail(function () {
				failures.push(plugin.name + ': ' + rgAdmin.requestFailed);
				current++;
				backupNext();
			});
		}

		backupNext();
	});
})(jQuery);
