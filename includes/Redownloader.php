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

        // 3. Clear BlitzCDN metadata if all downloads succeeded
        if ($all_successful) {
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
