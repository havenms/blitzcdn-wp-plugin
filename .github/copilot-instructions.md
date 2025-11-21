# Copilot Instructions for BlitzCDN WordPress Plugin

## Repository Overview

BlitzCDN is a WordPress plugin that offloads Media Library files to Appwrite Storage and serves them through a CDN. This plugin integrates deeply with WordPress's media handling system to provide automatic, seamless media offloading with CDN delivery.

## Technology Stack

- **Language**: PHP 7.4+
- **Framework**: WordPress Plugin (WordPress 5.0+)
- **Dependencies**: 
  - Appwrite PHP SDK (^10.0) via Composer
  - WordPress Core APIs
- **Package Manager**: Composer (PSR-4 autoloading)
- **No Testing Framework**: Currently no test infrastructure exists

## Architecture Overview

### Core Components

1. **`Core.php`**: Main orchestrator class (singleton pattern)
   - Initializes all components
   - Manages plugin lifecycle (activation/deactivation)
   - Entry point: `\BlitzCDN\Core::get_instance()`

2. **`AppwriteClient.php`**: Appwrite API integration
   - Wraps Appwrite SDK
   - Handles file uploads and deletions
   - Manages configuration and credentials
   - Supports environment variables via `.env` file

3. **`UploadHandler.php`**: Two-phase upload system
   - **Phase 1**: Uploads original files via `wp_handle_upload` hook
   - **Phase 2**: Uploads generated image sizes via `wp_generate_attachment_metadata` hook
   - Handles safe deletion of local files after successful upload
   - Manages attachment metadata storage

4. **`UrlRewriter.php`**: Dynamic URL rewriting
   - Filters WordPress attachment URLs
   - Replaces local URLs with CDN/Appwrite URLs
   - Handles responsive images and srcsets

5. **`Compatibility.php`**: Plugin compatibility layer
   - Elementor cache clearing
   - WooCommerce support

6. **Admin Components**:
   - **`admin/Settings.php`**: Settings page UI and option management
   - **`admin/Migrator.php`**: Bulk migration tool with AJAX batching

### Data Storage

Plugin stores metadata in WordPress postmeta:
- `_blitzcdn_file_id`: Appwrite file ID for original file
- `_blitzcdn_cdn_url`: CDN URL for original file
- `_blitzcdn_sizes`: Array of file IDs and URLs for intermediate sizes

### Constants

Defined in `blitzcdn.php`:
- `BLITZCDN_VERSION`: Plugin version
- `BLITZCDN_PATH`: Absolute path to plugin directory
- `BLITZCDN_URL`: URL to plugin directory
- `BLITZCDN_BASENAME`: Plugin basename

## Development Guidelines

### Coding Standards

1. **Follow WordPress Coding Standards**:
   - Use WordPress function naming conventions (underscores, not camelCase for functions)
   - Use WordPress hooks and filters appropriately
   - Escape output with `esc_html()`, `esc_attr()`, `esc_url()`, etc.
   - Sanitize input with `sanitize_text_field()`, etc.
   - Use `wp_nonce_field()` for form security

