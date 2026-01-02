# BlitzCDN Media Offload — Internal Knowledge Base

> **Internal Documentation** — This document serves as the central knowledge base for the BlitzCDN WordPress plugin codebase. It documents every feature, component, and implementation detail of the system.

---

## Table of Contents

1. [Overview](#overview)
2. [Technology Stack](#technology-stack)
3. [Plugin Architecture](#plugin-architecture)
4. [File Structure](#file-structure)
5. [Core Components](#core-components)
   - [Core.php — Main Orchestrator](#corephp--main-orchestrator)
   - [AppwriteClient.php — Appwrite SDK Wrapper](#appwriteclientphp--appwrite-sdk-wrapper)
   - [UploadHandler.php — Two-Phase Upload System](#uploadhandlerphp--two-phase-upload-system)
   - [UrlRewriter.php — Dynamic URL Rewriting](#urlrewriterphp--dynamic-url-rewriting)
   - [BackgroundMigrator.php — Server-Side Migration](#backgroundmigratorphp--server-side-migration)
   - [Redownloader.php — Goodbye Procedure](#redownloaderphp--goodbye-procedure)
   - [Compatibility.php — Plugin Compatibility Layer](#compatibilityphp--plugin-compatibility-layer)
   - [Diagnostics.php — System Health Checks](#diagnosticsphp--system-health-checks)
   - [CLI.php — WP-CLI Commands](#cliphp--wp-cli-commands)
6. [Admin Components](#admin-components)
   - [Settings.php — Admin Settings Page](#settingsphp--admin-settings-page)
   - [Migrator.php — AJAX Migration Handler](#migratorphp--ajax-migration-handler)
7. [Frontend JavaScript](#frontend-javascript)
8. [Configuration](#configuration)
   - [WordPress Settings](#wordpress-settings)
   - [Environment Variables](#environment-variables)
9. [Data Storage & Metadata](#data-storage--metadata)
10. [WordPress Hooks Integration](#wordpress-hooks-integration)
11. [Feature Details](#feature-details)
    - [Automatic Media Offloading](#automatic-media-offloading)
    - [URL Rewriting](#url-rewriting)
    - [Migration Tools](#migration-tools)
    - [Goodbye Procedure](#goodbye-procedure)
    - [Content URL Rewriting](#content-url-rewriting)
12. [Plugin Constants](#plugin-constants)
13. [AJAX Endpoints](#ajax-endpoints)
14. [Error Handling & Logging](#error-handling--logging)
15. [Debugging](#debugging)

---

## Overview

BlitzCDN is a WordPress plugin that offloads Media Library files to Appwrite Storage and serves them through a CDN. The plugin integrates deeply with WordPress's media handling system to provide:

- **Automatic file offloading** to Appwrite Storage on upload
- **CDN delivery** through a custom domain (e.g., `files.blitzcdn.net`)
- **Two-phase upload system** handling originals and WordPress-generated image sizes
- **Safe local file deletion** after verified remote upload
- **Bulk migration tools** for existing media (browser-based and background)
- **Goodbye procedure** to redownload all files back to WordPress
- **Dynamic URL rewriting** for seamless CDN integration
- **Upload tracking** via Appwrite Database for user-level management

---

## Technology Stack

| Component           | Details                               |
| ------------------- | ------------------------------------- |
| **Language**        | PHP 7.4+                              |
| **Framework**       | WordPress Plugin API (WordPress 5.0+) |
| **Remote Storage**  | Appwrite Storage                      |
| **SDK**             | Appwrite PHP SDK ^10.0                |
| **Background Jobs** | WooCommerce Action Scheduler ^3.7     |
| **Package Manager** | Composer (PSR-4 autoloading)          |
| **Frontend**        | jQuery (WordPress bundled)            |

### Dependencies (`composer.json`)

```json
{
  "require": {
    "php": ">=7.4",
    "appwrite/appwrite": "^10.0",
    "woocommerce/action-scheduler": "^3.7"
  }
}
```

---

## Plugin Architecture

The plugin follows a modular architecture with a central orchestrator (`Core.php`) that initializes all components:

```
┌─────────────────────────────────────────────────────────────────┐
│                       WordPress Core                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────┐     ┌─────────────────┐     ┌──────────────┐  │
│  │ Upload Hooks │────▶│  UploadHandler  │────▶│ AppwriteClient│  │
│  └──────────────┘     └─────────────────┘     └──────────────┘  │
│                              │                       │           │
│                              ▼                       ▼           │
│                       ┌─────────────┐         ┌───────────┐     │
│                       │  PostMeta   │         │ Appwrite  │     │
│                       │  Storage    │         │ Storage + │     │
│                       └─────────────┘         │ Database  │     │
│                              │                └───────────┘     │
│                              ▼                                   │
│  ┌──────────────┐     ┌─────────────────┐                       │
│  │ Attachment   │────▶│   UrlRewriter   │                       │
│  │ URL Filters  │     └─────────────────┘                       │
│  └──────────────┘                                               │
│                                                                  │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │                     Admin Interface                        │  │
│  │  ┌─────────────┐   ┌──────────────┐   ┌────────────────┐  │  │
│  │  │  Settings   │   │   Migrator   │   │ BackgroundMigr │  │  │
│  │  │   Page      │   │    (AJAX)    │   │ (Action Sched) │  │  │
│  │  └─────────────┘   └──────────────┘   └────────────────┘  │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## File Structure

```
blitzcdn-wp-plugin/
├── blitzcdn.php                 # Main plugin entry point
├── composer.json                # Composer dependencies
├── composer.lock                # Locked dependency versions
├── .env.example                 # Environment configuration template
├── .env                         # Environment configuration (not tracked)
├── README.md                    # This knowledge base
├── debug-db.php                 # Standalone Appwrite Database test script
│
├── includes/                    # Core plugin classes (namespace: BlitzCDN\)
│   ├── Core.php                 # Main orchestrator (singleton)
│   ├── AppwriteClient.php       # Appwrite SDK wrapper
│   ├── UploadHandler.php        # Two-phase upload system
│   ├── UrlRewriter.php          # Dynamic URL rewriting
│   ├── BackgroundMigrator.php   # Action Scheduler-based migration
│   ├── Redownloader.php         # Goodbye procedure (redownload from Appwrite)
│   ├── Compatibility.php        # Plugin compatibility layer
│   ├── Diagnostics.php          # System diagnostics
│   └── CLI.php                  # WP-CLI commands
│
├── admin/                       # Admin components (namespace: BlitzCDN\Admin\)
│   ├── Settings.php             # Settings page UI and registration
│   └── Migrator.php             # AJAX handlers for migration tools
│
├── assets/                      # Static assets
│   └── js/
│       └── migration.js         # Frontend migration/redownload logic
│
├── node-scripts/                # Standalone Node/Bun scripts
│   ├── index.ts                 # TypeScript utility
│   └── ...
│
└── vendor/                      # Composer dependencies (not tracked)
```

---

## Core Components

### Core.php — Main Orchestrator

**Namespace:** `BlitzCDN\Core`  
**Pattern:** Singleton

The central orchestrator that initializes all plugin components. It manages the plugin lifecycle and serves as the entry point.

#### Key Responsibilities:

- Loads Action Scheduler from Composer vendor directory
- Initializes all component classes
- Registers activation/deactivation hooks
- Provides getter methods for accessing components

#### Singleton Access:

```php
$blitzcdn = \BlitzCDN\Core::get_instance();
```

#### Component Initialization Order:

1. Load Action Scheduler (`load_action_scheduler()`)
2. Initialize `AppwriteClient`
3. Initialize `UploadHandler` (depends on AppwriteClient)
4. Initialize `UrlRewriter`
5. Initialize `Compatibility`
6. Initialize `BackgroundMigrator`
7. Initialize `Redownloader` (depends on AppwriteClient)
8. Check Action Scheduler availability
9. Initialize Admin components (Settings, Migrator) — _only in admin context_

#### Public Methods:

| Method                      | Returns              | Description                               |
| --------------------------- | -------------------- | ----------------------------------------- |
| `get_instance()`            | `Core`               | Returns singleton instance                |
| `get_upload_handler()`      | `UploadHandler`      | Access upload handler                     |
| `get_background_migrator()` | `BackgroundMigrator` | Access background migrator                |
| `get_redownloader()`        | `Redownloader`       | Access redownloader                       |
| `activate()`                | `void`               | Sets default options on plugin activation |
| `deactivate()`              | `void`               | Cleanup on deactivation (currently no-op) |

#### Default Options (set on activation):

```php
[
    'project_id' => '',
    'api_key' => '',
    'bucket_id' => '',
    'cdn_domain' => '',
    'endpoint' => 'https://cloud.appwrite.io/v1',
    'serve_from_cdn' => false,
    'safe_delete' => false,
    'delete_remote' => false,
]
```

---

### AppwriteClient.php — Appwrite SDK Wrapper

**Namespace:** `BlitzCDN\AppwriteClient`

Manages all interactions with the Appwrite API, including Storage and Database operations.

#### Configuration Sources (priority order):

1. System environment variables (`getenv()`)
2. PHP `$_ENV` superglobal
3. `.env` file in plugin root

#### Environment Variables:

| Variable                 | Required | Description                                            |
| ------------------------ | -------- | ------------------------------------------------------ |
| `APPWRITE_PROJECT_ID`    | Yes      | Appwrite project ID                                    |
| `APPWRITE_API_KEY`       | Yes      | API key with storage.read, storage.write permissions   |
| `APPWRITE_BUCKET_ID`     | Yes      | Storage bucket ID                                      |
| `APPWRITE_ENDPOINT`      | No       | API endpoint (default: `https://cloud.appwrite.io/v1`) |
| `APPWRITE_CDN_DOMAIN`    | No       | Custom CDN domain                                      |
| `APPWRITE_DB_ID`         | No       | Database ID for upload tracking                        |
| `APPWRITE_COLLECTION_ID` | No       | Collection ID for upload tracking                      |

#### Public Methods:

| Method                | Parameters                 | Returns             | Description                           |
| --------------------- | -------------------------- | ------------------- | ------------------------------------- |
| `is_configured()`     | —                          | `bool`              | Check if client has valid credentials |
| `get_bucket_id()`     | —                          | `string`            | Get bucket ID                         |
| `get_project_id()`    | —                          | `string`            | Get project ID                        |
| `get_cdn_domain()`    | —                          | `string`            | Get CDN domain                        |
| `get_endpoint()`      | —                          | `string`            | Get API endpoint                      |
| `get_db_id()`         | —                          | `string`            | Get database ID                       |
| `get_collection_id()` | —                          | `string`            | Get collection ID                     |
| `upload_file()`       | `$file_path`, `$file_name` | `string` or `false` | Upload file, returns file ID          |
| `delete_file()`       | `$file_id`                 | `bool`              | Delete file from storage              |
| `download_file()`     | `$file_id`                 | `string` or `false` | Download file content                 |
| `create_document()`   | `$data`                    | `array` or `false`  | Create tracking document in database  |

#### Upload Tracking Document Schema:

```php
[
    'email' => 'user@example.com',      // Account email from settings
    'fileId' => 'appwrite-file-id',     // Appwrite storage file ID
    'originalUrl' => 'https://...',     // Original WordPress URL
    'createdAt' => '2024-01-01T00:00:00Z' // ISO 8601 timestamp
]
```

---

### UploadHandler.php — Two-Phase Upload System

**Namespace:** `BlitzCDN\UploadHandler`

Manages the WordPress media upload workflow with a two-phase approach to handle both original files and generated image sizes.

#### Why Two Phases?

WordPress generates intermediate image sizes (thumbnail, medium, large, etc.) **after** the original file is uploaded. The two-phase system ensures:

1. Original file is uploaded immediately (Phase 1)
2. Generated sizes are uploaded after WordPress creates them (Phase 2)

#### Phase 1: Original File Upload

**Hook:** `wp_handle_upload` (filter)

Triggered immediately after WordPress places a file in `wp-content/uploads/`.

**Flow:**

1. Check if AppwriteClient is configured
2. Verify account email is set in settings
3. Upload original file to Appwrite Storage
4. Cache file ID in static property for Phase 2
5. Create tracking document in Appwrite Database

#### Metadata Save

**Hook:** `add_attachment` (action)

Associates the cached file ID with the WordPress attachment post.

**Metadata Saved:**

- `_blitzcdn_file_id`: Appwrite file ID for original
- `_blitzcdn_cdn_url`: CDN URL for original

#### Phase 2: Intermediate Sizes

**Hook:** `wp_generate_attachment_metadata` (filter)

Triggered after WordPress generates all image sizes.

**Flow:**

1. Verify original file metadata exists (upload if missing)
2. Iterate through all sizes in `$metadata['sizes']`
3. Upload each size to Appwrite
4. Store size metadata in `_blitzcdn_sizes` postmeta
5. **Rewrite URLs in post content** (local → CDN)
6. Safe delete local files if enabled
7. Fire `blitzcdn_upload_complete` action

#### Content URL Rewriting (on upload)

When files are successfully uploaded, the handler automatically rewrites URLs in:

- All public post types' content
- Attachment descriptions
- Post meta values

This handles:

- HTTP and HTTPS URL variants
- URL-encoded versions
- JSON-encoded URLs (Gutenberg blocks)

#### Safe Delete Logic

When `safe_delete` option is enabled AND all uploads succeed:

1. Delete original file from `wp-content/uploads/`
2. Delete all intermediate size files

**Important:** Deletion only occurs if ALL uploads (original + all sizes) succeed.

#### Attachment Deletion

**Hook:** `delete_attachment` (action)

When `delete_remote` option is enabled:

1. Delete original file from Appwrite
2. Delete all size files from Appwrite

---

### UrlRewriter.php — Dynamic URL Rewriting

**Namespace:** `BlitzCDN\UrlRewriter`

Dynamically rewrites WordPress attachment URLs to CDN URLs at runtime. Only active when `serve_from_cdn` setting is enabled.

#### Hooked Filters:

| Filter                        | Priority | Description              |
| ----------------------------- | -------- | ------------------------ |
| `wp_get_attachment_url`       | 10       | Main attachment URL      |
| `wp_get_attachment_image_src` | 10       | Image src array          |
| `wp_calculate_image_srcset`   | 10       | Responsive image srcset  |
| `image_downsize`              | 10       | Image dimension handling |

#### URL Rewriting Logic:

**Full Size Images:**

```php
$cdn_url = get_post_meta($post_id, '_blitzcdn_cdn_url', true);
```

**Intermediate Sizes:**

```php
$sizes_meta = get_post_meta($attachment_id, '_blitzcdn_sizes', true);
$cdn_url = $sizes_meta[$size_name]['url'];
```

#### CDN URL Format:

```
https://{cdn_domain}/storage/buckets/{bucket_id}/files/{file_id}/view?project={project_id}
```

If no CDN domain is configured, the direct Appwrite endpoint is used.

#### Srcset Handling:

The `filter_srcset()` method maps WordPress srcset entries (keyed by width) to the correct CDN URLs by matching widths from image metadata.

---

### BackgroundMigrator.php — Server-Side Migration

**Namespace:** `BlitzCDN\BackgroundMigrator`

Handles bulk migration of existing media using Action Scheduler for reliable background processing.

#### Constants:

| Constant        | Value                                   | Description                    |
| --------------- | --------------------------------------- | ------------------------------ |
| `ACTION_HOOK`   | `'blitzcdn_background_migration_batch'` | Action Scheduler hook name     |
| `OPTION_STATUS` | `'blitzcdn_migration_status'`           | Option name for status storage |
| `BATCH_SIZE`    | `5`                                     | Attachments per batch          |

#### Status Option Schema:

```php
[
    'status' => 'idle|running|stopped|completed',
    'processed' => 0,           // Number processed
    'total' => 100,             // Total at start
    'start_time' => 1234567890, // Unix timestamp
    'stopped_time' => null,     // Set when stopped
    'completed_time' => null,   // Set when completed
]
```

#### Public Methods:

| Method                   | Returns | Description                                    |
| ------------------------ | ------- | ---------------------------------------------- |
| `start_migration()`      | `bool`  | Start background migration                     |
| `stop_migration()`       | `bool`  | Stop migration (mark as stopped)               |
| `get_status()`           | `array` | Get current status                             |
| `process_batch()`        | `void`  | Process one batch (called by Action Scheduler) |
| `manual_execute_batch()` | `array` | Manually process one batch                     |

#### Batch Processing Flow:

1. Check status is `running`
2. Query attachments without `_blitzcdn_file_id` meta
3. For each attachment, call `UploadHandler::handle_upload_phase_2()`
4. Update processed count
5. Schedule next batch (1 second delay)

#### Scheduling:

```php
// First batch (immediate)
as_schedule_single_action(time(), self::ACTION_HOOK);

// Subsequent batches
as_schedule_single_action(time() + 1, self::ACTION_HOOK);
```

---

### Redownloader.php — Goodbye Procedure

**Namespace:** `BlitzCDN\Redownloader`

Handles downloading files from Appwrite back to WordPress local storage. Used when users want to leave BlitzCDN.

#### Purpose:

1. Download all files from Appwrite to `wp-content/uploads/`
2. Preserve original file paths
3. Rewrite CDN URLs back to local URLs in content
4. Clear BlitzCDN metadata
5. Optionally delete files from Appwrite

#### Public Methods:

| Method                    | Parameters                                 | Returns | Description                                             |
| ------------------------- | ------------------------------------------ | ------- | ------------------------------------------------------- |
| `get_redownload_stats()`  | —                                          | `array` | Get count and IDs of attachments with BlitzCDN metadata |
| `redownload_attachment()` | `$attachment_id`, `$delete_from_appwrite`  | `array` | Process single attachment                               |
| `process_batch()`         | `$attachment_ids`, `$delete_from_appwrite` | `array` | Process multiple attachments                            |

#### Redownload Flow (per attachment):

1. Verify attachment exists and has BlitzCDN metadata
2. Download original file from Appwrite
3. Download all intermediate sizes
4. Rewrite URLs in post content (CDN → local)
5. Clear BlitzCDN postmeta
6. Optionally delete files from Appwrite

#### Result Schema:

```php
[
    'status' => 'success|partial|error',
    'message' => 'Status message',
    'details' => [
        'original' => ['status' => 'success', 'path' => '...'],
        'sizes' => [
            'thumbnail' => ['status' => 'success', 'path' => '...'],
            // ...
        ],
        'deleted_from_appwrite' => true,
        'deleted_count' => 5,
        'content_rewrite' => [
            'posts_updated' => 3,
            'replacements_made' => 7
        ]
    ]
]
```

#### File Existence Handling:

If a file already exists locally (with size > 0), it is skipped rather than overwritten.

---

### Compatibility.php — Plugin Compatibility Layer

**Namespace:** `BlitzCDN\Compatibility`

Ensures compatibility with popular WordPress plugins.

#### Current Integrations:

**Elementor:**

- Clears Elementor's file cache after uploads
- Prevents stale cached images

```php
add_action('blitzcdn_upload_complete', [$this, 'clear_elementor_cache']);
```

---

### Diagnostics.php — System Health Checks

**Namespace:** `BlitzCDN\Diagnostics`

Provides diagnostic information for troubleshooting.

#### Static Methods:

| Method                               | Returns | Description                    |
| ------------------------------------ | ------- | ------------------------------ |
| `get_action_scheduler_diagnostics()` | `array` | Check Action Scheduler setup   |
| `get_pending_actions()`              | `array` | List pending scheduled actions |
| `get_wordpress_cron_diagnostics()`   | `array` | Check WP-Cron configuration    |
| `get_migration_status()`             | `array` | Get migration status           |
| `generate_report()`                  | `array` | Full diagnostic report         |

#### Diagnostic Report Contents:

- Action Scheduler loaded/initialized
- Function availability (`as_schedule_single_action`, etc.)
- WP-Cron disabled/alternate mode
- Loopback request test
- Migration status
- WordPress/PHP versions
- Debug mode status

---

### CLI.php — WP-CLI Commands

**Namespace:** `BlitzCDN\CLI`

WP-CLI commands for server-side operations.

#### Commands:

```bash
# Start background migration
wp blitzcdn migrate

# Start and manually process (for when Action Scheduler fails)
wp blitzcdn migrate --manual

# Check migration status
wp blitzcdn status

# Process a single batch manually
wp blitzcdn process_batch

# Run full diagnostics
wp blitzcdn diagnostics
```

#### Manual Migration Mode:

The `--manual` flag processes batches in a loop with 1-second delays, useful when Action Scheduler isn't working.

---

## Admin Components

### Settings.php — Admin Settings Page

**Namespace:** `BlitzCDN\Admin\Settings`

**Menu Location:** Settings → BlitzCDN

#### Registered Settings:

| Field            | Type     | Description                                |
| ---------------- | -------- | ------------------------------------------ |
| `account_email`  | text     | Required. User account email for tracking. |
| `serve_from_cdn` | checkbox | Enable dynamic URL rewriting               |
| `safe_delete`    | checkbox | Delete local files after successful upload |
| `delete_remote`  | checkbox | Delete from Appwrite when deleting from WP |

#### Settings Storage:

All settings stored in single option: `blitzcdn_settings`

```php
$settings = get_option('blitzcdn_settings', []);
```

#### Conditional Feature Locking:

Migration tools and Goodbye procedure are disabled until `account_email` is configured.

---

### Migrator.php — AJAX Migration Handler

**Namespace:** `BlitzCDN\Admin\Migrator`

Handles all AJAX requests for migration tools.

#### AJAX Actions Registered:

| Action                                | Method                              | Description                 |
| ------------------------------------- | ----------------------------------- | --------------------------- |
| `blitzcdn_migrate_batch`              | `ajax_migrate_batch()`              | Browser migration batch     |
| `blitzcdn_get_migration_stats`        | `ajax_get_stats()`                  | Get pending migration count |
| `blitzcdn_start_background_migration` | `ajax_start_background_migration()` | Start background migration  |
| `blitzcdn_stop_background_migration`  | `ajax_stop_background_migration()`  | Stop background migration   |
| `blitzcdn_get_background_status`      | `ajax_get_background_status()`      | Poll migration status       |
| `blitzcdn_manual_batch_execution`     | `ajax_manual_batch_execution()`     | Manual batch trigger        |
| `blitzcdn_get_redownload_stats`       | `ajax_get_redownload_stats()`       | Get goodbye procedure stats |
| `blitzcdn_redownload_batch`           | `ajax_redownload_batch()`           | Process goodbye batch       |

#### Security:

All AJAX handlers verify:

1. Nonce: `blitzcdn_migration_nonce`
2. Capability: `manage_options`
3. Configuration: `account_email` must be set

---

## Frontend JavaScript

**File:** `assets/js/migration.js`

jQuery-based frontend for migration and goodbye procedure.

#### Components:

1. **Browser-Based Migration**

   - Button: `#blitzcdn-migrate-btn`
   - Batch size: 20 attachments
   - Visual progress bar
   - Console-style log output

2. **Background Migration Controls**

   - Start button: `#blitzcdn-background-migrate-btn`
   - Stop button: `#blitzcdn-stop-background-migrate-btn`
   - Status polling: every 1 second

3. **Goodbye Procedure**
   - Button: `#blitzcdn-redownload-btn`
   - Cancel button: `#blitzcdn-cancel-redownload-btn`
   - Checkbox: `#blitzcdn-delete-after-redownload`
   - Batch size: 5 attachments
   - Stats display (downloaded/skipped/errors)

#### Script Localization:

```php
wp_localize_script('blitzcdn-migration', 'blitzcdn_migration', [
    'nonce' => wp_create_nonce('blitzcdn_migration_nonce'),
    'ajax_url' => admin_url('admin-ajax.php')
]);
```

#### Fallback Data Attributes:

Buttons also have `data-nonce` and `data-ajax-url` attributes as fallback.

---

## Configuration

### WordPress Settings

Access via **Settings → BlitzCDN**

| Setting                 | Type   | Default | Description                    |
| ----------------------- | ------ | ------- | ------------------------------ |
| Account Email           | string | `''`    | User email for upload tracking |
| Serve from CDN          | bool   | `false` | Enable URL rewriting           |
| Safe Delete Local Files | bool   | `false` | Delete local after upload      |
| Delete from Appwrite    | bool   | `false` | Delete remote on WP delete     |

### Environment Variables

Create a `.env` file in the plugin root directory:

```env
# Required
APPWRITE_PROJECT_ID=your_project_id
APPWRITE_API_KEY=your_api_key
APPWRITE_BUCKET_ID=your_bucket_id

# Optional
APPWRITE_ENDPOINT=https://cloud.appwrite.io/v1
APPWRITE_CDN_DOMAIN=files.blitzcdn.net

# Database tracking (optional)
APPWRITE_DB_ID=db
APPWRITE_COLLECTION_ID=uploads
```

---

## Data Storage & Metadata

### WordPress Post Meta

| Meta Key            | Attachment Scope | Type     | Description      |
| ------------------- | ---------------- | -------- | ---------------- |
| `_blitzcdn_file_id` | Original         | `string` | Appwrite file ID |
| `_blitzcdn_cdn_url` | Original         | `string` | Full CDN URL     |
| `_blitzcdn_sizes`   | All sizes        | `array`  | Size metadata    |

#### `_blitzcdn_sizes` Schema:

```php
[
    'thumbnail' => [
        'file_id' => 'appwrite-file-id',
        'url' => 'https://cdn.example.com/storage/buckets/...'
    ],
    'medium' => [...],
    'large' => [...],
    // ... other registered sizes
]
```

### WordPress Options

| Option Name                 | Type    | Description                |
| --------------------------- | ------- | -------------------------- |
| `blitzcdn_settings`         | `array` | Plugin settings            |
| `blitzcdn_migration_status` | `array` | Background migration state |

### Appwrite Database (Upload Tracking)

| Field         | Type   | Description              |
| ------------- | ------ | ------------------------ |
| `email`       | string | User account email       |
| `fileId`      | string | Appwrite storage file ID |
| `originalUrl` | string | Original WordPress URL   |
| `createdAt`   | string | ISO 8601 timestamp       |

---

## WordPress Hooks Integration

### Filters Used

| Filter                            | Component     | Purpose           |
| --------------------------------- | ------------- | ----------------- |
| `wp_handle_upload`                | UploadHandler | Phase 1 upload    |
| `wp_generate_attachment_metadata` | UploadHandler | Phase 2 upload    |
| `wp_get_attachment_url`           | UrlRewriter   | Rewrite URLs      |
| `wp_get_attachment_image_src`     | UrlRewriter   | Rewrite image src |
| `wp_calculate_image_srcset`       | UrlRewriter   | Rewrite srcset    |
| `image_downsize`                  | UrlRewriter   | Handle downsizing |

### Actions Used

| Action                     | Component     | Purpose                |
| -------------------------- | ------------- | ---------------------- |
| `add_attachment`           | UploadHandler | Save original metadata |
| `delete_attachment`        | UploadHandler | Delete from Appwrite   |
| `blitzcdn_upload_complete` | Compatibility | Post-upload hooks      |
| `admin_menu`               | Settings      | Add menu item          |
| `admin_init`               | Settings      | Register settings      |
| `admin_enqueue_scripts`    | Migrator      | Load JS                |

### Custom Actions Fired

| Action                     | Parameters       | When                    |
| -------------------------- | ---------------- | ----------------------- |
| `blitzcdn_upload_complete` | `$attachment_id` | After successful upload |

---

## Feature Details

### Automatic Media Offloading

1. User uploads file via WordPress Media Library
2. WordPress saves file to `wp-content/uploads/YYYY/MM/filename.ext`
3. **Phase 1:** `wp_handle_upload` filter triggers
   - File uploaded to Appwrite Storage
   - File ID cached in static property
   - Tracking document created in Appwrite Database
4. WordPress creates attachment post
5. `add_attachment` action triggers
   - File ID and CDN URL saved to postmeta
6. WordPress generates image sizes
7. **Phase 2:** `wp_generate_attachment_metadata` filter triggers
   - Each size uploaded to Appwrite
   - Sizes metadata saved to `_blitzcdn_sizes`
   - Content URLs rewritten
   - Local files deleted (if enabled)

### URL Rewriting

Two approaches work together:

1. **Dynamic (runtime):** `UrlRewriter` filters attachment URLs when requested
2. **Static (on upload):** `UploadHandler` rewrites URLs in post content database

### Migration Tools

**Browser-Based:**

- Runs in user's browser
- Shows real-time log
- Requires keeping tab open
- Batch size: 20

**Background (Action Scheduler):**

- Runs on server
- Continues without browser
- Automatic retry on failure
- Batch size: 5

### Goodbye Procedure

1. Get all attachments with `_blitzcdn_file_id` meta
2. For each attachment:
   - Download original from Appwrite
   - Download all sizes from Appwrite
   - Rewrite CDN URLs → local URLs in content
   - Clear BlitzCDN postmeta
   - Delete from Appwrite (optional)
3. URLs now point to local files

### Content URL Rewriting

Both `UploadHandler` (upload) and `Redownloader` (goodbye) include content rewriting:

**Scope:**

- All public post types
- Attachment descriptions
- Post meta values

**Handles:**

- HTTP/HTTPS variants
- URL-encoded versions
- JSON-encoded (Gutenberg blocks)

---

## Plugin Constants

Defined in `blitzcdn.php`:

| Constant            | Value                 | Description              |
| ------------------- | --------------------- | ------------------------ |
| `BLITZCDN_VERSION`  | `'1.0.0'`             | Plugin version           |
| `BLITZCDN_PATH`     | Plugin directory path | Absolute filesystem path |
| `BLITZCDN_URL`      | Plugin directory URL  | URL for assets           |
| `BLITZCDN_BASENAME` | Plugin basename       | For hooks                |

---

## AJAX Endpoints

All endpoints require:

- Nonce: `blitzcdn_migration_nonce`
- Capability: `manage_options`

| Action                                | Method | Parameters                      | Response                           |
| ------------------------------------- | ------ | ------------------------------- | ---------------------------------- |
| `blitzcdn_get_migration_stats`        | POST   | —                               | `{total, ids}`                     |
| `blitzcdn_migrate_batch`              | POST   | `ids[]`                         | `{id: {status, message}}`          |
| `blitzcdn_start_background_migration` | POST   | —                               | `success`                          |
| `blitzcdn_stop_background_migration`  | POST   | —                               | `success`                          |
| `blitzcdn_get_background_status`      | POST   | —                               | `{status, processed, total}`       |
| `blitzcdn_manual_batch_execution`     | POST   | —                               | `{processed, total, percentage}`   |
| `blitzcdn_get_redownload_stats`       | POST   | —                               | `{total, ids}`                     |
| `blitzcdn_redownload_batch`           | POST   | `ids[]`, `delete_from_appwrite` | `{id: {status, message, details}}` |

---

## Error Handling & Logging

### Logging

All errors logged via `error_log()` with `BlitzCDN:` prefix:

```php
error_log('BlitzCDN Upload Error: ' . $e->getMessage());
error_log('BlitzCDN Delete Error: ' . $e->getMessage());
error_log('BlitzCDN Download Error: ' . $e->getMessage());
error_log('BlitzCDN Migration Error (ID $id): ' . $e->getMessage());
```

### Graceful Degradation

- Upload failures don't stop WordPress upload
- Migration errors don't stop batch processing
- Missing configuration disables features rather than crashing

---

## Debugging

### Enable WordPress Debug Mode

```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Logs written to: `wp-content/debug.log`

### Test Appwrite Database Connection

Run standalone script:

```bash
php debug-db.php
```

### WP-CLI Diagnostics

```bash
wp blitzcdn diagnostics
```

Outputs:

- Action Scheduler status
- WP-Cron configuration
- Loopback request test
- Migration status

### Check Action Scheduler

```bash
wp action-scheduler list --status=pending --hook=blitzcdn_background_migration_batch
```

---

## License

This plugin is licensed under the GPL-2.0-or-later License.

---

**BlitzCDN** — Media offloading for WordPress, powered by Appwrite.
`zip -r blitzcdn.zip . -x "node-scripts/*" "middleware/*"`