<?php
/**
 * Plugin Name: BlitzCDN Media Offload
 * Plugin URI:  https://blitzcdn.net
 * Description: Offload Media Library files to BlitzCDN and serve them through our CDN.
 * Version:     1.0.0
 * Author:      BlitzCDN
 * Author URI:  https://blitzcdn.net
 * License:     GPL-2.0+
 * Text Domain: blitzcdn
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Define Constants
define('BLITZCDN_VERSION', '1.0.0');
define('BLITZCDN_PATH', plugin_dir_path(__FILE__));
define('BLITZCDN_URL', plugin_dir_url(__FILE__));
define('BLITZCDN_BASENAME', plugin_basename(__FILE__));

// Load .env file if it exists (before any other initialization)
$env_file = BLITZCDN_PATH . '.env';
if (file_exists($env_file)) {
    $env_lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        // Skip lines that start with # (comments)
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Strip inline comments (everything after # including the #)
            $comment_pos = strpos($value, '#');
            if ($comment_pos !== false) {
                $value = trim(substr($value, 0, $comment_pos));
            }
            // Remove quotes if present
            if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                $value = substr($value, 1, -1);
            }
            // Set in $_ENV for getenv() compatibility
            $_ENV[$key] = $value;
        }
    }
}

// Load Composer Autoloader
if (file_exists(BLITZCDN_PATH . 'vendor/autoload.php')) {
    require_once BLITZCDN_PATH . 'vendor/autoload.php';
} else {
    // Graceful fallback if composer install hasn't been run
    add_action('admin_notices', function() {
        echo '<div class="error"><p>' . esc_html__('BlitzCDN requires Composer dependencies. Please run "composer install" in the plugin directory.', 'blitzcdn') . '</p></div>';
    });
    return;
}

// Initialize the Plugin
function blitzcdn_init() {
    \BlitzCDN\Core::get_instance();
}
add_action('plugins_loaded', 'blitzcdn_init');

// Early initialization for admin-post requests (before plugins_loaded)
// This ensures the handler is available even if plugins_loaded hasn't fired yet
if (defined('DOING_ADMIN_POST') || (isset($_GET['action']) && $_GET['action'] === 'as_async_request_queue_runner')) {
    // Ensure Core is initialized early for admin-post requests
    add_action('init', 'blitzcdn_init', 1);
}

// Load CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    require_once BLITZCDN_PATH . 'includes/CLI.php';
}

// Activation Hook
register_activation_hook(__FILE__, ['\BlitzCDN\Core', 'activate']);

// Deactivation Hook
register_deactivation_hook(__FILE__, ['\BlitzCDN\Core', 'deactivate']);
