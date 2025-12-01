# BlitzCDN Media Offload

BlitzCDN is a WordPress plugin that seamlessly offloads your Media Library files to [Appwrite Storage](https://appwrite.io/docs/storage), a secure and scalable cloud storage solution. By integrating with a Content Delivery Network (CDN), it ensures fast, global delivery of your media assets while reducing server load and storage costs.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [How It Works](#how-it-works)
- [Migration](#migration)
- [Compatibility](#compatibility)
- [Troubleshooting](#troubleshooting)
- [API Reference](#api-reference)
- [Contributing](#contributing)
- [License](#license)

## Features

- **Automatic Offloading**: Automatically uploads new media files to Appwrite Storage upon upload.
- **CDN Integration**: Serve files through a custom CDN domain for improved performance.
- **Multi-Size Support**: Handles original files and all WordPress-generated image sizes.
- **Safe Deletion**: Optionally delete local files only after successful remote upload verification.
- **Bulk Migration**: Migrate existing Media Library items in batches.
- **URL Rewriting**: Dynamically rewrites attachment URLs to point to CDN/Appwrite URLs.
- **Elementor Compatibility**: Automatically clears Elementor cache after uploads.
- **WooCommerce Support**: Fully compatible with WooCommerce product images.
- **Admin Interface**: User-friendly settings page with migration tools.
- **Error Handling**: Robust error logging and graceful fallbacks.

## Requirements

- **PHP**: 7.4 or higher
- **WordPress**: 5.0 or higher
- **Appwrite**: 1.0 or higher (SDK version 10.0+)
- **Composer**: For dependency management
- **Appwrite Account**: With Storage service enabled
- **Action Scheduler**: Automatically installed via Composer (included in `woocommerce/action-scheduler` package)

## Installation

1. **Download the Plugin**:
   - Clone or download this plugin into your `wp-content/plugins/blitzcdn` directory:

     ```bash
     git clone https://github.com/your-repo/blitzcdn-wp-plugin.git wp-content/plugins/blitzcdn
     ```

2. **Install Dependencies**:
   - Navigate to the plugin directory and run Composer:

     ```bash
     cd wp-content/plugins/blitzcdn
     composer install
     ```

3. **Activate the Plugin**:
   - Go to **WordPress Admin > Plugins** and activate "BlitzCDN Media Offload".

4. **Configure Settings**:
   - Navigate to **Settings > BlitzCDN** to configure your Appwrite credentials and options.

## Configuration

Access the settings page at **WordPress Admin > Settings > BlitzCDN**.

### Required Settings

- **Project ID**: Your Appwrite Project ID (found in your Appwrite console).
- **API Key**: An API Key with `storage.write` and `storage.read` permissions.
- **Bucket ID**: The ID of the Storage Bucket where files will be stored.
- **Appwrite Endpoint**: Your Appwrite API endpoint (default: `https://cloud.appwrite.io/v1`).

### Optional Settings

- **CDN Domain**: Your custom CDN domain (e.g., `files.blitzcdn.net`). If provided, URLs will be rewritten to use this domain instead of the direct Appwrite endpoint.
- **Serve from CDN**: Enable to rewrite attachment URLs to use CDN/Appwrite URLs instead of local URLs.
- **Safe Delete Local Files**: Enable to automatically delete local files after successful upload to Appwrite. **Warning**: Only enable this if you're confident in your setup, as it may lead to data loss if uploads fail.
- **Delete from Appwrite**: Enable to delete files from Appwrite when you delete them from WordPress.

### Environment Variables (Alternative)

You can also configure the plugin using environment variables or a `.env` file in the plugin root:

```env
APPWRITE_PROJECT_ID=your_project_id
APPWRITE_API_KEY=your_api_key
APPWRITE_BUCKET_ID=your_bucket_id
APPWRITE_ENDPOINT=https://cloud.appwrite.io/v1
APPWRITE_CDN_DOMAIN=files.blitzcdn.net
```

## Usage

Once configured, the plugin works automatically:

1. **New Uploads**: When you upload media through WordPress, files are automatically offloaded to Appwrite.
2. **Existing Media**: Use the built-in migration tool to offload existing media.
3. **URL Rewriting**: If "Serve from CDN" is enabled, all attachment URLs will point to your CDN/Appwrite URLs.

### Manual Offloading

For programmatic offloading, you can use the following hooks:

```php
// Trigger offloading for a specific attachment
do_action('blitzcdn_upload_complete', $attachment_id);
```

## How It Works

BlitzCDN integrates deeply with WordPress's media handling system. Here's a detailed breakdown of the internal architecture and workflow:

### Core Components

#### 1. Core (`includes/Core.php`)

The main orchestrator class that initializes all components:

- Loads the Appwrite client
- Initializes upload handler, URL rewriter, and compatibility modules
- Handles plugin activation/deactivation

#### 2. Appwrite Client (`includes/AppwriteClient.php`)

Manages all interactions with the Appwrite API:

- Initializes the Appwrite SDK client
- Handles file uploads and deletions
- Supports environment variable configuration
- Provides configuration validation

#### 3. Upload Handler (`includes/UploadHandler.php`)

Manages the two-phase upload process:

##### Phase 1: Original File Upload

- Hooks into `wp_handle_upload` filter
- Uploads the original file immediately after it's saved locally
- Caches the Appwrite file ID for metadata association

##### Phase 2: Intermediate Sizes

- Hooks into `wp_generate_attachment_metadata` filter
- Uploads all WordPress-generated image sizes (thumbnail, medium, large, etc.)
- Saves metadata to postmeta for URL rewriting
- Implements safe deletion of local files (if enabled)

##### Deletion Handling

- Hooks into `delete_attachment` action
- Optionally deletes files from Appwrite when attachments are removed from WordPress

#### 4. URL Rewriter (`includes/UrlRewriter.php`)

Dynamically rewrites attachment URLs when "Serve from CDN" is enabled:

- Filters `wp_get_attachment_url`, `wp_get_attachment_image_src`, `wp_calculate_image_srcset`, and `image_downsize`
- Replaces local URLs with CDN/Appwrite URLs
- Handles responsive images and srcsets correctly

#### 5. Compatibility (`includes/Compatibility.php`)

Ensures compatibility with popular plugins:

- Clears Elementor cache after uploads to prevent stale cached images

#### 6. Background Migrator (`includes/BackgroundMigrator.php`)

Manages server-side background migrations using Action Scheduler:

- Processes attachments in small batches to avoid timeouts
- Uses Action Scheduler for reliable, independent background processing
- Continues running even when users close their browser
- Works on low-traffic sites without requiring page visits
- Provides status tracking and progress updates

#### 7. Admin Interface

- **Settings (`admin/Settings.php`)**: Provides the configuration interface
- **Migrator (`admin/Migrator.php`)**: Handles bulk migration of existing media via AJAX

### Data Flow

1. **Upload Initiation**: User uploads file via WordPress media uploader
2. **Local Storage**: WordPress saves file to `wp-content/uploads/`
3. **Phase 1 Upload**: BlitzCDN uploads original file to Appwrite, caches file ID
4. **Metadata Creation**: WordPress creates attachment post and metadata
5. **Metadata Saving**: BlitzCDN saves Appwrite file ID and CDN URL to postmeta
6. **Phase 2 Upload**: BlitzCDN uploads generated image sizes to Appwrite
7. **Safe Deletion**: If enabled, local files are deleted after successful uploads
8. **URL Rewriting**: When serving, URLs are rewritten to CDN/Appwrite endpoints

### Metadata Storage

BlitzCDN stores the following postmeta for each attachment:

- `_blitzcdn_file_id`: Appwrite file ID for the original file
- `_blitzcdn_cdn_url`: CDN URL for the original file
- `_blitzcdn_sizes`: Array of file IDs and URLs for intermediate sizes

### CDN URL Construction

CDN URLs are constructed as:

```text
https://{cdn_domain}/storage/buckets/{bucket_id}/files/{file_id}/view?project={project_id}
```

If no CDN domain is set, the direct Appwrite endpoint is used.

## Migration

The plugin includes a bulk migration tool to offload existing media:

1. Go to **Settings > BlitzCDN**
2. Click "Migrate Existing Media"
3. The tool will process attachments in batches of 5
4. Progress is displayed with real-time logging

**Migration Process**:

- Identifies attachments without BlitzCDN metadata
- Reuses the Phase 2 upload logic for each attachment
- Updates progress and logs results
- Handles errors gracefully without stopping the process

### Background Migration (server-side)

For large sites, the plugin supports a server-side background migration that continues processing even when the browser is closed. This uses **Action Scheduler** (a battle-tested job queue system used by WooCommerce) instead of WordPress's unreliable wp-cron system.

#### How It Works

- **Technology:** Uses [Action Scheduler](https://actionscheduler.org/) for reliable background processing
- **Class:** `\BlitzCDN\BackgroundMigrator` — registers an Action Scheduler hook `blitzcdn_background_migration_batch` and exposes methods to start/stop the process
- **Scheduling:** The migrator schedules batches using `as_schedule_single_action()` and automatically schedules the next batch after each completion
- **Batch size:** Default is small (`5`) to avoid PHP timeouts; defined as `BATCH_SIZE` in `includes/BackgroundMigrator.php`
- **Status storage:** Migration state is stored in the `blitzcdn_migration_status` option with these keys:
   - `status` — `'running'`, `'stopped'`, `'completed'`, or `'idle'`
   - `processed` — number of attachments processed so far
   - `total` — total attachments at the start of migration
   - `start_time` / `completed_time` — timestamps
- **Admin controls & UI:** The settings page (`Settings > BlitzCDN`) includes:
   - `Start Background Migration` button (AJAX endpoint `wp_ajax_blitzcdn_start_background_migration`)
   - `Stop Background Migration` button (AJAX endpoint `wp_ajax_blitzcdn_stop_background_migration`)
   - Status polling (AJAX endpoint `wp_ajax_blitzcdn_get_background_status`) that updates every 5 seconds
- **Processing:** For each attachment ID in the batch, the migrator calls `UploadHandler::handle_upload_phase_2()` to reuse the same upload and metadata handling logic

#### Advantages Over wp-cron

- **Works independently:** Doesn't require site traffic to trigger batches
- **Reliable:** Battle-tested system used by WooCommerce for processing millions of tasks
- **Built-in retry:** Action Scheduler automatically retries failed batches
- **System cron support:** Can optionally use system cron for maximum reliability on zero-traffic sites
- **No freezing:** Unlike wp-cron, Action Scheduler doesn't halt or freeze during processing

#### Setup and Configuration

**Basic Setup (Recommended):**

Action Scheduler works out of the box using WordPress's built-in cron system. No additional configuration is required for most sites.

**For Maximum Reliability (Optional):**

For zero-traffic sites or maximum reliability, you can set up a system cron to trigger Action Scheduler. Action Scheduler provides a built-in endpoint that processes pending actions:

```bash
# Add to your crontab (runs every minute)
* * * * * curl -s "https://example.com/wp-admin/admin-post.php?action=as_async_request_queue_runner" > /dev/null 2>&1
```

Or using `wget`:

```bash
* * * * * wget -q -O - "https://example.com/wp-admin/admin-post.php?action=as_async_request_queue_runner" > /dev/null 2>&1
```

**Viewing Scheduled Actions:**

You can view and manage scheduled actions using the Action Scheduler admin interface (if available) or via WP-CLI:

```bash
wp action-scheduler list --status=pending
```

#### Testing Background Migration

1. **Prepare Test Environment:**
   - Ensure you have some media files in your WordPress Media Library that haven't been migrated yet
   - Verify Action Scheduler is installed: Check that `vendor/woocommerce/action-scheduler` exists after running `composer install`

2. **Start Migration:**
   - Go to **Settings > BlitzCDN**
   - Click "Start Background Migration"
   - The status should update to show "running" with processed/total counts

3. **Verify It's Working:**
   - Check the status updates in the admin panel (updates every 5 seconds)
   - Close your browser and wait a few minutes
   - Return to the settings page - the migration should still be running and progressing
   - Check `wp-content/debug.log` for any errors (if `WP_DEBUG_LOG` is enabled)

4. **Monitor Progress:**
   - The status panel shows real-time progress
   - Check WordPress debug log for detailed error messages
   - Verify files are being uploaded to Appwrite by checking your Appwrite Storage bucket

5. **Stop Migration (if needed):**
   - Click "Stop Background Migration" to halt the process
   - The status will update to "stopped"

6. **Verify Completion:**
   - When complete, status will show "completed"
   - Check that all media files have the `_blitzcdn_file_id` postmeta
   - Verify files are accessible via CDN URLs (if "Serve from CDN" is enabled)

#### Troubleshooting Background Migration

- **Migration not starting:** Check that Action Scheduler is installed (`composer install`) and that the admin notice doesn't show any errors
- **Migration stuck:** Check WordPress debug log for errors. Failed batches are automatically retried by Action Scheduler
- **Slow processing:** Reduce `BATCH_SIZE` in `includes/BackgroundMigrator.php` if you're on a shared host
- **Not processing when browser is closed:** Set up system cron (see above) for maximum reliability on low-traffic sites

### Browser-based migration (existing behavior)

The original AJAX-based migration UI remains available for manual runs from the admin screen. This is useful for small sites or when you want immediate, browser-visible logging. On large sites, prefer the background migration for reliability and to avoid long-running admin requests.

## Compatibility

### Supported Plugins

- **Elementor**: Cache is automatically cleared after uploads
- **WooCommerce**: Fully compatible with product galleries and images

### WordPress Versions

- Tested with WordPress 5.0+
- Compatible with multisite installations

### PHP Compatibility

- Requires PHP 7.4+ for Appwrite SDK compatibility
- Uses modern PHP features like typed properties and arrow functions where available

## Troubleshooting

### Common Issues

1. **Uploads Fail**
   - Check Appwrite credentials and permissions
   - Ensure the bucket exists and has proper permissions
   - Review error logs in `wp-content/debug.log`

2. **Images Don't Load**
   - Verify CDN domain configuration
   - Check if "Serve from CDN" is enabled
   - Ensure Appwrite bucket permissions allow public access

3. **Migration Stuck**
   - Check browser console for JavaScript errors
   - Verify AJAX endpoints are accessible
   - Increase PHP memory limit if processing large files
   - For background migrations: Check that Action Scheduler is installed and working
   - Review Action Scheduler logs (if available) or WordPress debug log
   - Verify system cron is running (if configured) for background migrations

4. **Local Files Not Deleted**
   - Ensure "Safe Delete" is enabled
   - Check that all uploads succeeded before deletion
   - Review error logs for upload failures

### Debugging

Enable WordPress debugging:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

BlitzCDN logs errors to the WordPress debug log. Check `wp-content/debug.log` for detailed error messages.

### Support

For issues not covered here:

- Check the [Appwrite Documentation](https://appwrite.io/docs)
- Review WordPress [Media Library Documentation](https://developer.wordpress.org/plugins/media/)
- Contact support at support@blitzcdn.net

## API Reference

### Hooks

#### Actions

- `blitzcdn_upload_complete`: Fired after a file is successfully uploaded to Appwrite

  ```php
  do_action('blitzcdn_upload_complete', $attachment_id);
  ```

#### Filters

- `wp_handle_upload`: Used for Phase 1 uploads
- `wp_generate_attachment_metadata`: Used for Phase 2 uploads
- `wp_get_attachment_url`: Used for URL rewriting
- `wp_get_attachment_image_src`: Used for image source rewriting
- `wp_calculate_image_srcset`: Used for responsive image rewriting
- `image_downsize`: Used for image dimension handling

### Classes

#### `\BlitzCDN\Core`

Main plugin class. Access via:

```php
$blitzcdn = \BlitzCDN\Core::get_instance();
```

#### `\BlitzCDN\AppwriteClient`

Handles Appwrite API interactions.

#### `\BlitzCDN\UploadHandler`

Manages upload logic.

#### `\BlitzCDN\UrlRewriter`

Handles URL rewriting.

#### `\BlitzCDN\BackgroundMigrator`

Manages server-side background migrations using Action Scheduler.

### AJAX Endpoints

- `wp_ajax_blitzcdn_migrate_batch`: Processes migration batches (browser-based)
- `wp_ajax_blitzcdn_get_migration_stats`: Retrieves migration statistics
- `wp_ajax_blitzcdn_start_background_migration`: Starts server-side background migration
- `wp_ajax_blitzcdn_stop_background_migration`: Stops server-side background migration
- `wp_ajax_blitzcdn_get_background_status`: Retrieves background migration status

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Make your changes and test thoroughly
4. Commit your changes: `git commit -am 'Add some feature'`
5. Push to the branch: `git push origin feature/your-feature`
6. Submit a pull request

### Development Setup

1. Clone the repository
2. Run `composer install` (this will install Action Scheduler and Appwrite SDK)
3. Activate the plugin in a WordPress development environment
4. Configure your Appwrite credentials in **Settings > BlitzCDN**
5. Use the included migration tool for testing

### Testing Background Migration

To test the background migration system:

1. **Setup:**
   - Ensure you have media files in your WordPress Media Library
   - Configure Appwrite credentials in the plugin settings
   - Run `composer install` to ensure Action Scheduler is installed

2. **Test Basic Functionality:**
   - Go to **Settings > BlitzCDN**
   - Click "Start Background Migration"
   - Verify the status updates show "running"
   - Watch the processed/total counts increase
   - Close your browser and wait 2-3 minutes
   - Return to the settings page - migration should still be running

3. **Test Stop Functionality:**
   - Start a migration
   - Click "Stop Background Migration"
   - Verify status changes to "stopped"
   - Verify no new batches are processed

4. **Test Completion:**
   - Let a migration run to completion
   - Verify status shows "completed"
   - Check that media files have `_blitzcdn_file_id` postmeta
   - Verify files are accessible via CDN (if enabled)

5. **Test Error Handling:**
   - Temporarily break Appwrite credentials
   - Start a migration
   - Check WordPress debug log for error messages
   - Verify migration continues processing other items (errors are logged but don't stop the process)

6. **Test with System Cron (Optional):**
   - Set up system cron to trigger Action Scheduler (see Background Migration section)
   - Start a migration on a site with no traffic
   - Verify batches process even without page visits

### Coding Standards

- Follow WordPress Coding Standards
- Use PSR-4 autoloading
- Include PHPDoc comments for all classes and methods
- Test with multiple WordPress versions

## License

This plugin is licensed under the GPL-2.0+ License. See the LICENSE file for details.

---

**BlitzCDN** - Fast, reliable media offloading for WordPress.
