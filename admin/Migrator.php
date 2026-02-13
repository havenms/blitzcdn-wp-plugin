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

        try {
            // Get all attachments with BlitzCDN URLs and also check GUIDs
            $results = $wpdb->get_results(
                "SELECT p.ID, 
                        pm_url.meta_value as cdn_url,
                        p.guid as post_guid,
                        pm_file_id.meta_value as file_id
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm_url ON p.ID = pm_url.post_id AND pm_url.meta_key = '_blitzcdn_cdn_url'
                LEFT JOIN {$wpdb->postmeta} pm_file_id ON p.ID = pm_file_id.post_id AND pm_file_id.meta_key = '_blitzcdn_file_id'
                WHERE p.post_type = 'attachment'
                AND (pm_url.meta_value != '' OR pm_file_id.meta_value != '' OR p.guid LIKE '%storage/buckets%')
                ORDER BY p.ID DESC
                LIMIT 100"
            );

            $fixed_count = 0;
            $already_correct = 0;
            $reconstructed_count = 0;
            $sample_urls = [];

            foreach ($results as $attachment) {
                $cdn_url = $attachment->cdn_url;
                $post_guid = $attachment->post_guid;
                $file_id = $attachment->file_id;
                
                // Collect sample URLs for debugging
                if (count($sample_urls) < 5) {
                    $sample_urls[] = [
                        'id' => $attachment->ID,
                        'cdn_url' => $cdn_url,
                        'guid' => $post_guid,
                        'file_id' => $file_id
                    ];
                }

                $urls_to_check = array_filter([$cdn_url, $post_guid]);
                $needs_update = false;
                $new_cdn_url = null;

                foreach ($urls_to_check as $url) {
                    if (empty($url)) continue;

                    // Check if URL has storage/buckets but is missing /v1/
                    if (preg_match('#/storage/buckets/#', $url) && !preg_match('#/v1/storage/buckets/#', $url)) {
                        // Fix the URL by adding /v1/ before /storage
                        $fixed_url = preg_replace('#(https?://[^/]+)(/storage/buckets/)#', '$1/v1$2', $url);
                        
                        if ($fixed_url !== $url) {
                            $new_cdn_url = $fixed_url;
                            $needs_update = true;
                            break;
                        }
                    }
                }

                // If we have a file_id but no proper CDN URL, reconstruct it
                if (!$needs_update && !empty($file_id) && (empty($cdn_url) || !preg_match('#/v1/storage/buckets/#', $cdn_url))) {
                    // Get Appwrite settings to reconstruct the URL
                    $settings = get_option('blitzcdn_settings', []);
                    $endpoint = $settings['endpoint'] ?? '';
                    $bucket_id = $settings['bucket_id'] ?? '';
                    $project_id = $settings['project_id'] ?? '';
                    $cdn_domain = $settings['cdn_domain'] ?? '';

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
                        $reconstructed_count++;
                    }
                }

                if ($needs_update && $new_cdn_url) {
                    // Update _blitzcdn_cdn_url
                    update_post_meta($attachment->ID, '_blitzcdn_cdn_url', $new_cdn_url);
                    
                    // Update post GUID 
                    $wpdb->update(
                        $wpdb->posts,
                        ['guid' => $new_cdn_url],
                        ['ID' => $attachment->ID]
                    );
                    
                    // Fix image size URLs in metadata
                    $metadata = wp_get_attachment_metadata($attachment->ID);
                    if (!empty($metadata['sizes'])) {
                        foreach ($metadata['sizes'] as $size_name => &$size_data) {
                            if (!empty($size_data['cdn_url'])) {
                                $size_data['cdn_url'] = preg_replace('#(https?://[^/]+)(/storage/buckets/)#', '$1/v1$2', $size_data['cdn_url']);
                            }
                        }
                        wp_update_attachment_metadata($attachment->ID, $metadata);
                    }
                    
                    $fixed_count++;
                } else {
                    $already_correct++;
                }
            }

            wp_send_json_success([
                'message' => "Fixed {$fixed_count} URLs ({$reconstructed_count} reconstructed), {$already_correct} already correct",
                'fixed' => $fixed_count,
                'already_correct' => $already_correct,
                'reconstructed' => $reconstructed_count,
                'total' => count($results),
                'sample_urls' => $sample_urls
            ]);

        } catch (\Exception $e) {
            error_log('BlitzCDN: URL fix error: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }
}
