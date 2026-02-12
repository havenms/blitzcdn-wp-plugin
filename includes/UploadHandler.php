<?php

namespace BlitzCDN;

class UploadHandler {

    private $appwrite_client;
    private static $uploaded_files = []; // Cache for Phase 1 uploads: path => file_id
    private static $pending_deletions = []; // Track files pending deletion: attachment_id => metadata

    public function __construct(AppwriteClient $client) {
        $this->appwrite_client = $client;

        // Phase 1: Original File Upload
        add_filter('wp_handle_upload', [$this, 'handle_upload_phase_1']);

        // Save metadata for original file once attachment is created
        add_action('add_attachment', [$this, 'save_original_attachment_metadata']);

        // Phase 2: Intermediate Image Sizes
        add_filter('wp_generate_attachment_metadata', [$this, 'handle_upload_phase_2'], 10, 2);

        // Handle Deletion
        add_action('delete_attachment', [$this, 'handle_delete_attachment']);

        // Deferred safe delete - runs after WordPress finishes processing the request
        add_action('shutdown', [$this, 'process_pending_deletions'], 999);
    }

    /**
     * Phase 1: Upload original file immediately after it's placed in the uploads directory.
     */
    public function handle_upload_phase_1($upload) {
        // Skip if EmailRedownloader is running to prevent duplicate uploads
        if (class_exists('\BlitzCDN\EmailRedownloader') && \BlitzCDN\EmailRedownloader::is_redownloading()) {
            return $upload;
        }

        if (!$this->appwrite_client->is_configured()) {
            return $upload;
        }

        $settings = get_option('blitzcdn_settings', []);
        $account_email = $settings['account_email'] ?? '';

        if (empty($account_email)) {
            return $upload;
        }

        if (isset($upload['file']) && !isset($upload['error'])) {
            $file_path = $upload['file'];
            $file_name = basename($file_path);

            // Upload to Appwrite
            $file_id = $this->appwrite_client->upload_file($file_path, $file_name);

            if ($file_id) {
                // Cache the result for the next step (add_attachment)
                self::$uploaded_files[$file_path] = $file_id;

                // Track upload in Database
                $this->appwrite_client->create_document([
                    'email' => $account_email,
                    'fileId' => $file_id,
                    'originalUrl' => $upload['url'] ?? '',
                    'createdAt' => date('c')
                ]);
            } else {
                // Log failure, but do not stop WordPress
                error_log("BlitzCDN: Failed to upload original file: $file_name");
            }
        }

        return $upload;
    }

    /**
     * Save the Appwrite File ID for the original file to postmeta.
     */
    public function save_original_attachment_metadata($post_id) {
        if (!$this->appwrite_client->is_configured()) {
            return;
        }

        $file_path = get_attached_file($post_id);
        
        // Check if we uploaded this file in Phase 1
        if (isset(self::$uploaded_files[$file_path])) {
            $file_id = self::$uploaded_files[$file_path];
            $cdn_url = $this->get_cdn_url($file_id);

            update_post_meta($post_id, '_blitzcdn_file_id', $file_id);
            update_post_meta($post_id, '_blitzcdn_cdn_url', $cdn_url);
            
            // Clean up cache
            unset(self::$uploaded_files[$file_path]);
        } else {
            // Fallback: If for some reason wp_handle_upload didn't catch it or it's an import
            // We could try uploading here, but let's stick to the requested flow.
            // Actually, for robustness, let's try to upload if missing?
            // The user said "Phase 1 ... Handled via wp_handle_upload".
            // But for migration or other flows, we might need a direct upload method.
            // Let's leave it for now to strictly follow the phases.
        }
    }

