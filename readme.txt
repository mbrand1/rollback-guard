=== Rollback Guard ===
Contributors: brandon
Tags: backup, rollback, restore, updates, plugins
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically backs up plugin directories before updates with one-click restore.

== Description ==

Rollback Guard snapshots your plugin files before every update, giving you a one-click way to restore a previous version if something goes wrong.

**How it works:**

* Hooks into the WordPress upgrader pipeline (`upgrader_pre_install`) to create a full file copy of each plugin right before its files are replaced.
* Backups are stored in `wp-content/uploads/rg-backups/` with a manifest containing version, date, and file count.
* After updates complete, an admin notice summarizes what was backed up (or skipped).
* Browse and manage all backups from the **Plugin Backups** admin page.
* Restore any backup with a single click — a confirmation page shows you exactly what will change.
* A "Rollback" link appears on the Plugins list page for any plugin that has a backup.

**Safety features:**

* **Size gate** — plugins larger than the configurable limit (default 25 MB) are skipped unless explicitly allowlisted.
* **Storage quota** — total backup storage is capped (default 500 MB).
* **Retention pruning** — only the N most recent backups per plugin are kept (default 3).
* **Backup before restore** — restoring a backup first creates a backup of the current version, so you can undo the undo.
* **Disk space checks** — backups are skipped when free disk space is low.
* **Lock file** — prevents race conditions during concurrent or bulk updates.

**Not supported:**

* WordPress Multisite — the plugin detects multisite and disables itself with a notice.
* Must-use plugins (mu-plugins).

== Installation ==

1. Upload the `rollback-guard` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Plugin Backups** in the admin sidebar to view backups and settings.

That's it — backups happen automatically before every plugin update.

== Frequently Asked Questions ==

= Where are backups stored? =

In `wp-content/uploads/rg-backups/{plugin-slug}/`. Each backup is a timestamped directory containing a full copy of the plugin files and a `manifest.json`.

= What happens if a backup fails? =

By default, the update proceeds and you see a warning in the admin notice. You can change this behavior in Settings by enabling "Abort update on backup failure."

= Can I back up a plugin manually? =

Yes. On the Plugin Backups page, click "Back Up Now" for any plugin.

= What about single-file plugins? =

Single-file plugins (like Hello Dolly) are detected automatically. The single PHP file is backed up instead of a directory.

= Does this back up the database? =

Not yet. Database snapshots (options and transients) are planned for a future release.

= Can I exclude certain plugins from being backed up? =

Yes. Go to Plugin Backups → Settings and check the plugins you want to exclude.

= How do I restore a plugin? =

Either click the "Rollback" link on the Plugins list page, or go to Plugin Backups, expand a plugin, and click "Restore" on any backup version. Both paths take you to a confirmation page before anything changes.

== Screenshots ==

1. Plugin Backups page showing backed-up plugins with version history.
2. Settings page with size limit, storage quota, and retention options.
3. Restore confirmation page.
4. Post-update admin notice summarizing backup results.

== Changelog ==

= 1.0.0 =
* Initial release.
* Automatic backup before plugin updates via `upgrader_pre_install`.
* Manual backup button per plugin.
* One-click restore with confirmation page.
* Rollback link on the Plugins list page.
* Size gate with allowlist for large plugins.
* Storage quota enforcement.
* Retention pruning (configurable max versions per plugin).
* Backup-before-restore safety net.
* Plugin exclusion list.
* Single-file plugin support.
* Disk space and lock file safety checks.
* Admin notice with post-update backup summary.
* Multisite detection and incompatibility notice.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
