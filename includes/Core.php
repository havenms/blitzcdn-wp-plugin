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
        add_action('admin_post_as_async_request_queue_runner', [$this, 'handle_action_scheduler_queue_runner']);
        add_action('admin_post_nopriv_as_async_request_queue_runner', [$this, 'handle_action_scheduler_queue_runner']);

        // Initialize Admin Components
        if (is_admin()) {
            new Settings();
            new Migrator($this->appwrite_client);
        }
    }

    private function load_action_scheduler() {
        // Action Scheduler should be available via Composer
        // Load it if it hasn't been loaded yet
        $action_scheduler_path = BLITZCDN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
        
        if (file_exists($action_scheduler_path) && !class_exists('ActionScheduler', false)) {
            require_once $action_scheduler_path;
            
            // Initialize Action Scheduler if it has an initialization function
            if (function_exists('action_scheduler_init')) {
                action_scheduler_init();
            }
        }
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
     * Handle admin-post request to trigger Action Scheduler queue runner
     * This allows system cron to trigger Action Scheduler processing
     * 
     * @return void
     */
    public function handle_action_scheduler_queue_runner() {
        // Prevent output buffering issues
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Ensure Action Scheduler is loaded (in case it wasn't loaded yet)
        $this->load_action_scheduler();

        // Check if Action Scheduler is available
        if (!function_exists('as_get_scheduled_actions') || !class_exists('ActionScheduler_Store')) {
            status_header(500);
            header('Content-Type: text/plain');
            die('Action Scheduler is not available');
        }

        // Check if Action Scheduler is initialized
        // If not initialized yet, try to initialize it
        if (!class_exists('ActionScheduler', false) || !ActionScheduler::is_initialized()) {
            // Action Scheduler should auto-initialize, but if it hasn't, we can't proceed
            status_header(500);
            header('Content-Type: text/plain');
            die('Action Scheduler is not initialized');
        }

        try {
            // Trigger the queue runner
            // This is the same action that Action Scheduler's async request uses
            do_action('action_scheduler_run_queue', 'Admin Post Request');

            // Return success response
            status_header(200);
            header('Content-Type: text/plain');
            echo 'OK';
            exit;
        } catch (\Exception $e) {
            // Log error but don't expose details to prevent information leakage
            error_log('BlitzCDN: Action Scheduler queue runner error: ' . $e->getMessage());
            status_header(500);
            header('Content-Type: text/plain');
            die('Error processing queue');
        } catch (\Error $e) {
            // Also catch fatal errors (PHP 7+)
            error_log('BlitzCDN: Action Scheduler queue runner fatal error: ' . $e->getMessage());
            status_header(500);
            header('Content-Type: text/plain');
            die('Error processing queue');
        }
    }
}
