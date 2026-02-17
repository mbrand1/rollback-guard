# Plugin Rollback Guard (PRG) — Development Blueprint

## Overview

A WordPress plugin that automatically backs up plugin directories (and optionally associated settings from `wp_options`) immediately before plugin updates. Provides a browsable, version-controlled archive of previous plugin states with one-click restore capability. Designed for standard single-site WordPress installations.

**Problem**: Existing backup plugins handle full-site or full-database backups. Nothing specifically targets the common pain point of a plugin update breaking a site, where you just need to roll back *that one plugin*.

**Solution**: Hook into WordPress's upgrade pipeline, snapshot each plugin's directory right before it gets replaced, and provide a simple admin UI to browse and restore previous versions.

---

## Backup Storage Location

Backups are stored in the WordPress uploads directory, following the same convention used by WooCommerce (`wc-logs/`), Gravity Forms, WPForms, and other well-behaved plugins:

```
wp-content/uploads/rg-backups/
```

### Why `wp-content/uploads/` and not elsewhere

| Location | Why NOT |
|---|---|
| `wp-content/plugin-backups/` | Creates a new directory in WP core structure — not best practice |
| Inside our own plugin directory | If PRG itself is updated or deleted, all backups are destroyed |
| `wp-content/uploads/rg-backups/` | ✅ WordPress-sanctioned writable directory. Survives plugin deletion. Standard pattern. |

### Path Resolution

**Never hardcode the path.** Use `wp_upload_dir()` to respect custom upload locations and the `UPLOADS` constant:

```php
$upload_dir  = wp_upload_dir();
$backup_root = trailingslashit( $upload_dir['basedir'] ) . 'rg-backups';
```

### Security

On plugin activation (and verified on each backup operation), create:

```
wp-content/uploads/rg-backups/
  .htaccess       # "deny from all" — prevents Apache direct access
  index.php       # "<?php // Silence is golden." — prevents directory listing
```

Also add an nginx-compatible note in the plugin readme since `.htaccess` is Apache-only. Recommend server-level config for nginx users.

---

## Directory Structure

### Plugin Files

```
wp-content/plugins/rollback-guard/
  rollback-guard.php        # Main plugin file, hooks registration
  includes/
    class-rg-backup-manager.php   # Core backup/restore/prune logic
    class-rg-db-snapshot.php      # Settings snapshot - options & transients (Phase 2)
    class-rg-admin-page.php       # Settings page & backup browser UI
    class-rg-upgrader-hooks.php   # All WordPress upgrader hook integration
    class-rg-manifest.php         # Manifest read/write for backup metadata
  templates/
    admin-page.php                 # Backup listing & management UI template
    settings-page.php              # Plugin settings UI template
  assets/
    css/admin.css                  # Admin page styles
    js/admin.js                    # Admin page interactions (restore confirm, etc.)
  uninstall.php                    # Cleanup on plugin deletion
```

### Backup Storage Structure

```
wp-content/uploads/rg-backups/
  .htaccess
  index.php
  akismet/
    4.2.1_2025-02-17_143022/
      files/                       # Complete recursive copy of plugin directory
      db-snapshot.json             # Associated settings data (Phase 2, when enabled)
      manifest.json                # Backup metadata
    4.1.0_2025-01-10_091500/
      files/
      manifest.json
  woocommerce/
    9.5.1_2025-02-15_082311/
      files/
      manifest.json
  ...
```

### Manifest Format

Each backup includes a `manifest.json`:

```json
{
  "plugin_slug": "akismet",
  "plugin_name": "Akismet Anti-spam: Spam Protection",
  "version": "4.2.1",
  "new_version": "4.3.0",
  "backup_date": "2025-02-17T14:30:22+00:00",
  "wp_version": "6.7.2",
  "php_version": "8.2.14",
  "file_count": 42,
  "total_size_bytes": 251648,
  "db_snapshot_included": false,
  "backup_trigger": "manual_update",
  "checksum": "sha256:abcdef...",
  "size_limit_applied": false
}
```

---

## WordPress Hooks & Integration

### Primary Hooks