2. **Use PSR-4 Autoloading**:
   - Namespace: `BlitzCDN\` maps to `includes/`
   - Namespace: `BlitzCDN\Admin\` maps to `admin/`
   - One class per file, filename matches class name

3. **PHP Style**:
   - Class names: PascalCase
   - Method names: snake_case (WordPress convention)
   - Properties: snake_case
   - Constants: UPPERCASE_WITH_UNDERSCORES
   - Use type hints where possible (PHP 7.4+)

4. **Security**:
   - Always check `if (!defined('ABSPATH')) { exit; }` at top of files
   - Use WordPress nonce verification for AJAX and forms
   - Validate and sanitize all inputs
   - Escape all outputs
   - Use prepared statements for database queries

### Setup Instructions

1. **Install dependencies**:
   ```bash
   composer install
   ```

2. **Activate plugin**:
   - Copy plugin to `wp-content/plugins/blitzcdn/`
   - Activate via WordPress admin

3. **Configure**:
   - Option 1: Use Settings page (Settings > BlitzCDN)
   - Option 2: Create `.env` file with Appwrite credentials

### Common Patterns

#### Adding WordPress Hooks

```php
add_action('hook_name', [$this, 'method_name'], priority);
add_filter('filter_name', [$this, 'method_name'], priority, num_args);
```

#### Accessing Plugin Options

```php
$settings = get_option('blitzcdn_settings', []);
update_option('blitzcdn_settings', $settings);
```

#### Storing Attachment Metadata

```php
update_post_meta($attachment_id, '_blitzcdn_file_id', $file_id);
$file_id = get_post_meta($attachment_id, '_blitzcdn_file_id', true);
```

#### AJAX Handlers

```php
add_action('wp_ajax_action_name', [$this, 'ajax_handler']);
// Handler should wp_send_json_success() or wp_send_json_error()
```

### WordPress Integration Points

#### Upload Flow Hooks
- `wp_handle_upload`: Intercept file uploads (Phase 1)
- `wp_generate_attachment_metadata`: Process generated sizes (Phase 2)
- `delete_attachment`: Handle attachment deletion

#### URL Rewriting Hooks
- `wp_get_attachment_url`: Rewrite attachment URLs
- `wp_get_attachment_image_src`: Rewrite image source arrays
- `wp_calculate_image_srcset`: Rewrite responsive image srcsets
- `image_downsize`: Handle image dimensions and URLs

### File Structure

```
blitzcdn-wp-plugin/
├── .env                    # Environment configuration (not committed)
├── .env.example            # Example environment file
├── blitzcdn.php            # Main plugin file
├── composer.json           # Dependencies
├── README.md               # Documentation
├── admin/                  # Admin UI components
│   ├── Migrator.php        # Bulk migration tool
│   └── Settings.php        # Settings page
├── assets/                 # Static assets (CSS, JS)
├── includes/               # Core plugin classes
│   ├── AppwriteClient.php  # Appwrite API client
│   ├── Compatibility.php   # Plugin compatibility
│   ├── Core.php            # Main orchestrator
│   ├── UploadHandler.php   # Upload management
│   └── UrlRewriter.php     # URL rewriting
└── vendor/                 # Composer dependencies (not committed)
```

## Important Constraints

1. **No Testing Infrastructure**: Do not add test files unless explicitly requested. Focus on WordPress best practices for code quality.

2. **Composer Required**: The plugin requires `composer install` to be run. Always check that `vendor/autoload.php` exists.

3. **WordPress Environment**: Code runs within WordPress context. Always use WordPress functions when available (e.g., `wp_remote_get()` instead of `curl`).

4. **Backward Compatibility**: Maintain compatibility with PHP 7.4+ and WordPress 5.0+.

5. **Appwrite SDK**: Use Appwrite SDK methods correctly. Refer to Appwrite PHP SDK documentation for API details.

## Common Tasks

### Adding New Settings

1. Add field to settings array in `admin/Settings.php`
2. Update `render_settings_page()` method to include new field
3. Access via `get_option('blitzcdn_settings')[$key]`

### Modifying Upload Behavior

Edit `includes/UploadHandler.php`:
- Phase 1: `handle_upload()` method
- Phase 2: `handle_attachment_metadata()` method

### Adding URL Rewriting Logic

Edit `includes/UrlRewriter.php`:
- Add filters in constructor
- Implement rewriting methods

### Adding Plugin Compatibility

Edit `includes/Compatibility.php`:
- Add hooks for specific plugins
- Clear caches or handle integration

## Debugging

Enable WordPress debugging in `wp-config.php`:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Logs are written to `wp-content/debug.log`. Use `error_log()` for debugging:
```php
error_log('BlitzCDN: ' . print_r($data, true));
```

## Important Notes for Copilot

1. **Always preserve WordPress hooks and filters** - they are critical for plugin functionality
2. **Maintain the two-phase upload architecture** - don't merge Phase 1 and Phase 2
3. **Keep metadata structure consistent** - postmeta keys are used across the plugin
4. **Respect the singleton pattern** in Core class
5. **Always escape output and sanitize input** per WordPress standards
6. **Use WordPress coding style** even though it differs from PSR standards
7. **Remember: No test infrastructure** - don't create tests unless explicitly requested
