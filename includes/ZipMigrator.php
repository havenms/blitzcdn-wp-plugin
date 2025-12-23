<?php

namespace BlitzCDN;

/**
 * ZipMigrator handles zip-based media migration.
 * 
 * This class packages media files with metadata into a zip archive,
 * uploads to a middleware service for parallel processing, and handles
 * webhook callbacks with results.
 */
class ZipMigrator {

    /**
     * Option key for migration status tracking.
     */
    const MIGRATION_STATUS_OPTION = 'blitzcdn_zip_migration_status';

    /**
     * REST API namespace.
     */
    const REST_NAMESPACE = 'blitzcdn/v1';

    /**
     * Constructor - register hooks.
     */
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register REST API routes for webhook callbacks.
     */
    public function register_rest_routes() {
        register_rest_route(self::REST_NAMESPACE, '/migration-webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook_callback'],
            'permission_callback' => [$this, 'verify_webhook_token'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/migration-status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_migration_status_rest'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Verify webhook token from middleware.
     * 
     * @param \WP_REST_Request $request The REST request.
     * @return bool Whether the token is valid.
     */
    public function verify_webhook_token($request) {
        $settings = get_option('blitzcdn_settings', []);
        $webhook_secret = $settings['webhook_secret'] ?? '';

        if (empty($webhook_secret)) {
            error_log('BlitzCDN: Webhook secret not configured');
            return false;
        }

        // Check Authorization header
        $auth_header = $request->get_header('Authorization');
        if ($auth_header && preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
            $token = $matches[1];
            return hash_equals($webhook_secret, $token);
        }

        // Check X-Webhook-Secret header
        $secret_header = $request->get_header('X-Webhook-Secret');
        if ($secret_header) {
            return hash_equals($webhook_secret, $secret_header);
        }

        // Check body parameter
        $body = $request->get_json_params();
        if (isset($body['webhook_secret'])) {
            return hash_equals($webhook_secret, $body['webhook_secret']);
        }

        error_log('BlitzCDN: No valid webhook token provided');
        return false;
    }

    /**
     * Get attachment IDs that need migration.
     * 
     * @param int $limit Maximum number of IDs to return (0 for all).
     * @return array Array of attachment IDs.
     */
    public function get_unmigrated_attachments($limit = 0) {
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_blitzcdn_file_id',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];

        $query = new \WP_Query($args);
        return $query->posts;
    }

    /**
     * Build metadata for a set of attachments.
     * 
     * @param array $attachment_ids Array of attachment IDs.
     * @return array Metadata structure for the zip file.
     */
    public function build_metadata($attachment_ids) {
        $settings = get_option('blitzcdn_settings', []);
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'];

        $metadata = [
            'version' => '1.0',
            'account_email' => $settings['account_email'] ?? '',
            'webhook_url' => rest_url(self::REST_NAMESPACE . '/migration-webhook'),
            'webhook_secret' => $settings['webhook_secret'] ?? '',
            'site_url' => get_site_url(),
            'attachments' => [],
        ];

        foreach ($attachment_ids as $attachment_id) {
            $file = get_post_meta($attachment_id, '_wp_attached_file', true);
            if (empty($file)) {
                continue;
            }

            $attachment_data = [
                'attachment_id' => (int) $attachment_id,
                'original_file' => $file,
                'local_url' => $base_url . '/' . $file,
                'sizes' => [],
            ];

            // Get intermediate sizes
            $wp_metadata = wp_get_attachment_metadata($attachment_id);
            if (!empty($wp_metadata['sizes']) && is_array($wp_metadata['sizes'])) {
                $subdir = dirname($file);
                foreach ($wp_metadata['sizes'] as $size_name => $size_data) {
                    $size_file = $subdir !== '.' ? $subdir . '/' . $size_data['file'] : $size_data['file'];
                    $attachment_data['sizes'][] = [
                        'name' => $size_name,
                        'file' => $size_file,
                        'local_url' => $base_url . '/' . $size_file,
                        'width' => $size_data['width'] ?? 0,
                        'height' => $size_data['height'] ?? 0,
                    ];
                }
            }

            $metadata['attachments'][] = $attachment_data;
        }

        return $metadata;
    }

    /**
     * Create a zip archive containing media files and metadata.
     * 
     * @param array $attachment_ids Array of attachment IDs to include.
     * @return array|WP_Error Array with 'path' and 'metadata' on success, WP_Error on failure.
     */
    public function create_migration_zip($attachment_ids) {
        if (empty($attachment_ids)) {
            return new \WP_Error('no_attachments', 'No attachments provided for migration.');
        }

        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            return new \WP_Error('zip_unavailable', 'ZipArchive extension is not available.');
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // Create unique filename
        $migration_id = wp_generate_uuid4();
        $zip_filename = 'blitzcdn-migration-' . $migration_id . '.zip';
        $zip_path = $upload_dir['basedir'] . '/' . $zip_filename;

        // Build metadata
        $metadata = $this->build_metadata($attachment_ids);
        $metadata['migration_id'] = $migration_id;
        $metadata['created_at'] = current_time('c');

        // Create zip archive
        $zip = new \ZipArchive();
        $result = $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        
        if ($result !== true) {
            return new \WP_Error('zip_create_failed', 'Failed to create zip archive. Error code: ' . $result);
        }

        $files_added = 0;
        $files_failed = [];

        // Add files to zip
        foreach ($metadata['attachments'] as $attachment) {
            // Add original file
            $original_path = $base_dir . '/' . $attachment['original_file'];
            if (file_exists($original_path)) {
                $zip->addFile($original_path, $attachment['original_file']);
                $files_added++;
            } else {
                $files_failed[] = [
                    'attachment_id' => $attachment['attachment_id'],
                    'file' => $attachment['original_file'],
                    'reason' => 'File not found',
                ];
            }

            // Add size files
            foreach ($attachment['sizes'] as $size) {
                $size_path = $base_dir . '/' . $size['file'];
                if (file_exists($size_path)) {
                    $zip->addFile($size_path, $size['file']);
                    $files_added++;
                } else {
                    $files_failed[] = [
                        'attachment_id' => $attachment['attachment_id'],
                        'file' => $size['file'],
                        'reason' => 'Size file not found',
                    ];
                }
            }
        }

        // Add metadata.json
        $zip->addFromString('metadata.json', wp_json_encode($metadata, JSON_PRETTY_PRINT));

        $zip->close();

        // Update migration status
        $this->update_migration_status([
            'migration_id' => $migration_id,
            'status' => 'zip_created',
            'zip_path' => $zip_path,
            'total_files' => $files_added,
            'files_failed' => $files_failed,
            'attachment_count' => count($attachment_ids),
            'created_at' => current_time('timestamp'),
        ]);

        return [
            'migration_id' => $migration_id,
            'path' => $zip_path,
            'url' => $upload_dir['baseurl'] . '/' . $zip_filename,
            'metadata' => $metadata,
            'files_added' => $files_added,
            'files_failed' => $files_failed,
        ];
    }

    /**
     * Upload zip file to middleware service.
     * 
     * @param string $zip_path Path to the zip file.
     * @param string $migration_id Migration ID.
     * @return array|WP_Error Response data on success, WP_Error on failure.
     */
    public function upload_zip_to_middleware($zip_path, $migration_id) {
        $settings = get_option('blitzcdn_settings', []);
        $middleware_url = $settings['middleware_url'] ?? '';
        $middleware_api_key = $settings['middleware_api_key'] ?? '';

        if (empty($middleware_url)) {
            return new \WP_Error('middleware_not_configured', 'Middleware URL is not configured.');
        }

        if (!file_exists($zip_path)) {
            return new \WP_Error('zip_not_found', 'Zip file not found: ' . $zip_path);
        }

        // Update status
        $this->update_migration_status([
            'status' => 'uploading',
            'upload_started_at' => current_time('timestamp'),
        ]);

        // Prepare the request
        $endpoint = rtrim($middleware_url, '/') . '/api/migrate';
        
        // Use cURL for multipart file upload
        $ch = curl_init();
        
        $post_data = [
            'file' => new \CURLFile($zip_path, 'application/zip', basename($zip_path)),
            'migration_id' => $migration_id,
        ];

        $headers = [
            'Accept: application/json',
        ];

        if (!empty($middleware_api_key)) {
            $headers[] = 'Authorization: Bearer ' . $middleware_api_key;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post_data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 300, // 5 minute timeout for large files
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->update_migration_status([
                'status' => 'upload_failed',
                'error' => $error,
            ]);
            return new \WP_Error('curl_error', 'Failed to upload to middleware: ' . $error);
        }

        if ($http_code < 200 || $http_code >= 300) {
            $this->update_migration_status([
                'status' => 'upload_failed',
                'error' => 'HTTP ' . $http_code . ': ' . $response,
            ]);
            return new \WP_Error('http_error', 'Middleware returned HTTP ' . $http_code . ': ' . $response);
        }

        $data = json_decode($response, true);
        
        // Update status
        $this->update_migration_status([
            'status' => 'processing',
            'upload_completed_at' => current_time('timestamp'),
            'middleware_response' => $data,
        ]);

        return $data;
    }

    /**
     * Handle webhook callback from middleware.
     * 
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_webhook_callback($request) {
        $payload = $request->get_json_params();

        error_log('BlitzCDN: Received webhook callback: ' . wp_json_encode($payload));

        if (empty($payload)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Empty payload',
            ], 400);
        }

        $migration_id = $payload['migration_id'] ?? '';
        $status = $payload['status'] ?? '';
        $results = $payload['results'] ?? [];
        $errors = $payload['errors'] ?? [];

        // Update migration status
        $this->update_migration_status([
            'status' => $status === 'completed' ? 'webhook_received' : 'webhook_error',
            'webhook_received_at' => current_time('timestamp'),
            'webhook_payload' => $payload,
        ]);

        // Process results
        $processed = 0;
        $failed = 0;

        foreach ($results as $result) {
            $attachment_id = $result['attachment_id'] ?? 0;
            if (!$attachment_id) {
                continue;
            }

            // Update postmeta for original file
            if (!empty($result['file_id'])) {
                update_post_meta($attachment_id, '_blitzcdn_file_id', sanitize_text_field($result['file_id']));
            }

            if (!empty($result['cdn_url'])) {
                update_post_meta($attachment_id, '_blitzcdn_cdn_url', esc_url_raw($result['cdn_url']));
            }

            // Update sizes metadata
            if (!empty($result['sizes']) && is_array($result['sizes'])) {
                $blitz_sizes = [];
                foreach ($result['sizes'] as $size_name => $size_data) {
                    $blitz_sizes[$size_name] = [
                        'file_id' => sanitize_text_field($size_data['file_id'] ?? ''),
                        'url' => esc_url_raw($size_data['url'] ?? ''),
                    ];
                }
                update_post_meta($attachment_id, '_blitzcdn_sizes', $blitz_sizes);
            }

            $processed++;
        }

        // Log errors
        foreach ($errors as $error) {
            error_log('BlitzCDN webhook error: ' . wp_json_encode($error));
            $failed++;
        }

        // Update final status
        $this->update_migration_status([
            'status' => 'completed',
            'completed_at' => current_time('timestamp'),
            'processed' => $processed,
            'failed' => $failed,
        ]);

        // Clean up zip file if it exists
        $current_status = $this->get_migration_status();
        if (!empty($current_status['zip_path']) && file_exists($current_status['zip_path'])) {
            @unlink($current_status['zip_path']);
        }

        // Trigger URL rewriting cache clear
        do_action('blitzcdn_migration_completed', $migration_id, $processed, $failed);

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Webhook processed successfully',
            'processed' => $processed,
            'failed' => $failed,
        ], 200);
    }

    /**
     * Get migration status REST endpoint handler.
     * 
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function get_migration_status_rest($request) {
        $status = $this->get_migration_status();
        return new \WP_REST_Response($status, 200);
    }

    /**
     * Update migration status in options.
     * 
     * @param array $data Data to merge into status.
     */
    public function update_migration_status($data) {
        $current = get_option(self::MIGRATION_STATUS_OPTION, []);
        $updated = array_merge($current, $data);
        update_option(self::MIGRATION_STATUS_OPTION, $updated);
    }

    /**
     * Get current migration status.
     * 
     * @return array Migration status data.
     */
    public function get_migration_status() {
        return get_option(self::MIGRATION_STATUS_OPTION, [
            'status' => 'idle',
            'migration_id' => '',
            'total_files' => 0,
            'processed' => 0,
            'failed' => 0,
        ]);
    }

    /**
     * Reset migration status.
     */
    public function reset_migration_status() {
        delete_option(self::MIGRATION_STATUS_OPTION);
    }

    /**
     * Start a zip migration process.
     * 
     * @param int $batch_size Maximum number of attachments to include (0 for all).
     * @return array|WP_Error Result data or error.
     */
    public function start_migration($batch_size = 0) {
        // Reset previous status
        $this->reset_migration_status();

        // Get attachments to migrate
        $attachment_ids = $this->get_unmigrated_attachments($batch_size);

        if (empty($attachment_ids)) {
            return new \WP_Error('no_attachments', 'No attachments found to migrate.');
        }

        // Create zip
        $zip_result = $this->create_migration_zip($attachment_ids);

        if (is_wp_error($zip_result)) {
            return $zip_result;
        }

        // Upload to middleware
        $upload_result = $this->upload_zip_to_middleware($zip_result['path'], $zip_result['migration_id']);

        if (is_wp_error($upload_result)) {
            return $upload_result;
        }

        return [
            'success' => true,
            'migration_id' => $zip_result['migration_id'],
            'attachment_count' => count($attachment_ids),
            'files_added' => $zip_result['files_added'],
            'status' => 'processing',
            'message' => 'Migration started. Waiting for middleware to process files.',
        ];
    }

    /**
     * AJAX handler for starting zip migration.
     */
    public function ajax_start_zip_migration() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $settings = get_option('blitzcdn_settings', []);
        if (empty($settings['account_email'])) {
            wp_send_json_error('Account email not configured.');
        }

        if (empty($settings['middleware_url'])) {
            wp_send_json_error('Middleware URL not configured. Please set it in BlitzCDN settings.');
        }

        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 0;
        
        $result = $this->start_migration($batch_size);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for getting zip migration status.
     */
    public function ajax_get_zip_migration_status() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $status = $this->get_migration_status();
        wp_send_json_success($status);
    }

    /**
     * AJAX handler for getting unmigrated attachments count.
     */
    public function ajax_get_zip_migration_stats() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $attachment_ids = $this->get_unmigrated_attachments();
        $total_assets = Core::count_total_assets($attachment_ids);

        wp_send_json_success([
            'total_attachments' => count($attachment_ids),
            'total_assets' => $total_assets,
        ]);
    }

    /**
     * AJAX handler for resetting migration status.
     */
    public function ajax_reset_zip_migration() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Clean up zip file if it exists
        $status = $this->get_migration_status();
        if (!empty($status['zip_path']) && file_exists($status['zip_path'])) {
            @unlink($status['zip_path']);
        }

        $this->reset_migration_status();
        wp_send_json_success(['message' => 'Migration status reset.']);
    }
}