| Hook | Type | When | Our Use |
|---|---|---|---|
| `upgrader_pre_install` | Filter | Right before plugin files are replaced by `Plugin_Upgrader` | **Primary trigger** — snapshot the plugin directory |
| `upgrader_process_complete` | Action | After all updates finish | Log results, display admin notice summary |
| `pre_auto_update` | Action | Before any auto-update batch begins | Ensure backup logic also fires for auto-updates |
| `auto_update_plugin` | Filter | Controls whether a plugin auto-updates | Not modified, but we hook alongside it |
| `admin_notices` | Action | Renders admin notices | Display post-update backup summary |

### Hook Implementation Detail

#### `upgrader_pre_install`

This is the critical hook. It fires per-plugin during the upgrade process and receives data about which plugin is being updated.

```php
add_filter( 'upgrader_pre_install', [ $this, 'backup_before_update' ], 10, 2 );

public function backup_before_update( $response, $hook_extra ) {
    // $hook_extra['plugin'] contains the plugin file path (e.g., "akismet/akismet.php")
    // Extract slug, determine source directory, perform backup
    // Return $response unmodified on success
    // Return WP_Error to abort update on failure (if setting enabled)
}
```

#### `upgrader_process_complete`

```php
add_action( 'upgrader_process_complete', [ $this, 'after_updates_complete' ], 10, 2 );

public function after_updates_complete( $upgrader, $hook_extra ) {
    // $hook_extra['type'] === 'plugin'
    // $hook_extra['plugins'] contains array of updated plugin paths
    // Store transient with backup summary for admin notice display
}
```

### Bulk Update Handling

When multiple plugins are updated at once (bulk action), `upgrader_pre_install` fires for each one sequentially. The flow is:

1. User clicks "Update All"
2. For each plugin: `upgrader_pre_install` → backup → files replaced
3. After all complete: `upgrader_process_complete` fires once with full list
4. On next admin page load: notice displays full summary

---

## Core Backup Flow

### Pre-Update Sequence

1. **Hook fires** — `upgrader_pre_install` receives plugin identifier
2. **Extract slug** — Parse from plugin path (e.g., `akismet/akismet.php` → `akismet`)
3. **Read current version** — From plugin header metadata via `get_plugin_data()`
4. **Check exclusions** — Skip if plugin is in the excluded list
5. **Check plugin size** — Calculate directory size; if exceeding size limit, check allowlist. If not allowed, skip with warning and return early.
6. **Check storage quota** — Verify this backup won't exceed total storage quota
7. **Check disk space** — Verify sufficient free space available
8. **Create backup directory** — `{backup_root}/{slug}/{version}_{Ymd}_{His}/`
9. **Copy files** — Recursive copy of `WP_PLUGIN_DIR/{slug}/` → `backup_dir/files/` using `copy_dir()` from `wp-admin/includes/file.php` (WordPress native function)
10. **DB snapshot** (Phase 2, when enabled) — Capture matching options and transients
11. **Write manifest** — `manifest.json` with all metadata
12. **Verify backup** — Quick integrity check (file count matches, key files present)
13. **Retention pruning** — If backups for this plugin exceed max versions, delete oldest
14. **Return** — Pass through `$response` on success; optionally return `WP_Error` to abort update on failure

### Error Handling

If backup fails:
- **Configurable behavior**: Either abort the update (`WP_Error`) or log warning and proceed
- Default: **warn and proceed** (don't block updates by default — that's more dangerous)
- Log failure details to `rg-backups/error.log`
- Include failure notice in post-update admin summary

---

## Database Snapshot Strategy

DB snapshots are limited to high-confidence heuristic matching only — settings we can reliably identify as belonging to a plugin. We are **not** attempting to capture every possible database footprint a plugin might create. This keeps the feature simple and safe.

### What We Capture

**Options table (`wp_options`):**
- Rows where `option_name` starts with the plugin slug (e.g., `akismet_*`, `woocommerce_*`)
- This covers the vast majority of plugin settings

**Transients:**
- `_transient_{slug}*` and `_site_transient_{slug}*`
- Ephemeral but useful for complete settings state capture

### What We Do NOT Capture

