# Rollback Guard

Automatically backs up WordPress plugin directories before updates with one-click restore.

## What it does

Rollback Guard hooks into the WordPress upgrader pipeline to snapshot every plugin right before its files are replaced during an update. If an update breaks something, you can restore the previous version with one click.

- **Automatic backups** — created transparently before every plugin update
- **Manual backups** — back up any plugin on demand from the admin page
- **One-click restore** — confirmation page shows what will change, then restores in seconds
- **Rollback from Plugins page** — a "Rollback" link appears next to Deactivate for any plugin with a backup
- **Backup-before-restore** — restoring creates a safety backup of the current version first

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Single-site only (multisite is not supported)

## Installation

1. Download the [latest release](../../releases/latest) ZIP.
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Activate **Rollback Guard**.
4. Backups happen automatically. Manage them from **Plugin Backups** in the admin sidebar.

### Manual installation

Extract the `rollback-guard` folder into `wp-content/plugins/` and activate from the Plugins page.

## Configuration

Go to **Plugin Backups → Settings** to adjust:

| Setting | Default | Description |
|---------|---------|-------------|
| Max versions per plugin | 3 | Oldest backups are pruned automatically |
| Size limit | 25 MB | Plugins larger than this are skipped (allowlist available) |
| Storage quota | 500 MB | Total disk space cap for all backups |
| Abort on failure | Off | Optionally halt updates if backup fails |
| Excluded plugins | None | Plugins to never back up |

## How backups are stored

```
wp-content/uploads/rg-backups/
└── akismet/
    ├── 5.3.3_20260220_143022/
    │   ├── files/
    │   │   └── (full plugin copy)
    │   └── manifest.json
    └── 5.3.2_20260115_091500/
        ├── files/
        └── manifest.json
```

Each backup directory contains a complete copy of the plugin files and a `manifest.json` with version, date, file count, and size metadata.

The backup root is protected with `.htaccess` (deny all) and an empty `index.php`.

## Safety features

- **Size gate** — large plugins are skipped unless explicitly allowlisted, with an admin notice offering a one-click "Allow" link
- **Storage quota** — prevents backups from consuming unlimited disk space
- **Disk space check** — skips backup if free space is low
- **Lock file** — prevents race conditions during bulk or concurrent updates
- **Retention pruning** — only keeps the configured number of versions per plugin
- **Path traversal protection** — backup and restore paths are validated against the backup root

## Development

### Project structure

```
rollback-guard/
├── rollback-guard.php          # Bootstrap and hooks
├── includes/
│   ├── class-rg-backup-manager.php   # Backup, restore, prune logic
│   ├── class-rg-upgrader-hooks.php   # WordPress upgrader integration
│   ├── class-rg-admin-page.php       # Admin page and AJAX handlers
│   └── class-rg-manifest.php         # manifest.json read/write
├── templates/
│   ├── admin-page.php                # Backup browser
│   ├── settings-page.php             # Settings tab
│   └── confirm-rollback.php          # Restore confirmation
├── assets/
│   ├── css/admin.css
│   ├── js/admin.js
│   └── img/                          # Plugin icons
└── readme.txt                        # WordPress.org format
```

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
