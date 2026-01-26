<?php

namespace BlitzCDN;

/**
 * ZipMigrator handles zip-based media migration.
 * 
 * This class packages media files with metadata into a zip archive,
 * uploads to a middleware service for parallel processing, and handles
 * webhook callbacks with results.
 */
class ZipMigrator
{

    /**
     * Option key for migration status tracking.
     */
    const MIGRATION_STATUS_OPTION = 'blitzcdn_zip_migration_status';

    /**
     * REST API namespace.
     */
    const REST_NAMESPACE = 'blitzcdn/v1';

    /**
     * Fixed batch size the middleware processes per callback.
     */
    const MIDDLEWARE_BATCH_SIZE = 10;

    /**
     * Constructor - register hooks.
     */
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register REST API routes for webhook callbacks.
     */
    public function register_rest_routes()
    {
        register_rest_route(self::REST_NAMESPACE, '/migration-webhook', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook_callback'],
            'permission_callback' => [$this, 'verify_webhook_token'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/migration-status', [
            'methods' => 'GET',
            'callback' => [$this, 'get_migration_status_rest'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route(self::REST_NAMESPACE, '/cancel-migration', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_cancel_migration_rest'],
            'permission_callback' => function () {
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
    public function verify_webhook_token($request)
    {
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
     * Get attachment IDs that need migration (not yet uploaded to Appwrite).
     * 
     * @param int $limit Maximum number of IDs to return (0 for all).
     * @return array Array of attachment IDs.
     */
    public function get_unmigrated_attachments($limit = 0)
    {
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
     * Get attachment IDs that haven't been included in any zip during current migration.
     * This excludes attachments that are already queued for processing (in a zip sent to middleware).
     * 
     * @param int $limit Maximum number of IDs to return (0 for all).
     * @return array Array of attachment IDs.
     */
    public function get_unzipped_attachments($limit = 0)
    {
        $current_status = $this->get_migration_status();
        $zipped_ids = $current_status['zipped_attachment_ids'] ?? [];

        // Get unmigrated attachments
        $unmigrated = $this->get_unmigrated_attachments($limit);

        // Exclude those already zipped in this migration session
        if (!empty($zipped_ids)) {
            $unmigrated = array_diff($unmigrated, $zipped_ids);
            $unmigrated = array_values($unmigrated); // Re-index
        }

        return $unmigrated;
    }

    /**
     * Mark attachment IDs as zipped (added to a zip in current migration).
     * 
     * @param array $attachment_ids Array of attachment IDs to mark.
     */
    private function mark_attachments_zipped($attachment_ids)
    {
        $current_status = $this->get_migration_status();
        $zipped_ids = $current_status['zipped_attachment_ids'] ?? [];

        $zipped_ids = array_unique(array_merge($zipped_ids, $attachment_ids));

        $this->update_migration_status([
            'zipped_attachment_ids' => $zipped_ids,
        ]);
    }

    /**
     * Build metadata for a set of attachments.
     * 
     * @param array $attachment_ids Array of attachment IDs.
     * @return array Metadata structure for the zip file.
     */
    public function build_metadata($attachment_ids)
    {
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
    public function create_migration_zip($attachment_ids)
    {
        if (empty($attachment_ids)) {
            return new \WP_Error('no_attachments', 'No attachments provided for migration.');
        }

        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            return new \WP_Error('zip_unavailable', 'ZipArchive extension is not available.');
        }

        // Increase memory limit for large migrations
        $current_limit = ini_get('memory_limit');
        $limit_bytes = $this->parse_memory_limit($current_limit);
        $needed_bytes = 512 * 1024 * 1024; // 512MB

        if ($limit_bytes < $needed_bytes && $limit_bytes !== -1) {
            @ini_set('memory_limit', '512M');
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

        // Update migration status - preserve cumulative processed/failed counts from previous batches
        $total_batches = (int) ceil((count($metadata['attachments']) ?: count($attachment_ids)) / self::MIDDLEWARE_BATCH_SIZE);
        $current_status = $this->get_migration_status();

        $this->update_migration_status([
            'migration_id' => $migration_id,
            'status' => 'zip_created',
            'zip_path' => $zip_path,
            'total_files' => $files_added,
            'files_failed' => $files_failed,
            'attachment_count' => count($attachment_ids),
            'created_at' => current_time('timestamp'),
            'total_batches' => $total_batches,
            'current_batch' => 0,
            // Preserve cumulative counters - don't reset to 0!
            'processed' => intval($current_status['processed'] ?? 0),
            'failed' => intval($current_status['failed'] ?? 0),
            'safe_to_quit' => false,
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
     * Upload zip file to middleware service with retry logic.
     * 
     * @param string $zip_path Path to the zip file.
     * @param string $migration_id Migration ID.
     * @param int $max_retries Maximum number of retry attempts.
     * @return array|WP_Error Response data on success, WP_Error on failure.
     */
    public function upload_zip_to_middleware($zip_path, $migration_id, $max_retries = 3)
    {
        $settings = get_option('blitzcdn_settings', []);
        $middleware_url = $settings['middleware_url'] ?? '';
        $middleware_api_key = $settings['middleware_api_key'] ?? '';

        if (empty($middleware_url)) {
            return new \WP_Error('middleware_not_configured', 'Middleware URL is not configured.');
        }

        if (!file_exists($zip_path)) {
            return new \WP_Error('zip_not_found', 'Zip file not found: ' . $zip_path);
        }

        $last_error = null;

        // Retry loop with exponential backoff
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            error_log("BlitzCDN: Upload attempt {$attempt}/{$max_retries} for migration {$migration_id}");

            $result = $this->attempt_upload($zip_path, $migration_id, $middleware_url, $middleware_api_key);

            if (!is_wp_error($result)) {
                if ($attempt > 1) {
                    error_log("BlitzCDN: Upload succeeded on attempt {$attempt}");
                }
                return $result;
            }

            $last_error = $result;
            $error_code = $result->get_error_code();
            $error_message = $result->get_error_message();

            // Don't retry on certain errors
            if (in_array($error_code, ['middleware_not_configured', 'zip_not_found'])) {
                error_log("BlitzCDN: Non-retryable error: {$error_message}");
                return $result;
            }

            // For 413 (payload too large), don't retry - the zip is too big
            if (strpos($error_message, 'HTTP 413') !== false) {
                error_log("BlitzCDN: Zip too large (HTTP 413), cannot retry");
                return $result;
            }

            // If this isn't the last attempt, wait before retrying
            if ($attempt < $max_retries) {
                $wait_seconds = min(pow(2, $attempt - 1), 30); // Exponential backoff, max 30s
                error_log("BlitzCDN: Upload failed: {$error_message}. Retrying in {$wait_seconds}s...");
                sleep($wait_seconds);
            }
        }

        error_log("BlitzCDN: Upload failed after {$max_retries} attempts");
        return $last_error;
    }

    /**
     * Attempt a single upload to middleware.
     * 
     * @param string $zip_path Path to the zip file.
     * @param string $migration_id Migration ID.
     * @param string $middleware_url Middleware URL.
     * @param string $middleware_api_key Middleware API key.
     * @return array|WP_Error Response data on success, WP_Error on failure.
     */
    private function attempt_upload($zip_path, $migration_id, $middleware_url, $middleware_api_key)
    {

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
            'status' => 'awaiting_confirmation',
            'upload_completed_at' => current_time('timestamp'),
            'middleware_response' => $data,
            'safe_to_quit' => false,
        ]);

        return $data;
    }

    /**
     * Handle webhook callback from middleware.
     * 
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_webhook_callback($request)
    {
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

        $current_status = $this->get_migration_status();
        $processed = isset($current_status['processed']) ? intval($current_status['processed']) : 0;
        $failed = isset($current_status['failed']) ? intval($current_status['failed']) : 0;

        $status_data = [
            'webhook_received_at' => current_time('timestamp'),
            'webhook_payload' => $payload,
        ];

        if ($status === 'received') {
            // Preserve multi-batch tracking info
            $status_data = array_merge($status_data, [
                'status' => 'processing_remote',
                'safe_to_quit' => true,
                'current_batch' => 0,
                'total_batches' => isset($payload['total_batches']) ? intval($payload['total_batches']) : ($current_status['total_batches'] ?? 0),
                'last_message' => $payload['message'] ?? '',
                // Preserve multi-zip batch info so JS knows to continue
                'has_more_batches' => $current_status['all_zips_uploaded'] === false &&
                    ($current_status['attachments_zipped'] ?? 0) < ($current_status['total_attachments_to_migrate'] ?? 0),
            ]);

            $this->update_migration_status($status_data);

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Migration receipt acknowledged.',
            ], 200);
        }

        foreach ($results as $result) {
            $attachment_id = $result['attachment_id'] ?? 0;
            if (!$attachment_id) {
                continue;
            }

            // Only update metadata if we successfully uploaded the original file
            // This prevents partial/corrupted metadata for failed uploads
            if (empty($result['file_id'])) {
                error_log("BlitzCDN: Skipping metadata update for attachment {$attachment_id} - no file_id");
                $failed++;
                continue;
            }

            // Update postmeta for original file
            update_post_meta($attachment_id, '_blitzcdn_file_id', sanitize_text_field($result['file_id']));

            if (!empty($result['cdn_url'])) {
                update_post_meta($attachment_id, '_blitzcdn_cdn_url', esc_url_raw($result['cdn_url']));
            }

            // Update sizes metadata - only include successfully uploaded sizes
            if (!empty($result['sizes']) && is_array($result['sizes'])) {
                $blitz_sizes = [];
                foreach ($result['sizes'] as $size_name => $size_data) {
                    // Only save size if it has a file_id (successful upload)
                    if (!empty($size_data['file_id'])) {
                        $blitz_sizes[$size_name] = [
                            'file_id' => sanitize_text_field($size_data['file_id'] ?? ''),
                            'url' => esc_url_raw($size_data['url'] ?? ''),
                        ];
                    }
                }
                // Only update if we have at least some successful sizes
                if (!empty($blitz_sizes)) {
                    update_post_meta($attachment_id, '_blitzcdn_sizes', $blitz_sizes);
                }
            }

            $processed++;
        }

        // Log errors
        foreach ($errors as $error) {
            error_log('BlitzCDN webhook error: ' . wp_json_encode($error));
            $failed++;
        }

        if ($status === 'batch') {
            $status_data = array_merge($status_data, [
                'status' => 'processing_remote',
                'safe_to_quit' => true,
                'processed' => $processed,
                'failed' => $failed,
                'total_files_uploaded' => isset($payload['total_files_uploaded']) ? intval($payload['total_files_uploaded']) : ($current_status['total_files_uploaded'] ?? 0),
                'current_batch' => isset($payload['batch_number']) ? intval($payload['batch_number']) : ($current_status['current_batch'] ?? 0),
                'total_batches' => isset($payload['total_batches']) ? intval($payload['total_batches']) : ($current_status['total_batches'] ?? 0),
                'last_batch_at' => current_time('timestamp'),
            ]);

            $this->update_migration_status($status_data);

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Batch processed successfully',
                'processed' => $processed,
                'failed' => $failed,
            ], 200);
        }

        $final_status = 'completed';
        if ($status === 'partial') {
            $final_status = 'completed_with_errors';
        } elseif ($status === 'failed') {
            $final_status = 'failed';
        } elseif ($status === 'cancelled') {
            $final_status = 'cancelled';
        }

        $status_data = array_merge($status_data, [
            'status' => $final_status,
            'completed_at' => current_time('timestamp'),
            'processed' => $processed,
            'failed' => $failed,
            'total_files_uploaded' => isset($payload['total_files_uploaded']) ? intval($payload['total_files_uploaded']) : ($current_status['total_files_uploaded'] ?? 0),
            'safe_to_quit' => true,
            'current_batch' => isset($payload['batch_number']) ? intval($payload['batch_number']) : ($current_status['current_batch'] ?? 0),
            'total_batches' => isset($payload['total_batches']) ? intval($payload['total_batches']) : ($current_status['total_batches'] ?? 0),
        ]);

        $this->update_migration_status($status_data);

        // Clean up zip file if it exists
        $latest_status = $this->get_migration_status();
        if (!empty($latest_status['zip_path']) && file_exists($latest_status['zip_path'])) {
            @unlink($latest_status['zip_path']);
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
    public function get_migration_status_rest($request)
    {
        $status = $this->get_migration_status();
        return new \WP_REST_Response($status, 200);
    }

    /**
     * Update migration status in options.
     * 
     * @param array $data Data to merge into status.
     */
    public function update_migration_status($data)
    {
        $current = get_option(self::MIGRATION_STATUS_OPTION, []);
        $updated = array_merge($current, $data);
        update_option(self::MIGRATION_STATUS_OPTION, $updated);
    }

    /**
     * Get current migration status.
     * 
     * @return array Migration status data.
     */
    public function get_migration_status()
    {
        return get_option(self::MIGRATION_STATUS_OPTION, [
            'status' => 'idle',
            'migration_id' => '',
            'total_files' => 0,
            'processed' => 0,
            'failed' => 0,
            'total_batches' => 0,
            'current_batch' => 0,
            'safe_to_quit' => false,
            // Multi-zip batch tracking
            'total_attachments_to_migrate' => 0,
            'total_assets_to_migrate' => 0,
            'attachments_zipped' => 0,
            'current_zip_batch' => 0,
            'total_zip_batches' => 0,
            'all_zips_uploaded' => false,
            'zipped_attachment_ids' => [],
            'has_more_batches' => false,
            'total_files_uploaded' => 0,
        ]);
    }

    /**
     * Reset migration status.
     */
    public function reset_migration_status()
    {
        delete_option(self::MIGRATION_STATUS_OPTION);
    }

    /**
     * Parse memory limit string to bytes.
     * 
     * @param string $limit Memory limit string (e.g., '256M', '1G').
     * @return int Memory limit in bytes, or -1 for unlimited.
     */
    private function parse_memory_limit($limit)
    {
        if ($limit === '-1') {
            return -1;
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $limit = (int) $limit;

        switch ($last) {
            case 'g':
                $limit *= 1024;
            case 'm':
                $limit *= 1024;
            case 'k':
                $limit *= 1024;
        }

        return $limit;
    }

    /**
     * Cap attachments by total file count (originals + sizes).
     * Enforces a hard limit of 10,000 files per zip for stability.
     * 
     * @param array $attachment_ids All attachment IDs to consider.
     * @param int $max_attachments Maximum number of attachments (respects user setting).
     * @return array Capped array of attachment IDs.
     */
    private function cap_attachments_by_file_count($attachment_ids, $max_attachments)
    {
        $file_cap = 10000; // Hard cap on total files (originals + sizes)
        $result_ids = [];
        $total_files = 0;

        foreach ($attachment_ids as $attachment_id) {
            // Stop if we've hit the attachment limit
            if (count($result_ids) >= $max_attachments) {
                break;
            }

            // Count files for this attachment: 1 original + number of sizes
            $wp_metadata = wp_get_attachment_metadata($attachment_id);
            $size_count = !empty($wp_metadata['sizes']) && is_array($wp_metadata['sizes']) ? count($wp_metadata['sizes']) : 0;
            $attachment_file_count = 1 + $size_count; // original + sizes

            // Check if adding this attachment would exceed the file cap
            if ($total_files + $attachment_file_count > $file_cap) {
                error_log("BlitzCDN: File cap reached. Stopping at {$total_files} files with " . count($result_ids) . " attachments.");
                break;
            }

            $result_ids[] = $attachment_id;
            $total_files += $attachment_file_count;
        }

        return $result_ids;
    }

    /**
     * Start or continue a zip migration process.
     * This creates and uploads ONE zip batch at a time. The JavaScript frontend
     * should call this repeatedly (via AJAX) until all attachments are uploaded.
     * 
     * @param bool $is_continuation Whether this is continuing an existing migration.
     * @param int $limit_attachments Maximum number of attachments to migrate (0 for all). Used for testing.
     * @return array|WP_Error Result data or error.
     */
    public function start_or_continue_migration($is_continuation = false, $limit_attachments = 0)
    {
        $settings = get_option('blitzcdn_settings', []);
        $zip_batch_size = isset($settings['zip_batch_size']) ? intval($settings['zip_batch_size']) : 100;

        // Ensure batch size is within reasonable bounds
        $zip_batch_size = max(1, min(10000, $zip_batch_size));

        // For continuation, use get_unzipped_attachments to exclude those already in a zip
        // For fresh start, use get_unmigrated_attachments (which is the same if we reset status)
        $attachments_to_process = $is_continuation
            ? $this->get_unzipped_attachments($limit_attachments)
            : $this->get_unmigrated_attachments($limit_attachments);

        $total_to_process = count($attachments_to_process);

        if (empty($attachments_to_process)) {
            // All done!
            $this->update_migration_status([
                'status' => 'all_zips_uploaded',
                'all_zips_uploaded' => true,
                'message' => 'All attachments have been uploaded to middleware for processing.',
            ]);
            return [
                'success' => true,
                'all_zips_uploaded' => true,
                'status' => 'all_zips_uploaded',
                'message' => 'All attachments have been uploaded to middleware for processing.',
            ];
        }

        // Get current status for multi-zip tracking
        $current_status = $this->get_migration_status();
        $current_zip_batch = $is_continuation ? (($current_status['current_zip_batch'] ?? 0) + 1) : 1;
        $attachments_zipped_so_far = $current_status['attachments_zipped'] ?? 0;

        // For fresh start, get total unmigrated for accurate tracking
        $total_unmigrated = $is_continuation
            ? ($current_status['total_attachments_to_migrate'] ?? $total_to_process + $attachments_zipped_so_far)
            : $total_to_process;

        // Calculate total zip batches needed (estimate based on zip_batch_size)
        // This is an estimate since actual batch may be smaller due to file cap
        $total_zip_batches = (int) ceil($total_to_process / $zip_batch_size) + ($current_zip_batch - 1);

        // For fresh start, lock total_assets_to_migrate and initialize counters
        $total_assets_to_migrate = $current_status['total_assets_to_migrate'] ?? 0;
        if (!$is_continuation) {
            // First batch - calculate and lock total assets for stable progress denominator
            $total_assets_to_migrate = Core::count_total_assets($attachments_to_process);
            $attachments_zipped_so_far = 0;
        }

        // Calculate how many attachments to include in THIS zip (respecting 10,000 file cap)
        $capped_ids = $this->cap_attachments_by_file_count($attachments_to_process, $zip_batch_size);

        if (empty($capped_ids)) {
            return new \WP_Error('no_attachments', 'No valid attachments to migrate after file cap check.');
        }

        // Create zip for this batch
        $zip_result = $this->create_migration_zip($capped_ids);

        if (is_wp_error($zip_result)) {
            return $zip_result;
        }

        // Mark these attachments as zipped so they won't be included in subsequent batches
        $this->mark_attachments_zipped($capped_ids);

        // Update multi-zip tracking BEFORE upload
        $attachments_in_this_zip = isset($zip_result['metadata']['attachments'])
            ? count($zip_result['metadata']['attachments'])
            : count($capped_ids);

        // Track cumulative files uploaded (add this batch's files to running total)
        $current_files_uploaded = intval($current_status['total_files_uploaded'] ?? 0);
        $files_in_this_batch = $zip_result['files_added'] ?? 0;

        $this->update_migration_status([
            'total_attachments_to_migrate' => $total_unmigrated,
            'total_assets_to_migrate' => $total_assets_to_migrate,
            'attachments_zipped' => $attachments_zipped_so_far + $attachments_in_this_zip,
            'current_zip_batch' => $current_zip_batch,
            'total_zip_batches' => max($total_zip_batches, $current_zip_batch),
            'all_zips_uploaded' => false,
            'total_files_uploaded' => $current_files_uploaded + $files_in_this_batch,
        ]);

        // Upload to middleware
        $upload_result = $this->upload_zip_to_middleware($zip_result['path'], $zip_result['migration_id']);

        if (is_wp_error($upload_result)) {
            return $upload_result;
        }

        $total_batches = isset($zip_result['metadata']['attachments'])
            ? (int) ceil(count($zip_result['metadata']['attachments']) / self::MIDDLEWARE_BATCH_SIZE)
            : (int) ceil(count($capped_ids) / self::MIDDLEWARE_BATCH_SIZE);

        // Check if there are more attachments to process after this batch
        $remaining_after_this = $total_to_process - $attachments_in_this_zip;
        $has_more_batches = $remaining_after_this > 0;

        return [
            'success' => true,
            'migration_id' => $zip_result['migration_id'],
            'attachment_count' => $attachments_in_this_zip,
            'files_added' => $zip_result['files_added'],
            'status' => 'awaiting_confirmation',
            'safe_to_quit' => false,
            'total_batches' => $total_batches,
            // Multi-zip batch info
            'current_zip_batch' => $current_zip_batch,
            'total_zip_batches' => max($total_zip_batches, $current_zip_batch),
            'total_attachments_to_migrate' => $total_unmigrated,
            'total_assets_to_migrate' => $total_assets_to_migrate,
            'attachments_zipped' => $attachments_zipped_so_far + $attachments_in_this_zip,
            'total_files_uploaded' => $current_files_uploaded + $files_in_this_batch,
            'remaining_attachments' => $remaining_after_this,
            'has_more_batches' => $has_more_batches,
            'message' => $has_more_batches
                ? "Zip batch {$current_zip_batch} sent to middleware. {$remaining_after_this} attachments remaining. Wait for confirmation then continue."
                : 'Final zip batch sent to middleware. Wait for confirmation before closing this page.',
        ];
    }

    /**
     * Start a zip migration process (legacy method for backward compatibility).
     * 
     * @param int $limit_attachments Maximum number of attachments to migrate (0 for all). Used for testing.
     * @return array|WP_Error Result data or error.
     */
    public function start_migration($limit_attachments = 0)
    {
        // Reset previous status for fresh start
        $this->reset_migration_status();

        return $this->start_or_continue_migration(false, $limit_attachments);
    }

    /**
     * Continue a zip migration process (sends next batch).
     * 
     * @return array|WP_Error Result data or error.
     */
    public function continue_migration()
    {
        return $this->start_or_continue_migration(true, 0);
    }

    /**
     * AJAX handler for starting zip migration.
     */
    public function ajax_start_zip_migration()
    {
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

        // For testing, allow limiting total attachments processed (0 = use setting batch size)
        $limit_attachments = isset($_POST['limit_attachments']) ? intval($_POST['limit_attachments']) : 0;

        $result = $this->start_migration($limit_attachments);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for continuing zip migration (next batch).
     */
    public function ajax_continue_zip_migration()
    {
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

        $result = $this->continue_migration();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for getting zip migration status.
     */
    public function ajax_get_zip_migration_status()
    {
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
    public function ajax_get_zip_migration_stats()
    {
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
    public function ajax_reset_zip_migration()
    {
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

    /**
     * Cancel migration by notifying middleware.
     * 
     * @param string $migration_id The migration ID to cancel.
     * @return array|WP_Error Result data or error.
     */
    public function cancel_migration($migration_id = '')
    {
        $current_status = $this->get_migration_status();

        // Use current migration ID if not provided
        if (empty($migration_id)) {
            $migration_id = $current_status['migration_id'] ?? '';
        }

        if (empty($migration_id)) {
            return new \WP_Error('no_migration', 'No active migration to cancel.');
        }

        $settings = get_option('blitzcdn_settings', []);
        $middleware_url = $settings['middleware_url'] ?? '';
        $middleware_api_key = $settings['middleware_api_key'] ?? '';

        if (empty($middleware_url)) {
            // Just cancel locally
            $this->update_migration_status([
                'status' => 'cancelled',
                'cancelled_at' => current_time('timestamp'),
                'message' => 'Migration cancelled locally (no middleware configured).',
            ]);
            return [
                'success' => true,
                'message' => 'Migration cancelled locally.',
            ];
        }

        // Notify middleware to cancel
        $endpoint = rtrim($middleware_url, '/') . '/api/cancel';

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (!empty($middleware_api_key)) {
            $headers['Authorization'] = 'Bearer ' . $middleware_api_key;
        }

        $response = wp_remote_post($endpoint, [
            'headers' => $headers,
            'body' => wp_json_encode(['migration_id' => $migration_id]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            // Still cancel locally even if middleware call fails
            $this->update_migration_status([
                'status' => 'cancelled',
                'cancelled_at' => current_time('timestamp'),
                'message' => 'Migration cancelled locally. Middleware notification failed: ' . $response->get_error_message(),
            ]);
            return [
                'success' => true,
                'message' => 'Migration cancelled locally. Middleware notification failed.',
                'middleware_error' => $response->get_error_message(),
            ];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        // Update local status
        $this->update_migration_status([
            'status' => 'cancelled',
            'cancelled_at' => current_time('timestamp'),
            'message' => 'Migration cancelled.',
            'middleware_response' => $data,
        ]);

        // Clean up zip file if it exists
        if (!empty($current_status['zip_path']) && file_exists($current_status['zip_path'])) {
            @unlink($current_status['zip_path']);
        }

        return [
            'success' => true,
            'message' => 'Migration cancelled successfully.',
            'middleware_response' => $data,
        ];
    }

    /**
     * Handle cancel migration REST endpoint.
     * 
     * @param \WP_REST_Request $request The REST request.
     * @return \WP_REST_Response The REST response.
     */
    public function handle_cancel_migration_rest($request)
    {
        $params = $request->get_json_params();
        $migration_id = $params['migration_id'] ?? '';

        $result = $this->cancel_migration($migration_id);

        if (is_wp_error($result)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400);
        }

        return new \WP_REST_Response($result, 200);
    }

    /**
     * AJAX handler for cancelling migration.
     */
    public function ajax_cancel_zip_migration()
    {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $migration_id = isset($_POST['migration_id']) ? sanitize_text_field($_POST['migration_id']) : '';

        $result = $this->cancel_migration($migration_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }
}