    /**
     * Phase 2: Upload generated sizes.
     */
    public function handle_upload_phase_2($metadata, $attachment_id) {
        // Skip if EmailRedownloader is running to prevent duplicate uploads
        if (class_exists('\BlitzCDN\EmailRedownloader') && \BlitzCDN\EmailRedownloader::is_redownloading()) {
            return $metadata;
        }

        if (!$this->appwrite_client->is_configured()) {
            return $metadata;
        }

        $settings = get_option('blitzcdn_settings', []);
        $account_email = $settings['account_email'] ?? '';

        if (empty($account_email)) {
            return $metadata;
        }

        // Ensure original file metadata exists (in case Phase 1 missed it or it's a regeneration)
        $original_file_id = get_post_meta($attachment_id, '_blitzcdn_file_id', true);
        if (!$original_file_id) {
            // Try to upload original if missing
            $file_path = get_attached_file($attachment_id);
            if (file_exists($file_path)) {
                $file_name = basename($file_path);
                $file_id = $this->appwrite_client->upload_file($file_path, $file_name);
                if ($file_id) {
                    $cdn_url = $this->get_cdn_url($file_id);
                    update_post_meta($attachment_id, '_blitzcdn_file_id', $file_id);
                    update_post_meta($attachment_id, '_blitzcdn_cdn_url', $cdn_url);
                    $original_file_id = $file_id;

                    // Track upload in Database
                    $this->appwrite_client->create_document([
                        'email' => $account_email,
                        'fileId' => $file_id,
                        'originalUrl' => wp_get_attachment_url($attachment_id),
                        'createdAt' => date('c')
                    ]);
                }
            }
        }

        $blitz_sizes = [];
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        // Get the relative path to the directory containing the images
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $subdir = dirname($file);
        
        $all_uploads_successful = true;
        if (!$original_file_id) {
            $all_uploads_successful = false;
        }

        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size_info) {
                $file_name = $size_info['file'];
                $file_path = path_join($base_dir, path_join($subdir, $file_name));

                if (file_exists($file_path)) {
                    $file_id = $this->appwrite_client->upload_file($file_path, $file_name);
                    
                    if ($file_id) {
                        $cdn_url = $this->get_cdn_url($file_id);
                        $blitz_sizes[$size_name] = [
                            'file_id' => $file_id,
                            'url' => $cdn_url
                        ];

                        // Track upload in Database
                        $this->appwrite_client->create_document([
                            'email' => $account_email,
                            'fileId' => $file_id,
                            'originalUrl' => '', // Hard to get exact URL for size here without constructing it
                            'createdAt' => date('c')
                        ]);
                    } else {
                        $all_uploads_successful = false;
                        error_log("BlitzCDN: Failed to upload size: $size_name for attachment $attachment_id");
                    }
                } else {
                    // File doesn't exist locally?
                    $all_uploads_successful = false;
                }
            }
        }

        // Save sizes metadata
        if (!empty($blitz_sizes)) {
            update_post_meta($attachment_id, '_blitzcdn_sizes', $blitz_sizes);
        }

        // Rewrite URLs in post content if all uploads succeeded
        if ($all_uploads_successful) {
            // Get CDN URLs that were just stored
            $cdn_url = get_post_meta($attachment_id, '_blitzcdn_cdn_url', true);
            
            // Build URL mapping: Local URL => CDN URL (inverse of Redownloader)
            $url_replacements = [];
            
            // Construct local URL
            $upload_dir = wp_upload_dir();
            $base_url = trailingslashit($upload_dir['baseurl']);
            // _wp_attached_file is relative to uploads directory, e.g., "2024/01/image.jpg"
            $local_url = $base_url . ltrim($file, '/');
            
            // Original file URL replacement
            if ($cdn_url && $local_url && $cdn_url !== $local_url) {
                $url_replacements[$local_url] = $cdn_url;
            }
            
            // Size URLs replacement
            if (is_array($blitz_sizes) && !empty($blitz_sizes)) {
                foreach ($blitz_sizes as $size_name => $size_info) {
                    if (!empty($size_info['url']) && isset($metadata['sizes'][$size_name]['file'])) {
                        $size_filename = $metadata['sizes'][$size_name]['file'];
                        // Construct local size URL: baseurl/subdir/filename
                        $size_path = ($subdir === '.' ? '' : $subdir . '/') . $size_filename;
                        $local_size_url = $base_url . ltrim($size_path, '/');
                        $cdn_size_url = $size_info['url'];
                        
                        if ($local_size_url && $cdn_size_url !== $local_size_url) {
                            $url_replacements[$local_size_url] = $cdn_size_url;
                        }
                    }
                }
            }
            
            // Perform URL rewriting in all post content
            if (!empty($url_replacements)) {
                $this->rewrite_urls_in_content($url_replacements);
            }
        }

        // Safe Delete Logic - Defer to shutdown hook to ensure WordPress has finished processing
        $settings = get_option('blitzcdn_settings', []);
        $safe_delete = $settings['safe_delete'] ?? false;

        if ($safe_delete && $all_uploads_successful) {
            // Store deletion request to process after WordPress finishes
            self::$pending_deletions[$attachment_id] = $metadata;
        }

        do_action('blitzcdn_upload_complete', $attachment_id);

        return $metadata;
    }

    private function get_cdn_url($file_id) {
        $cdn_domain = rtrim($this->appwrite_client->get_cdn_domain(), '/');
        $bucket_id = $this->appwrite_client->get_bucket_id();
        $project_id = $this->appwrite_client->get_project_id();
        
        // If CDN domain is set, use it.
        // Format: https://files.blitzcdn.net/bucket_id/file_id
        // Or maybe the user maps the CDN to the Appwrite endpoint?
        // The user said: "Serve them through a CDN domain such as files.blitzcdn.net"
        // And "Store Appwrite file ID + CDN URL".
        
        // If the user provides a CDN domain, we assume it proxies to Appwrite or is mapped.
        // A common pattern for Appwrite behind CDN is: https://cdn.example.com/v1/storage/buckets/{bucket}/files/{file}/view?project={project}
        // OR if they are using Appwrite's custom domain feature on the bucket?
        // Let's assume a standard structure or just append the path.
        // If the user just gives "files.blitzcdn.net", we might need to know the path structure.
        // Let's assume: https://{cdn_domain}/v1/storage/buckets/{bucket_id}/files/{file_id}/view?project={project_id}&mode=admin
        // Wait, public files don't need mode=admin if permissions are right.
        
        // Let's construct the standard Appwrite view URL but replace the endpoint host with the CDN domain.
        
        $endpoint = $this->appwrite_client->get_endpoint();
        $parsed_endpoint = parse_url($endpoint);
        $endpoint_path = $parsed_endpoint['path'] ?? '/v1';
        
        if ($cdn_domain) {
            // Ensure protocol
            if (!preg_match("~^(?:f|ht)tps?://~i", $cdn_domain)) {
                $cdn_domain = "https://" . $cdn_domain;
            }
            $base_url = $cdn_domain;
        } else {
            $base_url = $endpoint; // Fallback to direct Appwrite URL
        }

        // Construct path
        // /storage/buckets/{bucketId}/files/{fileId}/view
        $url = "{$base_url}/storage/buckets/{$bucket_id}/files/{$file_id}/view?project={$project_id}";
        
        return $url;
    }

    /**
     * Process pending file deletions on shutdown hook.
     * This ensures WordPress has completely finished processing attachments before deletion.
     * 
     * Fetches current metadata from WordPress to include any files generated after Phase 2.
     */
    public function process_pending_deletions() {
        if (empty(self::$pending_deletions)) {
            return;
        }

        foreach (self::$pending_deletions as $attachment_id => $stored_metadata) {
            // Fetch current metadata from WordPress to get any files generated after Phase 2
            $current_metadata = wp_get_attachment_metadata($attachment_id);
            
            // Fallback to stored metadata if current metadata is unavailable
            if (empty($current_metadata) || !is_array($current_metadata)) {
                $current_metadata = $stored_metadata;
            }
            
            $this->safe_delete_local_files($attachment_id, $current_metadata);
        }

        // Clear pending deletions after processing
        self::$pending_deletions = [];
    }

    /**
     * Safely delete local files after successful upload to CDN.
     * 
     * @param int $attachment_id WordPress attachment ID
     * @param array $metadata Attachment metadata from WordPress
     */
    private function safe_delete_local_files($attachment_id, $metadata) {
        $settings = get_option('blitzcdn_settings', []);
        $safe_delete = $settings['safe_delete'] ?? false;

        // Double-check safe delete is still enabled (in case settings changed)
        if (!$safe_delete) {
            return;
        }

        // Verify all uploads succeeded by checking metadata exists
        $file_id = get_post_meta($attachment_id, '_blitzcdn_file_id', true);
        $sizes_meta = get_post_meta($attachment_id, '_blitzcdn_sizes', true);

        if (!$file_id) {
            error_log("BlitzCDN: Safe delete skipped for attachment $attachment_id - no CDN file ID found");
            return;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);

        if (empty($file)) {
            error_log("BlitzCDN: Safe delete skipped for attachment $attachment_id - no attached file path found");
            return;
        }

        $subdir = dirname($file);
        $deleted_count = 0;
        $errors = [];

        // Delete original file
        $original_path = path_join($base_dir, $file);
        if (file_exists($original_path)) {
            if (@unlink($original_path)) {
                $deleted_count++;
            } else {
                $errors[] = "Failed to delete original: $original_path";
            }
        }

        // Delete size files - only delete sizes that were successfully uploaded to CDN
        // This prevents deleting files that were generated but not uploaded
        if (isset($metadata['sizes']) && is_array($metadata['sizes']) && is_array($sizes_meta)) {
            // Build a set of size names that were successfully uploaded to CDN
            $uploaded_size_names = array_keys($sizes_meta);
            
            foreach ($metadata['sizes'] as $size_name => $size_info) {
                // Only delete if this size was successfully uploaded to CDN
                if (!in_array($size_name, $uploaded_size_names, true)) {
                    continue;
                }
                
                if (!isset($size_info['file'])) {
                    continue;
                }

                $file_name = $size_info['file'];
                $file_path = path_join($base_dir, path_join($subdir, $file_name));

                if (file_exists($file_path)) {
                    if (@unlink($file_path)) {
                        $deleted_count++;
                    } else {
                        $errors[] = "Failed to delete size: $file_path";
                    }
                }
            }
        }

        // Log results
        if (!empty($errors)) {
            error_log("BlitzCDN: Safe delete completed for attachment $attachment_id with errors: " . implode(', ', $errors));
        } elseif ($deleted_count > 0) {
            error_log("BlitzCDN: Safe delete completed for attachment $attachment_id - deleted $deleted_count file(s)");
        }
    }

    /**
     * Rewrite local URLs to CDN URLs in all post content across the site.
     * This is the inverse of Redownloader::rewrite_urls_in_content().
     * Similar to the original migration functionality.
     *
     * @param array $url_replacements Array mapping local URLs to CDN URLs [local_url => cdn_url]
     * @return array {
     *     @type int $posts_updated Number of posts updated
     *     @type int $replacements_made Total number of URL replacements made
     * }
     */
    private function rewrite_urls_in_content($url_replacements) {
        global $wpdb;
        
        $posts_updated = 0;
        $replacements_made = 0;
        
        if (empty($url_replacements)) {
            return [
                'posts_updated' => 0,
                'replacements_made' => 0
            ];
        }
        
        // Get all post types that can contain content (posts, pages, custom post types)
        $post_types = get_post_types(['public' => true], 'names');
        $post_types[] = 'attachment'; // Also check attachment descriptions
        $post_types_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        
        // Process each URL replacement
        foreach ($url_replacements as $local_url => $cdn_url) {
            // We need to handle both http and https, and URL-encoded versions
            $local_url_http = str_replace('https://', 'http://', $local_url);
            $local_url_https = str_replace('http://', 'https://', $local_url);
            $cdn_url_http = str_replace('https://', 'http://', $cdn_url);
            $cdn_url_https = str_replace('http://', 'https://', $cdn_url);
            
            // Also handle URL-encoded versions
            $local_url_encoded = esc_url_raw($local_url);
            $cdn_url_encoded = esc_url_raw($cdn_url);
            
            // Find all posts containing the local URL
            // Build the query with proper placeholders
            $query = "SELECT ID, post_content FROM {$wpdb->posts} 
                WHERE post_type IN ({$post_types_placeholders}) 
                AND post_content LIKE %s";
            
            $query_params = array_merge($post_types, ['%' . $wpdb->esc_like($local_url) . '%']);
            $posts = $wpdb->get_results($wpdb->prepare($query, $query_params));
            
            foreach ($posts as $post) {
                $original_content = $post->post_content;
                $updated_content = $original_content;
                $post_replacements = 0;
                
                // Replace https version (handle both plain and JSON-encoded)
                if (strpos($updated_content, $local_url_https) !== false) {
                    $updated_content = str_replace($local_url_https, $cdn_url_https, $updated_content);
                    $post_replacements += substr_count($original_content, $local_url_https);
                    // Also handle JSON-encoded URLs (Gutenberg blocks)
                    $local_url_https_json = addslashes($local_url_https);
                    $cdn_url_https_json = addslashes($cdn_url_https);
                    if (strpos($updated_content, $local_url_https_json) !== false) {
                        $updated_content = str_replace($local_url_https_json, $cdn_url_https_json, $updated_content);
                    }
                }
                
                // Replace http version (handle both plain and JSON-encoded)
                if (strpos($updated_content, $local_url_http) !== false) {
                    $updated_content = str_replace($local_url_http, $cdn_url_http, $updated_content);
                    $post_replacements += substr_count($original_content, $local_url_http);
                    // Also handle JSON-encoded URLs
                    $local_url_http_json = addslashes($local_url_http);
                    $cdn_url_http_json = addslashes($cdn_url_http);
                    if (strpos($updated_content, $local_url_http_json) !== false) {
                        $updated_content = str_replace($local_url_http_json, $cdn_url_http_json, $updated_content);
                    }
                }
                
                // Replace URL-encoded versions if different
                if ($local_url_encoded !== $local_url_https && strpos($updated_content, $local_url_encoded) !== false) {
                    $updated_content = str_replace($local_url_encoded, $cdn_url_encoded, $updated_content);
                    $post_replacements += substr_count($original_content, $local_url_encoded);
                }
                
                // Only update if content changed
                if ($updated_content !== $original_content) {
                    $wpdb->update(
                        $wpdb->posts,
                        ['post_content' => $updated_content],
                        ['ID' => $post->ID],
                        ['%s'],
                        ['%d']
                    );
                    
                    // Clear post cache
                    clean_post_cache($post->ID);
                    
                    $posts_updated++;
                    $replacements_made += $post_replacements;
                }
            }
            
            // Also check postmeta for URLs (some plugins store URLs in meta)
            $meta_results = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} 
                WHERE meta_value LIKE %s",
                '%' . $wpdb->esc_like($local_url) . '%'
            ));
            
            foreach ($meta_results as $meta) {
                $original_value = $meta->meta_value;
                $updated_value = $original_value;
                
                // Replace https version
                if (strpos($updated_value, $local_url_https) !== false) {
                    $updated_value = str_replace($local_url_https, $cdn_url_https, $updated_value);
                }
                
                // Replace http version
                if (strpos($updated_value, $local_url_http) !== false) {
                    $updated_value = str_replace($local_url_http, $cdn_url_http, $updated_value);
                }
                
                // Replace URL-encoded versions if different
                if ($local_url_encoded !== $local_url_https && strpos($updated_value, $local_url_encoded) !== false) {
                    $updated_value = str_replace($local_url_encoded, $cdn_url_encoded, $updated_value);
                }
                
                // Only update if value changed
                if ($updated_value !== $original_value) {
                    update_post_meta($meta->post_id, $meta->meta_key, $updated_value);
                }
            }
        }
        
        return [
            'posts_updated' => $posts_updated,
            'replacements_made' => $replacements_made
        ];
    }

    /**
     * Handle attachment deletion.
     * Deletes files from Appwrite if the option is enabled.
     */
    public function handle_delete_attachment($post_id) {
        $settings = get_option('blitzcdn_settings', []);
        $delete_remote = $settings['delete_remote'] ?? false;

        if (!$delete_remote || !$this->appwrite_client->is_configured()) {
            return;
        }

        // Delete original file
        $file_id = get_post_meta($post_id, '_blitzcdn_file_id', true);
        if ($file_id) {
            $this->appwrite_client->delete_file($file_id);
        }

        // Delete sizes
        $sizes_meta = get_post_meta($post_id, '_blitzcdn_sizes', true);
        if (is_array($sizes_meta)) {
            foreach ($sizes_meta as $size_info) {
                if (isset($size_info['file_id'])) {
                    $this->appwrite_client->delete_file($size_info['file_id']);
                }
            }
        }
    }
}