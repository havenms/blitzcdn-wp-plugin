<?php

namespace BlitzCDN;

class UploadHandler {

    private $appwrite_client;
    private static $uploaded_files = []; // Cache for Phase 1 uploads: path => file_id

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
    }

    /**
     * Phase 1: Upload original file immediately after it's placed in the uploads directory.
     */
    public function handle_upload_phase_1($upload) {
        if (!$this->appwrite_client->is_configured()) {
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
        if (!$this->appwrite_client->is_configured()) {
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

        // Safe Delete Logic
        $settings = get_option('blitzcdn_settings', []);
        $safe_delete = $settings['safe_delete'] ?? false;

        if ($safe_delete && $all_uploads_successful) {
            $this->safe_delete_local_files($attachment_id, $metadata);
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

    private function safe_delete_local_files($attachment_id, $metadata) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        $subdir = dirname($file);

        // Delete original
        $original_path = path_join($base_dir, $file);
        if (file_exists($original_path)) {
            unlink($original_path);
        }

        // Delete sizes
        if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_info) {
                $file_name = $size_info['file'];
                $file_path = path_join($base_dir, path_join($subdir, $file_name));
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }
        }
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
