<?php

namespace BlitzCDN\Admin;

use BlitzCDN\Core;

class Migrator {

    private $appwrite_client;

    public function __construct($appwrite_client) {
        $this->appwrite_client = $appwrite_client;
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_blitzcdn_migrate_batch', [$this, 'ajax_migrate_batch']);
        add_action('wp_ajax_blitzcdn_get_migration_stats', [$this, 'ajax_get_stats']);
        
        // Background Migration Actions
        add_action('wp_ajax_blitzcdn_start_background_migration', [$this, 'ajax_start_background_migration']);
        add_action('wp_ajax_blitzcdn_stop_background_migration', [$this, 'ajax_stop_background_migration']);
        add_action('wp_ajax_blitzcdn_get_background_status', [$this, 'ajax_get_background_status']);
        add_action('wp_ajax_blitzcdn_manual_batch_execution', [$this, 'ajax_manual_batch_execution']);

        // Goodbye / Redownload Actions
        add_action('wp_ajax_blitzcdn_get_redownload_stats', [$this, 'ajax_get_redownload_stats']);
        add_action('wp_ajax_blitzcdn_redownload_batch', [$this, 'ajax_redownload_batch']);
    }

    public function enqueue_scripts($hook) {
        // Enqueue on any admin page that contains 'blitzcdn' in the hook name.
        // This helps in setups where the admin page hook varies (multisite, plugin placement, etc.).
        if (false === strpos($hook, 'blitzcdn')) {
            return;
        }

        wp_enqueue_script('blitzcdn-migration', BLITZCDN_URL . 'assets/js/migration.js', ['jquery'], BLITZCDN_VERSION, true);
        
        // Get batch sizes from settings
        $settings = get_option('blitzcdn_settings', []);
        $migration_batch_size = isset($settings['migration_batch_size']) ? intval($settings['migration_batch_size']) : 20;
        $redownload_batch_size = isset($settings['redownload_batch_size']) ? intval($settings['redownload_batch_size']) : 5;
        
        // Ensure batch sizes are within reasonable limits
        $migration_batch_size = max(1, min(100, $migration_batch_size));
        $redownload_batch_size = max(1, min(50, $redownload_batch_size));
        
        wp_localize_script('blitzcdn-migration', 'blitzcdn_migration', [
            'nonce' => wp_create_nonce('blitzcdn_migration_nonce'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'migration_batch_size' => $migration_batch_size,
            'redownload_batch_size' => $redownload_batch_size
        ]);
    }

    public function ajax_get_stats() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_blitzcdn_file_id',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);

        $total = $query->found_posts;
        wp_send_json_success(['total' => $total, 'ids' => $query->posts]);
    }

    public function ajax_migrate_batch() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $settings = get_option('blitzcdn_settings', []);
        if (empty($settings['account_email'])) {
            wp_send_json_error('Account email not configured. Please set your email in BlitzCDN settings.');
        }

        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        
        if (empty($ids)) {
            wp_send_json_error('No IDs provided');
        }

        $results = [];
        $upload_handler = Core::get_instance()->get_upload_handler(); // Need to expose this in Core

        foreach ($ids as $id) {
            $metadata = wp_get_attachment_metadata($id);
            if (!$metadata) {
                $results[$id] = ['status' => 'error', 'message' => 'No metadata'];
                continue;
            }

            try {
                // Reuse the logic in UploadHandler
                // We need to make sure we don't double-process if already done, but the query filters that.
                // However, handle_upload_phase_2 checks for existing meta too.
                
                $new_metadata = $upload_handler->handle_upload_phase_2($metadata, $id);
                
                // We don't strictly need to update metadata if handle_upload_phase_2 only modifies side-effects (postmeta),
                // but it returns metadata, and sometimes plugins modify it.
                // In our case, handle_upload_phase_2 modifies postmeta directly for BlitzCDN fields.
                // It doesn't modify the $metadata array structure regarding sizes (it reads it).
                // So we might not need wp_update_attachment_metadata unless we changed something inside $metadata.
                // But let's be safe.
                
                $results[$id] = ['status' => 'success'];
            } catch (\Exception $e) {
                $results[$id] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        wp_send_json_success($results);
    }

    public function ajax_start_background_migration() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $settings = get_option('blitzcdn_settings', []);
        if (empty($settings['account_email'])) {
            wp_send_json_error('Account email not configured. Please set your email in BlitzCDN settings.');
        }

        // Log who is starting the migration for audit/debugging (helps debug auto-start cases)
        $current_user = wp_get_current_user();
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        error_log(sprintf('BlitzCDN: ajax_start_background_migration called by %s (%s)', $current_user->user_login, $remote_ip));

        Core::get_instance()->get_background_migrator()->start_migration();
        wp_send_json_success();
    }

    public function ajax_stop_background_migration() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $current_user = wp_get_current_user();
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        error_log(sprintf('BlitzCDN: ajax_stop_background_migration called by %s (%s)', $current_user->user_login, $remote_ip));

        Core::get_instance()->get_background_migrator()->stop_migration();
        wp_send_json_success();
    }

    public function ajax_get_background_status() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $status = Core::get_instance()->get_background_migrator()->get_status();
        wp_send_json_success($status);
    }

    public function ajax_manual_batch_execution() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

        $result = Core::get_instance()->get_background_migrator()->manual_execute_batch();
        
        if (isset($result['error'])) {
            wp_send_json_error($result);
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * AJAX: Get statistics for redownload (goodbye) procedure.
     */
    public function ajax_get_redownload_stats() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Verify account is configured
        $settings = get_option('blitzcdn_settings', []);
        if (empty($settings['account_email'])) {
            wp_send_json_error('Account email not configured. Please set your email in BlitzCDN settings.');
        }

        // Verify Appwrite client is configured
        if (!$this->appwrite_client->is_configured()) {
            wp_send_json_error('Appwrite is not configured. Please check your environment settings.');
        }

        $current_user = wp_get_current_user();
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        error_log(sprintf('BlitzCDN: ajax_get_redownload_stats called by %s (%s)', $current_user->user_login, $remote_ip));

        $redownloader = Core::get_instance()->get_redownloader();
        $stats = $redownloader->get_redownload_stats();

        wp_send_json_success($stats);
    }

    /**
     * AJAX: Process a batch of attachments for redownload.
     */
    public function ajax_redownload_batch() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Verify account is configured
        $settings = get_option('blitzcdn_settings', []);
        if (empty($settings['account_email'])) {
            wp_send_json_error('Account email not configured. Please set your email in BlitzCDN settings.');
        }

        // Verify Appwrite client is configured
        if (!$this->appwrite_client->is_configured()) {
            wp_send_json_error('Appwrite is not configured. Please check your environment settings.');
        }

        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        $delete_from_appwrite = isset($_POST['delete_from_appwrite']) && $_POST['delete_from_appwrite'] === 'true';

        if (empty($ids)) {
            wp_send_json_error('No IDs provided');
        }

        $current_user = wp_get_current_user();
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        error_log(sprintf('BlitzCDN: ajax_redownload_batch called by %s (%s) ids=%s delete=%s', $current_user->user_login, $remote_ip, implode(',', $ids), $delete_from_appwrite ? '1' : '0'));

        $redownloader = Core::get_instance()->get_redownloader();
        $results = $redownloader->process_batch($ids, $delete_from_appwrite);

        wp_send_json_success($results);
    }
}
