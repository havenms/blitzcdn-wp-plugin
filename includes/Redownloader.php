<?php

namespace BlitzCDN;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the "Goodbye" procedure - redownloading media from Appwrite back to WordPress.
 * 
 * This class provides browser-based batch processing to:
 * 1. Download files from Appwrite Storage back to wp-content/uploads
 * 2. Preserve original file paths and names
 * 3. Update WordPress metadata to point to local files
 * 4. Optionally delete files from Appwrite after successful redownload
 */
class Redownloader {

    private $appwrite_client;

    public function __construct(AppwriteClient $client) {
        $this->appwrite_client = $client;
    }

    /**
     * Get statistics about attachments that can be redownloaded.
     * These are attachments that have BlitzCDN metadata (uploaded to Appwrite).
     *
     * @return array {
     *     @type int   $total Total number of attachments with BlitzCDN metadata
     *     @type int[] $ids   Array of attachment IDs
     * }
     */
    public function get_redownload_stats() {
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_blitzcdn_file_id',
                    'compare' => 'EXISTS'
                ]
            ]
        ]);

        return [
            'total' => $query->found_posts,
            'ids' => $query->posts
        ];
    }

    /**
     * Redownload a single attachment from Appwrite.
     *
     * @param int  $attachment_id The attachment post ID
     * @param bool $delete_from_appwrite Whether to delete from Appwrite after successful redownload
     * @return array {
     *     @type string $status  'success' or 'error'
     *     @type string $message Status message
     *     @type array  $details Additional details about what was processed
     * }
     */
    public function redownload_attachment($attachment_id, $delete_from_appwrite = false) {
        $result = [
            'status' => 'success',
            'message' => '',
            'details' => [
                'original' => null,
                'sizes' => [],
                'deleted_from_appwrite' => false
            ]
        ];

        // Verify attachment exists
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return [
                'status' => 'error',
                'message' => 'Attachment not found',
                'details' => $result['details']
            ];
        }

        // Get BlitzCDN metadata
        $original_file_id = get_post_meta($attachment_id, '_blitzcdn_file_id', true);
        $sizes_meta = get_post_meta($attachment_id, '_blitzcdn_sizes', true);

        if (empty($original_file_id)) {
            return [
                'status' => 'error',
                'message' => 'No BlitzCDN metadata found',
                'details' => $result['details']
            ];
        }

        // Get WordPress attachment metadata
        $wp_metadata = wp_get_attachment_metadata($attachment_id);
        $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);

        if (empty($attached_file)) {
            return [
                'status' => 'error',
                'message' => 'No attached file path found in WordPress metadata',
                'details' => $result['details']
            ];
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        // Track all file IDs for potential deletion
        $file_ids_to_delete = [];
        $all_successful = true;

        // 1. Redownload original file
        $original_path = path_join($base_dir, $attached_file);
        $original_result = $this->download_and_save_file($original_file_id, $original_path, $attachment_id);
        $result['details']['original'] = $original_result;

        if ($original_result['status'] === 'success') {
            $file_ids_to_delete[] = $original_file_id;
        } else {
            $all_successful = false;
        }

        // 2. Redownload intermediate sizes
        if (is_array($sizes_meta) && !empty($sizes_meta)) {
            $subdir = dirname($attached_file);

            foreach ($sizes_meta as $size_name => $size_info) {
                if (empty($size_info['file_id'])) {
                    $result['details']['sizes'][$size_name] = [
                        'status' => 'skipped',
                        'message' => 'No file ID found'
                    ];
                    continue;
                }

                // Get the filename for this size from WordPress metadata
                $size_filename = null;
                if (isset($wp_metadata['sizes'][$size_name]['file'])) {
                    $size_filename = $wp_metadata['sizes'][$size_name]['file'];
                }

                if (empty($size_filename)) {
                    $result['details']['sizes'][$size_name] = [
                        'status' => 'skipped',
                        'message' => 'No filename in WordPress metadata'
                    ];
                    continue;
                }

                $size_path = path_join($base_dir, path_join($subdir, $size_filename));
                $size_result = $this->download_and_save_file($size_info['file_id'], $size_path, $attachment_id);
                $result['details']['sizes'][$size_name] = $size_result;

                if ($size_result['status'] === 'success') {
                    $file_ids_to_delete[] = $size_info['file_id'];
                } else {
                    $all_successful = false;
                }
            }
        }

        // 3. Rewrite URLs in post content if all downloads succeeded
        if ($all_successful) {
            // Get CDN URLs before clearing metadata
            $cdn_url = get_post_meta($attachment_id, '_blitzcdn_cdn_url', true);
            
            // Build URL mapping: CDN URL => Local URL
            $url_replacements = [];
            
            // Construct local URL manually to avoid CDN filter interference
            $upload_dir = wp_upload_dir();
            $base_url = trailingslashit($upload_dir['baseurl']);
            // _wp_attached_file is relative to uploads directory, e.g., "2024/01/image.jpg"
            $local_url = $base_url . ltrim($attached_file, '/');
            
            // Original file URL replacement
            if ($cdn_url && $local_url && $cdn_url !== $local_url) {
                $url_replacements[$cdn_url] = $local_url;
            }
            
            // Size URLs replacement
            if (is_array($sizes_meta) && !empty($sizes_meta)) {
                $subdir = dirname($attached_file);
                
                foreach ($sizes_meta as $size_name => $size_info) {
                    if (!empty($size_info['url']) && isset($wp_metadata['sizes'][$size_name]['file'])) {
                        $size_filename = $wp_metadata['sizes'][$size_name]['file'];
                        // Construct local size URL: baseurl/subdir/filename
                        $size_path = ($subdir === '.' ? '' : $subdir . '/') . $size_filename;
                        $local_size_url = $base_url . ltrim($size_path, '/');
                        $cdn_size_url = $size_info['url'];
                        
                        if ($local_size_url && $cdn_size_url !== $local_size_url) {
                            $url_replacements[$cdn_size_url] = $local_size_url;
                        }
                    }
                }
            }
            
            // Perform URL rewriting in all post content
            if (!empty($url_replacements)) {
                $rewrite_result = $this->rewrite_urls_in_content($url_replacements);
                $result['details']['content_rewrite'] = $rewrite_result;
            }
            
            // Clear BlitzCDN metadata
            delete_post_meta($attachment_id, '_blitzcdn_file_id');
            delete_post_meta($attachment_id, '_blitzcdn_cdn_url');
            delete_post_meta($attachment_id, '_blitzcdn_sizes');

            $result['message'] = 'Successfully redownloaded all files and cleared CDN metadata';

            // 4. Delete from Appwrite if requested and all downloads succeeded
            if ($delete_from_appwrite && !empty($file_ids_to_delete)) {
                $deleted_count = 0;
                foreach ($file_ids_to_delete as $file_id) {
                    if ($this->appwrite_client->delete_file($file_id)) {
                        $deleted_count++;
                    }
                }
                $result['details']['deleted_from_appwrite'] = true;
                $result['details']['deleted_count'] = $deleted_count;
                $result['message'] .= ". Deleted $deleted_count files from Appwrite";
            }
        } else {
            $result['status'] = 'partial';
            $result['message'] = 'Some files failed to download. CDN metadata preserved for retry.';
        }

        return $result;
    }

    /**
     * Download a file from Appwrite and save it to the local filesystem.
     *
     * @param string $file_id       Appwrite file ID
     * @param string $target_path   Full local path where file should be saved
     * @param int    $attachment_id Attachment ID for context in error messages
     * @return array {
     *     @type string $status   'success', 'exists', or 'error'
     *     @type string $message  Status message
     *     @type string $path     The target path
     * }
     */
    private function download_and_save_file($file_id, $target_path, $attachment_id) {
        // Check if file already exists locally
        if (file_exists($target_path)) {
            // Verify it's a valid file (not empty)
            if (filesize($target_path) > 0) {
                return [
                    'status' => 'exists',
                    'message' => 'File already exists locally',
                    'path' => $target_path
                ];
            }
            // Empty file - delete and redownload
            @unlink($target_path);
        }

        // Ensure directory exists
        $dir = dirname($target_path);
        if (!file_exists($dir)) {
            if (!wp_mkdir_p($dir)) {
                return [
                    'status' => 'error',
                    'message' => 'Failed to create directory: ' . $dir,
                    'path' => $target_path
                ];
            }
        }

        // Check directory is writable
        if (!is_writable($dir)) {
            return [
                'status' => 'error',
                'message' => 'Directory is not writable: ' . $dir,
                'path' => $target_path
            ];
        }

        // Download from Appwrite
        $file_content = $this->appwrite_client->download_file($file_id);

        if ($file_content === false) {
            return [
                'status' => 'error',
                'message' => 'Failed to download from Appwrite',
                'path' => $target_path
            ];
        }

        if (empty($file_content)) {
            return [
                'status' => 'error',
                'message' => 'Downloaded empty content from Appwrite',
                'path' => $target_path
            ];
        }

        // Write file to disk
        $bytes_written = @file_put_contents($target_path, $file_content);

        if ($bytes_written === false) {
            return [
                'status' => 'error',
                'message' => 'Failed to write file to disk',
                'path' => $target_path
            ];
        }

        // Verify the file was written correctly
        if (!file_exists($target_path) || filesize($target_path) !== strlen($file_content)) {
            return [
                'status' => 'error',
                'message' => 'File verification failed after write',
                'path' => $target_path
            ];
        }

        return [
            'status' => 'success',
            'message' => 'File downloaded successfully',
            'path' => $target_path,
            'size' => $bytes_written
        ];
    }

    /**
     * Rewrite CDN URLs to local URLs in all post content across the site.
     * Similar to the original migration functionality but in reverse.
     *
     * @param array $url_replacements Array mapping CDN URLs to local URLs [cdn_url => local_url]
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
        foreach ($url_replacements as $cdn_url => $local_url) {
            // We need to handle both http and https, and URL-encoded versions
            $cdn_url_http = str_replace('https://', 'http://', $cdn_url);
            $cdn_url_https = str_replace('http://', 'https://', $cdn_url);
            $local_url_http = str_replace('https://', 'http://', $local_url);
            $local_url_https = str_replace('http://', 'https://', $local_url);
            
            // Also handle URL-encoded versions
            $cdn_url_encoded = esc_url_raw($cdn_url);
            $local_url_encoded = esc_url_raw($local_url);
            
            // Find all posts containing the CDN URL
            // Build the query with proper placeholders
            $query = "SELECT ID, post_content FROM {$wpdb->posts} 
                WHERE post_type IN ({$post_types_placeholders}) 
                AND post_content LIKE %s";
            
            $query_params = array_merge($post_types, ['%' . $wpdb->esc_like($cdn_url) . '%']);
            $posts = $wpdb->get_results($wpdb->prepare($query, $query_params));
            
            foreach ($posts as $post) {
                $original_content = $post->post_content;
                $updated_content = $original_content;
                $post_replacements = 0;
                
                // Replace https version
                if (strpos($updated_content, $cdn_url_https) !== false) {
                    $updated_content = str_replace($cdn_url_https, $local_url_https, $updated_content);
                    $post_replacements += substr_count($original_content, $cdn_url_https);
                }
                
                // Replace http version
                if (strpos($updated_content, $cdn_url_http) !== false) {
                    $updated_content = str_replace($cdn_url_http, $local_url_http, $updated_content);
                    $post_replacements += substr_count($original_content, $cdn_url_http);
                }
                
                // Replace URL-encoded versions if different
                if ($cdn_url_encoded !== $cdn_url_https && strpos($updated_content, $cdn_url_encoded) !== false) {
                    $updated_content = str_replace($cdn_url_encoded, $local_url_encoded, $updated_content);
                    $post_replacements += substr_count($original_content, $cdn_url_encoded);
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
                '%' . $wpdb->esc_like($cdn_url) . '%'
            ));
            
            foreach ($meta_results as $meta) {
                $original_value = $meta->meta_value;
                $updated_value = $original_value;
                
                // Replace https version
                if (strpos($updated_value, $cdn_url_https) !== false) {
                    $updated_value = str_replace($cdn_url_https, $local_url_https, $updated_value);
                }
                
                // Replace http version
                if (strpos($updated_value, $cdn_url_http) !== false) {
                    $updated_value = str_replace($cdn_url_http, $local_url_http, $updated_value);
                }
                
                // Replace URL-encoded versions if different
                if ($cdn_url_encoded !== $cdn_url_https && strpos($updated_value, $cdn_url_encoded) !== false) {
                    $updated_value = str_replace($cdn_url_encoded, $local_url_encoded, $updated_value);
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
     * Process a batch of attachments for redownload.
     *
     * @param int[] $attachment_ids       Array of attachment IDs to process
     * @param bool  $delete_from_appwrite Whether to delete from Appwrite after successful redownload
     * @return array Results keyed by attachment ID
     */
    public function process_batch($attachment_ids, $delete_from_appwrite = false) {
        $results = [];

        foreach ($attachment_ids as $attachment_id) {
            try {
                $results[$attachment_id] = $this->redownload_attachment($attachment_id, $delete_from_appwrite);
            } catch (\Exception $e) {
                $results[$attachment_id] = [
                    'status' => 'error',
                    'message' => 'Exception: ' . $e->getMessage(),
                    'details' => []
                ];
            }
        }

        return $results;
    }
}