- **Custom tables** — Too complex, too risky. A plugin like WooCommerce has dozens of custom tables with millions of rows. Backing up and restoring those is full-database-backup territory and out of scope.
- **Options that don't match the slug** — Some plugins store options under non-obvious keys. We accept this limitation rather than guessing wrong.
- **Plugin self-declaration / diff-based detection** — Out of scope. Keeping it simple.

### DB Snapshot Format

```json
{
  "snapshot_date": "2025-02-17T14:30:22+00:00",
  "options": [
    {
      "name": "akismet_strictness",
      "value": "0",
      "autoload": "yes"
    }
  ],
  "transients": [
    {
      "name": "_transient_akismet_ssl_check",
      "value": "...",
      "expiration": 1708272000
    }
  ]
}
```

---

## Restore Flow

1. **User clicks Restore** in admin UI → confirmation modal with warnings
2. **Deactivate** current version of the plugin via `deactivate_plugins()`
3. **Remove** current plugin directory from `WP_PLUGIN_DIR`
4. **Copy** backup files from `backup_dir/files/` → `WP_PLUGIN_DIR/{slug}/`
5. **DB restore** (Phase 2, optional with separate confirmation):
   - Reimport `wp_options` rows from snapshot
   - Reimport transients from snapshot
6. **Reactivate** plugin via `activate_plugin()`
7. **Clear caches** — flush relevant transients, object cache
8. **Log** restore action in backup summary
9. **Admin notice** — confirm successful restore with version info

### Restore Safeguards

- Always require explicit user confirmation
- DB restore is a separate opt-in step (file restore alone is usually sufficient)
- Create a backup of the *current* version before restoring (so you can undo the undo)
- Verify file permissions before attempting restore

---

## Admin UI

### Location

**Tools → Plugin Backups** (using `add_management_page()`)

### Backup Browser

- **Per-plugin accordion/collapsible sections**
- Each plugin with backups gets a section showing:
  - Plugin name and current version
  - List of stored backup versions, each showing:
    - Version number
    - Backup date/time
    - File count and total size
    - Whether DB snapshot was included
    - Action buttons: **Restore** | **Download ZIP** | **Delete**
- **Storage summary** at top: total backups, total disk usage, available space

### Post-Update Admin Notice

Displayed via `admin_notices` after `upgrader_process_complete`, using a stored transient:

> **Plugin Rollback Guard**: Backed up 3 plugins before update:
> - Akismet 4.2.1 → 4.3.0 (backup: 245 KB)
> - Yoast SEO 21.5 → 21.6 (backup: 1.2 MB)
> - Contact Form 7 5.8.4 → 5.8.5 (backup: 380 KB)
> - ⚠ WooCommerce 9.5.1 (34 MB) — skipped, exceeds size limit. [Allow this plugin]
>
> [View Backups]

Dismissible. Links to the backup browser page.

### Settings Tab

| Setting | Default | Description |
|---|---|---|
| Max versions per plugin | 3 | How many backup versions to retain per plugin |
| Include DB snapshots | Off | Capture associated `wp_options` and transients matching plugin slug |
| Abort update on backup failure | Off | Return WP_Error if backup fails (risky — off by default) |
| Plugin size limit | 25 MB | Plugins larger than this are skipped unless explicitly allowed |
| Large plugin allowlist | (none) | Plugins exceeding size limit that should be backed up anyway |
| Total storage quota | 500 MB | Maximum total disk usage for all backups |
| Excluded plugins | (none) | Plugins to skip during backup |
| Compress backups | Off | ZIP the files/ directory to save space (slower) |

---

## Edge Cases

### Single-File Plugins

Plugins without a subdirectory (e.g., `hello.php` directly in `plugins/`):
- Detect via plugin path (no `/` in the path after `plugins/`)
- Back up the single file rather than a directory
- Restore replaces the single file

### Must-Use Plugins (`mu-plugins/`)

**Not supported.** Must-use plugins are typically deployed by developers doing advanced server-level work and are not managed through the standard WordPress update pipeline. Out of scope.

### Large Plugins

Some plugins are massive (WooCommerce 30MB+, Elementor similar). Backing these up repeatedly can fill disk fast. We handle this with a **size gate**:

