# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Plugin Rollback Guard (PRG) is a WordPress plugin that snapshots plugin directories before updates, providing one-click restore. It hooks into the WordPress upgrader pipeline to create per-plugin, version-tagged backups. Single-site only — multisite and mu-plugins are explicitly out of scope.

The full specification lives in `rollback-guard-blueprint.md`.

## Architecture

### Plugin Structure

Main file: `rollback-guard.php` (hooks registration, bootstrap)

Core classes in `includes/`:
- `class-rg-backup-manager.php` — backup, restore, prune logic
- `class-rg-upgrader-hooks.php` — WordPress upgrader hook integration
- `class-rg-manifest.php` — manifest.json read/write
- `class-rg-admin-page.php` — admin page, backup browser, rollback link on Plugins page
- `class-rg-db-snapshot.php` — options/transients capture (Phase 2, not yet created)

Templates in `templates/`:
- `admin-page.php` — backup browser with storage summary, per-plugin backup tables
- `settings-page.php` — plugin settings form
- `confirm-rollback.php` — restore confirmation page (used by both Plugins page rollback link and backup browser Restore buttons)

Assets in `assets/css/`, `assets/js/`, and `assets/img/` (plugin icons).

### Backup Storage

Backups go in `wp-content/uploads/rg-backups/` (resolved via `wp_upload_dir()`, never hardcoded). Structure: `{slug}/{version}_{Ymd}_{His}/files/` + `manifest.json`.

Security files (`.htaccess`, `index.php`) must exist in the backup root — created on activation and verified before each backup.

### Critical Hook: `upgrader_pre_install`

This is the primary trigger. It fires per-plugin during upgrades, receives `$hook_extra['plugin']` (e.g., `akismet/akismet.php`), and must return `$response` unmodified on success or `WP_Error` to abort (if that setting is enabled). Default behavior on backup failure: warn and proceed (never block updates by default).

Bulk updates fire `upgrader_pre_install` sequentially for each plugin, then `upgrader_process_complete` once at the end.

### Key Design Decisions

- **Size gate**: plugins exceeding the size limit (default 25 MB) are skipped unless explicitly allowlisted. Admin notice warns and offers "Allow this plugin" link.
- **Storage quota**: hard cap (default 500 MB) on total backup disk usage.
- **Retention**: max N versions per plugin (default 3), oldest pruned automatically.
- **Single-file plugins**: detected by absence of `/` in plugin path; back up the single file instead of a directory.
- **Backup-before-restore**: restoring creates a backup of the current version first (undo the undo).
- **Lock file**: used during backup to prevent race conditions in bulk/cron updates.
- **DB snapshots (Phase 2 only)**: heuristic match on `wp_options` rows and transients where name starts with plugin slug. No custom tables.

### WordPress APIs to Use

Use `copy_dir()` (from `wp-admin/includes/file.php`) for recursive copies, `WP_Filesystem` for file operations, `wp_mkdir_p()` for directory creation, `get_plugin_data()` for version/name, `recurse_dirsize()` for size checks, `size_format()` for display.

## Development Phases

**Phase 1 (MVP)**: COMPLETE. Upgrader hooks, file backup with `copy_dir()`, manifest, retention pruning, size gate, storage quota, admin notice, backup browser page, delete backups, security files, single-file plugin support, disk space checks, multisite detection, lock file.

**Phase 2**: PARTIALLY DONE. File restore and backup-before-restore are complete. Manual backup button is done. Remaining: DB snapshots (options + transients), DB restore (separate opt-in), ZIP download, compression option.

**Phase 3**: PARTIALLY DONE. Settings UI and plugin exclusion list are complete. Remaining: auto-update hardening, checksum verification, email notifications, WP-Cron pruning.

## Testing Notes

- Test with both single-plugin and bulk updates
- Test with a single-file plugin (Hello Dolly)
- Test the size gate with a large plugin (WooCommerce): verify skip, warning, and allowlist flow
- Set a low storage quota and verify enforcement
- Test low disk space and bad permissions for graceful failure
- Verify retention pruning deletes oldest backups correctly
- `upgrader_pre_install` is lightly used in the ecosystem — test edge cases thoroughly

## Admin UI

Top-level menu item **Plugin Backups** registered via `add_menu_page()` with custom icon (`assets/img/icon-16x16.png`), position 81. URLs use `admin.php?page=rollback-guard` (not `tools.php`).

Post-update summary displayed via `admin_notices` using a transient set in `upgrader_process_complete`.

### Rollback from Plugins Page

A "Rollback" action link appears on each plugin's row in the Plugins list (via `plugin_action_links` filter) when backups exist. It links to a confirmation page (`confirm-rollback.php`) that shows backup details and requires explicit confirmation before restoring. The same confirmation page is used by Restore buttons in the backup browser.

### Icons

Plugin icons live in `assets/img/`: `icon-16x16.png` (admin menu), `icon-32x32.png` (page titles), `icon-128x128.png`, `icon-256x256.png` (wp.org).

### JavaScript Internationalization

All user-facing strings in `admin.js` are passed via `wp_localize_script()` in the `rgAdmin` object. Never hard-code English strings in JS.
