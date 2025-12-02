<?php
/**
 * Plugin Name: BlitzCDN Media Offload
 * Plugin URI:  https://blitzcdn.net
 * Description: Offload Media Library files to Appwrite Storage and serve them through a CDN.
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

// Load CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    require_once BLITZCDN_PATH . 'includes/CLI.php';
}

// Activation Hook
register_activation_hook(__FILE__, ['\BlitzCDN\Core', 'activate']);

// Deactivation Hook
register_deactivation_hook(__FILE__, ['\BlitzCDN\Core', 'deactivate']);