- **Default size limit**: 25 MB per plugin (configurable in settings)
- **Plugins exceeding the limit are skipped by default** — they are NOT backed up unless explicitly allowed
- When a large plugin is skipped, the admin notice shows a warning: "⚠ WooCommerce (34 MB) was skipped — exceeds size limit. [Allow this plugin]"
- **"Allow this plugin"** link adds the plugin to an explicit allowlist in settings, acknowledging the disk space risk
- The settings page shows current disk usage and estimated per-backup cost for each allowed large plugin
- A **total storage quota** (default 500 MB) acts as a hard cap — if a backup would exceed it, it's skipped with a warning
- Optional compression setting (ZIP the `files/` directory) helps mitigate size for users who opt in large plugins

### File Permissions

- Verify write access to backup location before attempting
- Verify write access to plugin directory before restore
- Fail gracefully with clear error messages

### Multisite

**Not supported.** Multisite introduces network-activated plugins, per-site activation states, and shared plugin directories across sites. The complexity isn't worth it for v1 (or likely ever). If activated on a multisite install, display an admin notice: "Plugin Rollback Guard is not compatible with WordPress Multisite installations."

### Concurrent Operations

- Lock file during backup to prevent race conditions
- Especially relevant during bulk updates or overlapping cron auto-updates

### Our Own Plugin Updates

- PRG should back itself up before its own update (it can — the hook fires before files are replaced)
- Restoration of PRG itself may need special handling (restoring the plugin that's doing the restoring)

---

## Development Phases

### Phase 1 — MVP (ship this first)

- `upgrader_pre_install` hook to capture plugin directory before update
- Recursive file copy using `copy_dir()`
- Manifest generation
- Retention limit (max N versions per plugin, prune oldest)
- Post-update admin notice listing what was backed up
- Basic admin page listing all backups with version, date, size
- Delete individual backups
- Security: `.htaccess` + `index.php` in backup directory
- Single-file plugin support
- Disk space checking
- **Plugin size gate**: skip plugins exceeding size limit, warn in admin notice, allowlist opt-in
- **Total storage quota** enforcement
- Multisite detection: display incompatibility notice and disable

### Phase 2 — DB Snapshots & Restore

- Heuristic DB identification (options and transients matching plugin slug)
- DB snapshot export as JSON
- File-based restore functionality (copy files back, reactivate)
- DB restore (optional, separate confirmation — options and transients only)
- Download backup as ZIP
- Compression option for large allowed plugins

### Phase 3 — Polish

- Full settings UI refinement
- Exclude specific plugins
- Auto-update integration hardening
- Backup integrity verification (checksums)
- Email notification on backup failure
- Scheduled backup pruning via WP-Cron

---

## Key WordPress Functions to Use

| Function | Purpose |
|---|---|
| `copy_dir()` | Recursive directory copy (in `wp-admin/includes/file.php`) |
| `wp_upload_dir()` | Get uploads path (respects custom locations) |
| `get_plugin_data()` | Read plugin header (version, name, etc.) |
| `get_plugins()` | List all installed plugins |
| `deactivate_plugins()` | Deactivate before restore |
| `activate_plugin()` | Reactivate after restore |
| `WP_Filesystem` | File operations with proper permissions |
| `set_transient()` | Store post-update notice data |
| `wp_mkdir_p()` | Recursive directory creation |
| `size_format()` | Human-readable file sizes in UI |
| `recurse_dirsize()` | Calculate directory size |

---

## Notes for Claude Code Session

- Start with a clean WordPress install
- Build Phase 1 first — it's immediately useful and testable
- Test with both single-plugin and bulk updates
- Test with a single-file plugin (like Hello Dolly)
- Verify the backup directory is created with proper security files
- Verify retention pruning works correctly
- Test what happens when disk space is low or permissions are wrong
- The `upgrader_pre_install` hook is well-documented but lightly used — test edge cases thoroughly
- **Test the size gate**: install a large plugin (WooCommerce) and verify it gets skipped with a proper warning, then allowlist it and verify backup works
- **Test storage quota**: set a low quota and verify enforcement
- **No multisite**: if detected, show incompatibility notice and bail
- **No mu-plugins**: ignore entirely
- **DB snapshots are Phase 2** — don't build this in Phase 1
