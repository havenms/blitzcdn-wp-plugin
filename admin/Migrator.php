<?php

namespace BlitzCDN\Admin;

use BlitzCDN\Core;
use BlitzCDN\ZipMigrator;

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

        // Email Redownload Actions
        add_action('wp_ajax_blitzcdn_get_email_stats', [$this, 'ajax_get_email_stats']);
        add_action('wp_ajax_blitzcdn_email_redownload_batch', [$this, 'ajax_email_redownload_batch']);
        add_action('wp_ajax_blitzcdn_email_link_batch', [$this, 'ajax_email_link_batch']);
        
        // Background email linking actions
        add_action('wp_ajax_blitzcdn_start_background_link', [$this, 'ajax_start_background_link']);
        add_action('wp_ajax_blitzcdn_stop_background_link', [$this, 'ajax_stop_background_link']);
        add_action('wp_ajax_blitzcdn_get_background_link_status', [$this, 'ajax_get_background_link_status']);

        // WooCommerce reconnection action
        add_action('wp_ajax_blitzcdn_reconnect_woocommerce', [$this, 'ajax_reconnect_woocommerce']);

        // Fix URL structure action
        add_action('wp_ajax_blitzcdn_fix_url_structure', [$this, 'ajax_fix_url_structure']);

        // Zip Migration Actions - delegate to proxy methods to avoid circular dependency
        add_action('wp_ajax_blitzcdn_start_zip_migration', [$this, 'ajax_start_zip_migration_proxy']);
        add_action('wp_ajax_blitzcdn_continue_zip_migration', [$this, 'ajax_continue_zip_migration_proxy']);
        add_action('wp_ajax_blitzcdn_get_zip_migration_status', [$this, 'ajax_get_zip_migration_status_proxy']);
        add_action('wp_ajax_blitzcdn_get_zip_migration_stats', [$this, 'ajax_get_zip_migration_stats_proxy']);
        add_action('wp_ajax_blitzcdn_reset_zip_migration', [$this, 'ajax_reset_zip_migration_proxy']);
        add_action('wp_ajax_blitzcdn_cancel_zip_migration', [$this, 'ajax_cancel_zip_migration_proxy']);
    }

    // Proxy methods for ZipMigrator AJAX handlers
    public function ajax_start_zip_migration_proxy() {
        Core::get_instance()->get_zip_migrator()->ajax_start_zip_migration();
    }

    public function ajax_continue_zip_migration_proxy() {
        Core::get_instance()->get_zip_migrator()->ajax_continue_zip_migration();
    }

    public function ajax_get_zip_migration_status_proxy() {
        Core::get_instance()->get_zip_migrator()->ajax_get_zip_migration_status();
    }

    public function ajax_get_zip_migration_stats_proxy() {
        Core::get_instance()->get_zip_migrator()->ajax_get_zip_migration_stats();
    }

    public function ajax_reset_zip_migration_proxy() {
        Core::get_instance()->get_zip_migrator()->ajax_reset_zip_migration();
    }

    public function ajax_cancel_zip_migration_proxy() {
        Core::get_instance()->get_zip_migrator()->ajax_cancel_zip_migration();
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

        $image_count = $query->found_posts;
        $ids = $query->posts;
        
        // Count total assets (original + sizes) instead of just images
        $total_assets = \BlitzCDN\Core::count_total_assets($ids);
        
        wp_send_json_success([
            'total' => $total_assets, // Total assets, not images
            'total_images' => $image_count, // Keep image count for reference
            'ids' => $ids
        ]);
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
            try {
                $metadata = wp_get_attachment_metadata($id);
                if (!$metadata) {
                    $results[$id] = ['status' => 'error', 'message' => 'No metadata'];
                    continue;
                }

                // Reuse the logic in UploadHandler
                // We need to make sure we don't double-process if already done, but the query filters that.
                // However, handle_upload_phase_2 checks for existing meta too.
                
                $new_metadata = $upload_handler->handle_upload_phase_2($metadata, $id);
                
                // Count assets processed for this attachment
                $assets_count = Core::count_assets_per_attachment($id);
                
                // We don't strictly need to update metadata if handle_upload_phase_2 only modifies side-effects (postmeta),
                // but it returns metadata, and sometimes plugins modify it.
                // In our case, handle_upload_phase_2 modifies postmeta directly for BlitzCDN fields.
                // It doesn't modify the $metadata array structure regarding sizes (it reads it).
                // So we might not need wp_update_attachment_metadata unless we changed something inside $metadata.
                // But let's be safe.
                
                $results[$id] = [
                    'status' => 'success',
                    'assets_count' => $assets_count // Return asset count for progress tracking
                ];
            } catch (\Exception $e) {
                error_log('BlitzCDN: Error processing attachment ' . $id . ': ' . $e->getMessage());
                $results[$id] = ['status' => 'error', 'message' => $e->getMessage()];
                // Continue processing other attachments
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
        
        // Count total assets (original + sizes) instead of just images
        $total_assets = Core::count_total_assets($stats['ids']);
        $stats['total'] = $total_assets; // Override with asset count
        $stats['total_images'] = count($stats['ids']); // Keep image count for reference

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

    /**
     * AJAX: Get statistics for email-based redownload.
     */
    public function ajax_get_email_stats() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        
        if (empty($email) || !is_email($email)) {
            wp_send_json_error('Invalid email address');
        }

        // Verify Appwrite client is configured
        if (!$this->appwrite_client->is_configured()) {
            wp_send_json_error('Appwrite is not configured. Please check your environment settings.');
        }

        $current_user = wp_get_current_user();
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        error_log(sprintf('BlitzCDN: ajax_get_email_stats called by %s (%s) email=%s', $current_user->user_login, $remote_ip, $email));

        $email_redownloader = Core::get_instance()->get_email_redownloader();
        $stats = $email_redownloader->get_email_stats($email);

        wp_send_json($stats);
    }

    /**
     * AJAX: Process a batch of files for email-based redownload.
     */
    public function ajax_email_redownload_batch() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $file_ids = isset($_POST['file_ids']) ? array_map('sanitize_text_field', $_POST['file_ids']) : [];
        $create_attachments = isset($_POST['create_attachments']) ? ($_POST['create_attachments'] === 'true') : true;

        if (empty($file_ids)) {
            wp_send_json_error('No file IDs provided');
        }

        // Verify Appwrite client is configured
        if (!$this->appwrite_client->is_configured()) {
            wp_send_json_error('Appwrite is not configured. Please check your environment settings.');
        }

        $current_user = wp_get_current_user();
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        error_log(sprintf('BlitzCDN: ajax_email_redownload_batch called by %s (%s) files=%s', $current_user->user_login, $remote_ip, implode(',', $file_ids)));

        $email_redownloader = Core::get_instance()->get_email_redownloader();
        $results = $email_redownloader->process_batch($file_ids, $create_attachments);

        wp_send_json_success($results);
    }

    /**
     * AJAX: Process a batch of files for email-based linking (no download).
     */
    public function ajax_email_link_batch() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        // Increase time limit for batch processing
        @set_time_limit(300); // 5 minutes
        @ini_set('memory_limit', '256M');

        $file_ids = isset($_POST['file_ids']) ? array_map('sanitize_text_field', $_POST['file_ids']) : [];

        if (empty($file_ids)) {
            wp_send_json_error('No file IDs provided');
        }

        // Verify Appwrite client is configured
        if (!$this->appwrite_client->is_configured()) {
            wp_send_json_error('Appwrite is not configured. Please check your environment settings.');
        }

        $current_user = wp_get_current_user();
        $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        error_log(sprintf('BlitzCDN: ajax_email_link_batch called by %s (%s) files=%s', $current_user->user_login, $remote_ip, implode(',', $file_ids)));

        $email_redownloader = Core::get_instance()->get_email_redownloader();
        $results = $email_redownloader->link_batch($file_ids);

        wp_send_json_success($results);
    }

    /**
     * AJAX: Start background linking process for email.
     */
    public function ajax_start_background_link() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

        if (empty($email) || !is_email($email)) {
            wp_send_json_error('Invalid email address');
        }

        $background_linker = Core::get_instance()->get_background_email_linker();
        $result = $background_linker->start_linking($email);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * AJAX: Stop background linking process.
     */
    public function ajax_stop_background_link() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $background_linker = Core::get_instance()->get_background_email_linker();
        $background_linker->stop_linking();

        wp_send_json_success('Background linking stopped');
    }

    /**
     * AJAX: Get background linking status.
     */
    public function ajax_get_background_link_status() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $background_linker = Core::get_instance()->get_background_email_linker();
        $status = $background_linker->get_status();

        wp_send_json_success($status);
    }

    public function ajax_reconnect_woocommerce() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        // Get offset parameter for batch processing
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = 50; // Process 50 attachments per batch

        try {
            $email_redownloader = Core::get_instance()->get_email_redownloader();
            
            if (!$email_redownloader) {
                wp_send_json_error(['message' => 'Email redownloader not available']);
                return;
            }
            
            $result = $email_redownloader->reconnect_woocommerce_images($offset, $limit);

            if ($result['status'] === 'success') {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        } catch (\Exception $e) {
            error_log('BlitzCDN: AJAX reconnect WooCommerce exception: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Exception: ' . $e->getMessage()]);
        }
    }

    /**
     * AJAX handler to fix CDN URL structure (add missing /v1/ path).
     */
    public function ajax_fix_url_structure() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
            return;
        }

        global $wpdb;

        // Get offset and limit for batch processing
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = 50; // Process 50 attachments per batch

        try {
            // Get Appwrite settings from AppwriteClient (which loads from .env)
            $appwrite_client = new \BlitzCDN\AppwriteClient();
            $endpoint = $appwrite_client->get_endpoint();
            $bucket_id = $appwrite_client->get_bucket_id();
            $project_id = $appwrite_client->get_project_id();
            $cdn_domain = $appwrite_client->get_cdn_domain();
            
            // Log settings for debugging (only on first batch)
            if ($offset === 0) {
                error_log('BlitzCDN Fix URL Structure - Settings Check:');
                error_log('  Endpoint: ' . ($endpoint ?: 'EMPTY'));
                error_log('  Bucket ID: ' . ($bucket_id ?: 'EMPTY'));
                error_log('  Project ID: ' . ($project_id ?: 'EMPTY'));
                error_log('  CDN Domain: ' . ($cdn_domain ?: 'EMPTY'));
            }
            
            // Get total count first
            $total_count = $wpdb->get_var(
                "SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_url ON p.ID = pm_url.post_id AND pm_url.meta_key = '_blitzcdn_cdn_url'
                LEFT JOIN {$wpdb->postmeta} pm_file_id ON p.ID = pm_file_id.post_id AND pm_file_id.meta_key = '_blitzcdn_file_id'
                WHERE p.post_type = 'attachment'
                AND (pm_url.meta_value != '' OR pm_file_id.meta_value != '' OR p.guid LIKE '%storage/buckets%')"
            );

            // Get batch of attachments with BlitzCDN URLs and also check GUIDs
            // Order by potentially broken URLs first (those missing /view or project=)
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT p.ID, 
                            pm_url.meta_value as cdn_url,
                            p.guid as post_guid,
                            pm_file_id.meta_value as file_id,
                            CASE 
                                WHEN (pm_url.meta_value NOT LIKE '%%/view%%' OR pm_url.meta_value NOT LIKE '%%project=%%') THEN 1
                                WHEN (p.guid NOT LIKE '%%/view%%' OR p.guid NOT LIKE '%%project=%%') THEN 1
                                ELSE 2
                            END as priority
                    FROM {$wpdb->posts} p
                    LEFT JOIN {$wpdb->postmeta} pm_url ON p.ID = pm_url.post_id AND pm_url.meta_key = '_blitzcdn_cdn_url'
                    LEFT JOIN {$wpdb->postmeta} pm_file_id ON p.ID = pm_file_id.post_id AND pm_file_id.meta_key = '_blitzcdn_file_id'
                    WHERE p.post_type = 'attachment'
                    AND (pm_url.meta_value != '' OR pm_file_id.meta_value != '' OR p.guid LIKE '%%storage/buckets%%')
                    ORDER BY priority ASC, p.ID DESC
                    LIMIT %d OFFSET %d",
                    $limit,
                    $offset
                )
            );

            $fixed_count = 0;
            $already_correct = 0;
            $reconstructed_count = 0;
            $sample_urls = [];
            $processed_urls = []; // Track all processed URLs for real-time logging

            foreach ($results as $attachment) {
                $cdn_url = $attachment->cdn_url;
                $post_guid = $attachment->post_guid;
                $file_id = $attachment->file_id;
                
                // Collect sample URLs for first batch only
                if ($offset === 0 && count($sample_urls) < 3) {
                    $sample_urls[] = [
                        'id' => $attachment->ID,
                        'cdn_url' => $cdn_url ?: 'none',
                        'guid' => $post_guid ?: 'none',
                        'file_id' => $file_id ?: 'none'
                    ];
                }

                $urls_to_check = array_filter([$cdn_url, $post_guid]);
                $needs_update = false;
                $new_cdn_url = null;
                $old_url = $cdn_url ?: $post_guid;
                $action_type = 'skip';
                
                // Create detailed log entry for this attachment
                $log_entry = [
                    'id' => $attachment->ID,
                    'cdn_url' => $cdn_url ?: 'empty',
                    'post_guid' => $post_guid ?: 'empty',
                    'file_id' => $file_id ?: 'empty',
                    'checks' => []
                ];

                foreach ($urls_to_check as $url) {
                    if (empty($url)) {
                        $log_entry['checks'][] = 'URL is empty, skipping';
                        continue;
                    }
                    
                    $log_entry['checks'][] = 'Checking URL: ' . $url;

                    // Check if URL is a CDN URL (has /storage/buckets/)
                    if (preg_match('#/storage/buckets/#', $url)) {
                        $log_entry['checks'][] = '✓ Contains /storage/buckets/';
                        $is_broken = false;
                        $reasons = [];
                        
                        // Check 1: Missing /v1/ API version
                        $has_v1 = preg_match('#/v1/storage/buckets/#', $url);
                        if (!$has_v1) {
                            $is_broken = true;
                            $reasons[] = 'Missing /v1/ API version';
                            $log_entry['checks'][] = '✗ Missing /v1/ API version';
                        } else {
                            $log_entry['checks'][] = '✓ Has /v1/ API version';
                        }
                        
                        // Check 2: Missing /view endpoint
                        $has_view = preg_match('#/view(\?|&)#', $url);
                        if (!$has_view) {
                            $is_broken = true;
                            $reasons[] = 'Missing /view endpoint';
                            $log_entry['checks'][] = '✗ Missing /view endpoint';
                        } else {
                            $log_entry['checks'][] = '✓ Has /view endpoint';
                        }
                        
                        // Check 3: Missing project= parameter
                        $has_project = preg_match('#project=#', $url);
                        if (!$has_project) {
                            $is_broken = true;
                            $reasons[] = 'Missing project= parameter';
                            $log_entry['checks'][] = '✗ Missing project= parameter';
                        } else {
                            $log_entry['checks'][] = '✓ Has project= parameter';
                        }
                        
                        if ($is_broken) {
                            $log_entry['checks'][] = '⚠ URL is BROKEN: ' . implode(', ', $reasons);
                            
                            // Extract the file ID from the URL
                            if (preg_match('#/files/([a-f0-9]+)(?:/|$)#', $url, $matches)) {
                                $extracted_file_id = $matches[1];
                                $log_entry['checks'][] = 'Extracted file ID: ' . $extracted_file_id;
                                $log_entry['checks'][] = 'Settings - Endpoint: ' . ($endpoint ?: 'MISSING');
                                $log_entry['checks'][] = 'Settings - Bucket: ' . ($bucket_id ?: 'MISSING');
                                $log_entry['checks'][] = 'Settings - Project: ' . ($project_id ?: 'MISSING');
                                
                                if ($endpoint && $bucket_id && $project_id) {
                                    $base_url = !empty($cdn_domain) ? $cdn_domain : $endpoint;
                                    
                                    // Ensure proper URL format
                                    $base_url = rtrim($base_url, '/');
                                    if (!preg_match('/^https?:\/\//', $base_url)) {
                                        $base_url = 'https://' . $base_url;
                                    }
                                    
                                    // Add /v1 if not present
                                    if (!preg_match('/\/v1$/', $base_url)) {
                                        $base_url .= '/v1';
                                    }
                                    
                                    // Reconstruct the proper URL with /view?project=
                                    $new_cdn_url = $base_url . '/storage/buckets/' . $bucket_id . '/files/' . $extracted_file_id . '/view?project=' . $project_id;
                                    $needs_update = true;
                                    $action_type = 'fixed';
                                    $log_entry['checks'][] = '🔧 Reconstructed URL: ' . $new_cdn_url;
                                    break;
                                } else {
                                    $log_entry['checks'][] = '✗ Missing settings to reconstruct URL';
                                }
                            } else {
                                $log_entry['checks'][] = '✗ Could not extract file ID from URL';
                            }
                        } else {
                            $log_entry['checks'][] = '✓ URL is valid';
                        }
                    } else {
                        $log_entry['checks'][] = '✗ Not a CDN URL (no /storage/buckets/)';
                    }
                }
                
                // If we still don't have a valid URL but have a file_id from metadata, reconstruct it
                // Only reconstruct if we truly have no valid URL (empty cdn_url AND empty/invalid guid)
                if (!$needs_update && !empty($file_id) && (empty($cdn_url) && empty($post_guid))) {
                    $log_entry['checks'][] = '⚠ No valid URL found, attempting reconstruction from file_id: ' . $file_id;
                    $log_entry['checks'][] = 'Settings available - Endpoint: ' . ($endpoint ?: 'MISSING') . ', Bucket: ' . ($bucket_id ?: 'MISSING') . ', Project: ' . ($project_id ?: 'MISSING');

                    if ($endpoint && $bucket_id && $project_id) {
                        $base_url = !empty($cdn_domain) ? $cdn_domain : $endpoint;
                        
                        // Ensure proper URL format
                        $base_url = rtrim($base_url, '/');
                        if (!preg_match('/^https?:\/\//', $base_url)) {
                            $base_url = 'https://' . $base_url;
                        }
                        
                        // Add /v1 if not present
                        if (!preg_match('/\/v1$/', $base_url)) {
                            $base_url .= '/v1';
                        }
                        
                        $new_cdn_url = $base_url . '/storage/buckets/' . $bucket_id . '/files/' . $file_id . '/view?project=' . $project_id;
                        $needs_update = true;
                        $action_type = 'reconstructed';
                        $reconstructed_count++;
                        $log_entry['checks'][] = '🔧 Reconstructed from file_id: ' . $new_cdn_url;
                    } else {
                        $log_entry['checks'][] = '✗ Cannot reconstruct - missing settings';
                    }
                }

                if ($needs_update && $new_cdn_url) {
                    $log_entry['checks'][] = '💾 Updating database...';
                    
                    // Clear WordPress cache for this post first
                    clean_post_cache($attachment->ID);
                    
                    // Update _blitzcdn_cdn_url
                    update_post_meta($attachment->ID, '_blitzcdn_cdn_url', $new_cdn_url);
                    
                    // Update post GUID 
                    $update_result = $wpdb->update(
                        $wpdb->posts,
                        ['guid' => $new_cdn_url],
                        ['ID' => $attachment->ID],
                        ['%s'],
                        ['%d']
                    );
                    
                    // Log the update result
                    if ($update_result === false) {
                        $log_entry['checks'][] = '✗ Failed to update GUID: ' . $wpdb->last_error;
                    } else {
                        $log_entry['checks'][] = '✓ Updated GUID (rows affected: ' . $update_result . ')';
                    }
                    
                    // Fix image size URLs in metadata
                    $metadata = wp_get_attachment_metadata($attachment->ID);
                    if (!empty($metadata['sizes'])) {
                        $sizes_fixed = 0;
                        $sizes_details = [];
                        
                        $log_entry['checks'][] = 'Checking ' . count($metadata['sizes']) . ' image size variants...';
                        
                        foreach ($metadata['sizes'] as $size_name => &$size_data) {
                            if (!empty($size_data['cdn_url'])) {
                                $old_size_url = $size_data['cdn_url'];
                                $is_size_broken = false;
                                $size_issues = [];
                                
                                // Check if this size URL is broken (same checks as main URL)
                                if (preg_match('#/storage/buckets/#', $old_size_url)) {
                                    // Check for missing /v1/ or missing /view endpoint or missing project=
                                    if (!preg_match('#/v1/storage/buckets/#', $old_size_url)) {
                                        $is_size_broken = true;
                                        $size_issues[] = 'no /v1/';
                                    }
                                    if (!preg_match('#/view(\?|&)#', $old_size_url)) {
                                        $is_size_broken = true;
                                        $size_issues[] = 'no /view';
                                    }
                                    if (!preg_match('#project=#', $old_size_url)) {
                                        $is_size_broken = true;
                                        $size_issues[] = 'no project=';
                                    }
                                }
                                
                                if ($is_size_broken) {
                                    $log_entry['checks'][] = '  ⚠ Size "' . $size_name . '" is BROKEN: ' . implode(', ', $size_issues);
                                    $log_entry['checks'][] = '    Old: ' . $old_size_url;
                                    
                                    if ($endpoint && $bucket_id && $project_id) {
                                        // Extract file ID from the broken size URL
                                        if (preg_match('#/files/([a-f0-9]+)(?:/|$)#', $old_size_url, $matches)) {
                                            $size_file_id = $matches[1];
                                            
                                            $base_url = !empty($cdn_domain) ? $cdn_domain : $endpoint;
                                            $base_url = rtrim($base_url, '/');
                                            if (!preg_match('/^https?:\/\//', $base_url)) {
                                                $base_url = 'https://' . $base_url;
                                            }
                                            if (!preg_match('/\/v1$/', $base_url)) {
                                                $base_url .= '/v1';
                                            }
                                            
                                            // Reconstruct the proper URL for this size
                                            $size_data['cdn_url'] = $base_url . '/storage/buckets/' . $bucket_id . '/files/' . $size_file_id . '/view?project=' . $project_id;
                                            $sizes_fixed++;
                                            $sizes_details[] = $size_name;
                                            $log_entry['checks'][] = '    ✓ Fixed: ' . $size_data['cdn_url'];
                                        } else {
                                            $log_entry['checks'][] = '    ✗ Could not extract file ID';
                                        }
                                    } else {
                                        $sizes_details[] = $size_name . ' (no settings)';
                                        $log_entry['checks'][] = '    ✗ Cannot fix - missing settings';
                                    }
                                }
                            }
                        }
                        
                        if ($sizes_fixed > 0) {
                            $metadata_update_result = wp_update_attachment_metadata($attachment->ID, $metadata);
                            if ($metadata_update_result) {
                                $log_entry['checks'][] = '✓ Fixed ' . $sizes_fixed . ' image sizes: ' . implode(', ', $sizes_details);
                            } else {
                                $log_entry['checks'][] = '✗ Failed to save metadata for image sizes';
                            }
                        } else if (!empty($sizes_details)) {
                            $log_entry['checks'][] = '⚠ Found broken sizes but could not fix: ' . implode(', ', $sizes_details);
                        }
                    }
                    
                    // Verify the update was saved
                    $saved_cdn_url = get_post_meta($attachment->ID, '_blitzcdn_cdn_url', true);
                    if ($saved_cdn_url === $new_cdn_url) {
                        $log_entry['checks'][] = '✓ Verified - URL saved correctly';
                    } else {
                        $log_entry['checks'][] = '✗ WARNING - URL not saved! Expected: ' . $new_cdn_url . ', Got: ' . $saved_cdn_url;
                    }
                    
                    // Add to processed URLs log
                    $processed_urls[] = [
                        'id' => $attachment->ID,
                        'old_url' => $old_url,
                        'new_url' => $new_cdn_url,
                        'action' => $action_type,
                        'log' => implode(' | ', $log_entry['checks'])
                    ];
                    
                    $fixed_count++;
                        } else {
                            // Main URL is correct, but check if image size URLs need fixing
                            $log_entry['checks'][] = 'Main URL appears correct, checking image size variants...';
                            
                            $metadata = wp_get_attachment_metadata($attachment->ID);
                            if (!empty($metadata['sizes'])) {
                                $sizes_fixed = 0;
                                $sizes_details = [];
                                
                                $log_entry['checks'][] = 'Found ' . count($metadata['sizes']) . ' image size variants';
                                
                                // Check if _blitzcdn_sizes exists
                                $existing_blitzcdn_sizes = get_post_meta($attachment->ID, '_blitzcdn_sizes', true);
                                $needs_blitzcdn_sizes = empty($existing_blitzcdn_sizes);
                                
                                if ($needs_blitzcdn_sizes) {
                                    // _blitzcdn_sizes doesn't exist, create it from existing valid cdn_url fields
                                    $blitzcdn_sizes = [];
                                    foreach ($metadata['sizes'] as $size_name => $size_data) {
                                        if (!empty($size_data['cdn_url'])) {
                                            $blitzcdn_sizes[$size_name] = [
                                                'url' => $size_data['cdn_url'],
                                                'width' => isset($size_data['width']) ? $size_data['width'] : 0,
                                                'height' => isset($size_data['height']) ? $size_data['height'] : 0
                                            ];
                                        }
                                    }
                                    
                                    if (!empty($blitzcdn_sizes)) {
                                        update_post_meta($attachment->ID, '_blitzcdn_sizes', $blitzcdn_sizes);
                                        $log_entry['checks'][] = '✓ Created _blitzcdn_sizes meta from existing valid URLs (' . count($blitzcdn_sizes) . ' sizes)';
                                        $needs_update = true; // Mark as updated
                                        $action_type = 'linked';
                                    }
                                }
                        
                        $log_entry['checks'][] = 'Found ' . count($metadata['sizes']) . ' image size variants';
                        
                        foreach ($metadata['sizes'] as $size_name => &$size_data) {
                            if (!empty($size_data['cdn_url'])) {
                                $old_size_url = $size_data['cdn_url'];
                                $is_size_broken = false;
                                $size_issues = [];
                                
                                $log_entry['checks'][] = '  Checking size "' . $size_name . '": ' . $old_size_url;
                                
                                // Check if this size URL is broken (same checks as main URL)
                                if (preg_match('#/storage/buckets/#', $old_size_url)) {
                                    // Check for missing /v1/ or missing /view endpoint or missing project=
                                    if (!preg_match('#/v1/storage/buckets/#', $old_size_url)) {
                                        $is_size_broken = true;
                                        $size_issues[] = 'missing /v1/';
                                    }
                                    if (!preg_match('#/view(\?|&)#', $old_size_url)) {
                                        $is_size_broken = true;
                                        $size_issues[] = 'missing /view';
                                    }
                                    if (!preg_match('#project=#', $old_size_url)) {
                                        $is_size_broken = true;
                                        $size_issues[] = 'missing project=';
                                    }
                                    
                                    if ($is_size_broken) {
                                        $log_entry['checks'][] = '    ✗ BROKEN: ' . implode(', ', $size_issues);
                                    } else {
                                        $log_entry['checks'][] = '    ✓ Valid';
                                    }
                                }
                                
                                if ($is_size_broken && $endpoint && $bucket_id && $project_id) {
                                    // Extract file ID from the broken size URL
                                    if (preg_match('#/files/([a-f0-9]+)(?:/|$)#', $old_size_url, $matches)) {
                                        $size_file_id = $matches[1];
                                        $log_entry['checks'][] = '    Extracted file ID: ' . $size_file_id;
                                        
                                        $base_url = !empty($cdn_domain) ? $cdn_domain : $endpoint;
                                        $base_url = rtrim($base_url, '/');
                                        if (!preg_match('/^https?:\/\//', $base_url)) {
                                            $base_url = 'https://' . $base_url;
                                        }
                                        if (!preg_match('/\/v1$/', $base_url)) {
                                            $base_url .= '/v1';
                                        }
                                        
                                        // Reconstruct the proper URL for this size
                                        $new_size_url = $base_url . '/storage/buckets/' . $bucket_id . '/files/' . $size_file_id . '/view?project=' . $project_id;
                                        $size_data['cdn_url'] = $new_size_url;
                                        $sizes_fixed++;
                                        $sizes_details[] = $size_name;
                                        $log_entry['checks'][] = '    ✓ Reconstructed: ' . $new_size_url;
                                    } else {
                                        $log_entry['checks'][] = '    ✗ Could not extract file ID';
                                    }
                                } else if ($is_size_broken) {
                                    $sizes_details[] = $size_name . '(no settings)';
                                    $log_entry['checks'][] = '    ✗ Cannot fix - Settings: Endpoint=' . ($endpoint ?: 'NONE') . ', Bucket=' . ($bucket_id ?: 'NONE') . ', Project=' . ($project_id ?: 'NONE');
                                }
                            }
                        }
                        
                        if ($sizes_fixed > 0) {
                            // Clear cache before updating
                            clean_post_cache($attachment->ID);
                            
                            $metadata_update_result = wp_update_attachment_metadata($attachment->ID, $metadata);
                            if ($metadata_update_result) {
                                $log_entry['checks'][] = '🔧 Fixed ' . $sizes_fixed . ' broken image sizes: ' . implode(', ', $sizes_details);
                                
                                // Verify the metadata was saved
                                $saved_metadata = wp_get_attachment_metadata($attachment->ID);
                                $verified = true;
                                foreach ($sizes_details as $size_name) {
                                    if (!isset($saved_metadata['sizes'][$size_name]['cdn_url'])) {
                                        $verified = false;
                                        break;
                                    }
                                }
                                if ($verified) {
                                    $log_entry['checks'][] = '  ✓ Verified - Metadata saved correctly';
                                } else {
                                    $log_entry['checks'][] = '  ✗ WARNING - Metadata may not have saved correctly';
                                }
                            } else {
                                $log_entry['checks'][] = '✗ Failed to save metadata for image sizes';
                            }
                            $fixed_count++;
                            
                            // Add to processed URLs log as a fix
                            $processed_urls[] = [
                                'id' => $attachment->ID,
                                'old_url' => $old_url,
                                'new_url' => $old_url . ' (+ ' . $sizes_fixed . ' sizes)',
                                'action' => 'fixed',
                                'log' => implode(' | ', $log_entry['checks'])
                            ];
                        } else {
                            $already_correct++;
                            
                            if (empty($urls_to_check)) {
                                $log_entry['checks'][] = '⚠ No URLs found to check';
                            }
                            
                            // Add to log as already correct
                            $processed_urls[] = [
                                'id' => $attachment->ID,
                                'url' => $old_url,
                                'action' => 'correct',
                                'log' => implode(' | ', $log_entry['checks'])
                            ];
                        }
                    } else {
                        $already_correct++;
                        
                        if (empty($urls_to_check)) {
                            $log_entry['checks'][] = '⚠ No URLs found to check';
                        } else {
                            $log_entry['checks'][] = 'No image size variants found';
                        }
                        
                        // Add to log as already correct
                        $processed_urls[] = [
                            'id' => $attachment->ID,
                            'url' => $old_url,
                            'action' => 'correct',
                            'log' => implode(' | ', $log_entry['checks'])
                        ];
                    }
                }
            }

            // After fixing attachment metadata, also fix URLs embedded in post content
            // This handles URLs hardcoded in WooCommerce products, pages, posts, etc.
            $content_fixes = 0;
            if ($offset === 0 && $endpoint && $bucket_id && $project_id) {
                // Only run this on the first batch to avoid duplicating work
                $processed_urls[] = [
                    'id' => 'content',
                    'action' => 'info',
                    'log' => '🔍 Scanning post content for broken URLs...'
                ];
                
                // Find all posts with potential broken CDN URLs in content
                $posts_with_cdn_urls = $wpdb->get_results(
                    "SELECT ID, post_content, post_type 
                    FROM {$wpdb->posts} 
                    WHERE post_content LIKE '%storage/buckets%' 
                    AND post_status = 'publish'
                    LIMIT 100"
                );
                
                foreach ($posts_with_cdn_urls as $post) {
                    $original_content = $post->post_content;
                    $updated_content = $original_content;
                    $post_changes = 0;
                    
                    // Pattern to match broken CDN URLs with filenames
                    // Matches: /v1/storage/buckets/{bucket}/files/{file_id}/filename.jpg
                    // Should be: /v1/storage/buckets/{bucket}/files/{file_id}/view?project={project}
                    $pattern = '#(https?://[^/]+/v1/storage/buckets/[^/]+/files/([a-f0-9]+))/[^/\s"\'\)]+\.(jpg|jpeg|png|gif|webp|svg)#i';
                    
                    if (preg_match_all($pattern, $original_content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $broken_url = $match[0];
                            $base_path = $match[1];
                            $file_id = $match[2];
                            
                            // Construct proper URL
                            $base_url = !empty($cdn_domain) ? $cdn_domain : $endpoint;
                            $base_url = rtrim($base_url, '/');
                            if (!preg_match('/^https?:\/\//', $base_url)) {
                                $base_url = 'https://' . $base_url;
                            }
                            if (!preg_match('/\/v1$/', $base_url)) {
                                $base_url .= '/v1';
                            }
                            
                            $correct_url = $base_url . '/storage/buckets/' . $bucket_id . '/files/' . $file_id . '/view?project=' . $project_id;
                            $updated_content = str_replace($broken_url, $correct_url, $updated_content);
                            $post_changes++;
                        }
                    }
                    
                    // Also fix URLs missing /view and project= but not necessarily with filenames
                    $pattern2 = '#(https?://[^/]+)/v1/storage/buckets/([^/]+)/files/([a-f0-9]+)(?!/view)([^\s"\'\)]*?)(?=["\s\)])#i';
                    if (preg_match_all($pattern2, $updated_content, $matches2, PREG_SET_ORDER)) {
                        foreach ($matches2 as $match) {
                            $broken_url = $match[0];
                            $file_id = $match[3];
                            
                            // Construct proper URL
                            $base_url = !empty($cdn_domain) ? $cdn_domain : $endpoint;
                            $base_url = rtrim($base_url, '/');
                            if (!preg_match('/^https?:\/\//', $base_url)) {
                                $base_url = 'https://' . $base_url;
                            }
                            if (!preg_match('/\/v1$/', $base_url)) {
                                $base_url .= '/v1';
                            }
                            
                            $correct_url = $base_url . '/storage/buckets/' . $bucket_id . '/files/' . $file_id . '/view?project=' . $project_id;
                            $updated_content = str_replace($broken_url, $correct_url, $updated_content);
                            $post_changes++;
                        }
                    }
                    
                    if ($post_changes > 0 && $updated_content !== $original_content) {
                        $wpdb->update(
                            $wpdb->posts,
                            ['post_content' => $updated_content],
                            ['ID' => $post->ID],
                            ['%s'],
                            ['%d']
                        );
                        $content_fixes++;
                        
                        $processed_urls[] = [
                            'id' => $post->ID,
                            'action' => 'content_fixed',
                            'log' => '✓ Fixed ' . $post_changes . ' URLs in ' . $post->post_type . ' #' . $post->ID
                        ];
                    }
                }
                
                if ($content_fixes > 0) {
                    $processed_urls[] = [
                        'id' => 'content_summary',
                        'action' => 'success',
                        'log' => '✅ Fixed URLs in ' . $content_fixes . ' posts/products'
                    ];
                } else {
                    $processed_urls[] = [
                        'id' => 'content_summary',
                        'action' => 'info',
                        'log' => '✓ No broken URLs found in post content'
                    ];
                }
            }
            
            // Check if there are more attachments to process
            $next_offset = $offset + $limit;
            $has_more = ($next_offset < $total_count);
            
            // Flush WordPress object cache to ensure all updates are persisted
            wp_cache_flush();

            wp_send_json_success([
                'message' => sprintf(
                    'Processed batch %d: %d fixed (%d reconstructed), %d already correct',
                    floor($offset / $limit) + 1,
                    $fixed_count,
                    $reconstructed_count,
                    $already_correct
                ),
                'fixed' => $fixed_count,
                'already_correct' => $already_correct,
                'reconstructed' => $reconstructed_count,
                'processed' => count($results),
                'total_attachments' => (int)$total_count,
                'has_more' => $has_more,
                'next_offset' => $next_offset,
                'sample_urls' => $sample_urls,
                'processed_urls' => $processed_urls,
                'settings_check' => [ // Add settings info for debugging
                    'endpoint' => $endpoint ?: 'NOT SET',
                    'bucket_id' => $bucket_id ?: 'NOT SET',
                    'project_id' => $project_id ?: 'NOT SET',
                    'cdn_domain' => $cdn_domain ?: 'NOT SET'
                ]
            ]);

        } catch (\Exception $e) {
            error_log('BlitzCDN: URL fix error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
}
