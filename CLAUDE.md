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
- `class-rg-admin-page.php` — settings page and backup browser UI
- `class-rg-db-snapshot.php` — options/transients capture (Phase 2)

Templates in `templates/`, assets in `assets/css/` and `assets/js/`.

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

**Phase 1 (MVP)**: upgrader hooks, file backup with `copy_dir()`, manifest, retention pruning, size gate, storage quota, admin notice, backup browser page, delete backups, security files, single-file plugin support, disk space checks, multisite detection (show incompatibility notice and disable).

**Phase 2**: DB snapshots (options + transients), file restore, DB restore (separate opt-in), ZIP download, compression option.

**Phase 3**: settings UI polish, plugin exclusion list, auto-update hardening, checksum verification, email notifications, WP-Cron pruning.

## Testing Notes

- Test with both single-plugin and bulk updates
- Test with a single-file plugin (Hello Dolly)
- Test the size gate with a large plugin (WooCommerce): verify skip, warning, and allowlist flow
- Set a low storage quota and verify enforcement
- Test low disk space and bad permissions for graceful failure
- Verify retention pruning deletes oldest backups correctly
- `upgrader_pre_install` is lightly used in the ecosystem — test edge cases thoroughly

## Admin UI

Settings page registered at **Tools > Plugin Backups** via `add_management_page()`. Post-update summary displayed via `admin_notices` using a transient set in `upgrader_process_complete`.
