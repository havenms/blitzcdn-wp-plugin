<?php

namespace BlitzCDN;

use BlitzCDN\Admin\Settings;
use BlitzCDN\Admin\Migrator;

class Core {

    private static $instance = null;
    private $appwrite_client;
    private $upload_handler;
    private $url_rewriter;
    private $compatibility;
    private $background_migrator;
    private $redownloader;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_components();
    }

    private function init_components() {
        // Ensure Action Scheduler is loaded
        $this->load_action_scheduler();

        // Initialize Appwrite Client
        $this->appwrite_client = new AppwriteClient();

        // Initialize Upload Handler
        $this->upload_handler = new UploadHandler($this->appwrite_client);

        // Initialize URL Rewriter
        $this->url_rewriter = new UrlRewriter();

        // Initialize Compatibility
        $this->compatibility = new Compatibility();

        // Initialize Background Migrator
        $this->background_migrator = new BackgroundMigrator();

        // Initialize Redownloader (Goodbye Procedure)
        $this->redownloader = new Redownloader($this->appwrite_client);

        // Check Action Scheduler availability
        $this->check_action_scheduler();

        // Register admin-post handler for Action Scheduler queue runner
        // This allows system cron to trigger Action Scheduler without requiring nonces
        // Note: Action Scheduler's async request uses admin-ajax.php (wp_ajax_* hooks),
        // but we support admin-post.php for system cron compatibility
        add_action('admin_post_as_async_request_queue_runner', [$this, 'handle_action_scheduler_queue_runner']);
        add_action('admin_post_nopriv_as_async_request_queue_runner', [$this, 'handle_action_scheduler_queue_runner']);
        
        // Also register for admin-ajax.php in case Action Scheduler's async request mechanism is used
        // This ensures compatibility with both endpoints
        add_action('wp_ajax_as_async_request_queue_runner', [$this, 'handle_action_scheduler_queue_runner']);
        add_action('wp_ajax_nopriv_as_async_request_queue_runner', [$this, 'handle_action_scheduler_queue_runner']);

        // Initialize Admin Components
        if (is_admin()) {
            new Settings();
            new Migrator($this->appwrite_client);
        }
    }

    private function load_action_scheduler() {
        self::ensure_action_scheduler_loaded();
    }

    /**
     * Static method to ensure Action Scheduler is loaded
     * Can be called independently without requiring Core instance
     * 
     * @return void
     */
    private static function ensure_action_scheduler_loaded() {
        // Action Scheduler should be available via Composer
        // Load it if it hasn't been loaded yet
        if (!defined('BLITZCDN_PATH')) {
            return; // Plugin constants not defined yet
        }
        
        $action_scheduler_path = BLITZCDN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
        
        if (file_exists($action_scheduler_path) && !class_exists('\ActionScheduler', false)) {
            require_once $action_scheduler_path;
        }
        
        // Action Scheduler will initialize on the 'init' hook
        // We don't need to manually trigger it - just ensure the file is loaded
    }

    private function check_action_scheduler() {
        // Verify Action Scheduler is available
        // If it's not available, show an admin notice
        if (!function_exists('as_schedule_single_action') && is_admin()) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>';
                echo '<strong>BlitzCDN:</strong> ';
                echo esc_html__('Action Scheduler is not available. Background migrations will not work. Please run "composer install" in the plugin directory.', 'blitzcdn');
                echo '</p></div>';
            });
        }
    }

    public static function activate() {
        // Set default options
        if (false === get_option('blitzcdn_settings')) {
            update_option('blitzcdn_settings', [
                'project_id' => '',
                'api_key' => '',
                'bucket_id' => '',
                'cdn_domain' => '',
                'endpoint' => 'https://cloud.appwrite.io/v1',
                'serve_from_cdn' => false,
                'safe_delete' => false,
                'delete_remote' => false,
            ]);
        }
    }

    public static function deactivate() {
        // Do not delete metadata or options on deactivate
    }

    public function get_upload_handler() {
        return $this->upload_handler;
    }

    public function get_background_migrator() {
        return $this->background_migrator;
    }

    public function get_redownloader() {
        return $this->redownloader;
    }

    /**
     * Handle admin-post/admin-ajax request to trigger Action Scheduler queue runner
     * This allows system cron to trigger Action Scheduler processing
     * Supports both admin-post.php (for system cron) and admin-ajax.php (for Action Scheduler's async requests)
     * 
     * @return void
     */
    public function handle_action_scheduler_queue_runner() {
        try {
            // Prevent output buffering issues - clear all nested output buffers
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Ensure Action Scheduler is loaded (in case it wasn't loaded yet)
            // Use static method to avoid dependency on instance state
            self::ensure_action_scheduler_loaded();

            // Check if Action Scheduler is available
            // Use fully qualified class name (leading backslash) to reference global namespace
            if (!function_exists('as_get_scheduled_actions') || !class_exists('\ActionScheduler_Store')) {
                status_header(500);
                header('Content-Type: text/plain');
                die('Action Scheduler is not available');
            }

            // Check if Action Scheduler is initialized (safely check static method)
            // Use fully qualified class name (leading backslash) to reference global namespace
            $is_initialized = false;
            if (class_exists('\ActionScheduler', false) && method_exists('\ActionScheduler', 'is_initialized')) {
                $is_initialized = \ActionScheduler::is_initialized();
            }

            // If not initialized, check if the queue runner hook is available anyway
            // (Action Scheduler might be partially initialized)
            if (!$is_initialized && !has_action('action_scheduler_run_queue')) {
                status_header(500);
                header('Content-Type: text/plain');
                die('Action Scheduler queue runner not available');
            }

            // Trigger the queue runner
            // This is the same action that Action Scheduler's async request uses
            do_action('action_scheduler_run_queue', 'Admin Post Request');

            // Return success response
            status_header(200);
            header('Content-Type: text/plain');
            echo 'OK';
            exit;
        } catch (\Throwable $e) {
            // Catch all errors (Exception and Error in PHP 7+)
            error_log('BlitzCDN: Action Scheduler queue runner error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            status_header(500);
            header('Content-Type: text/plain');
            die('Error processing queue');
        }
    }
}