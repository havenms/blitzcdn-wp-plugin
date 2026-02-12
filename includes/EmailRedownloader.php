<?php

namespace BlitzCDN;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles email-based media redownloading from Appwrite.
 * 
 * This class provides functionality to:
 * 1. Query Appwrite Database for files associated with an email
 * 2. Download those files from Appwrite Storage OR
 * 3. Create WordPress attachments pointing to CDN URLs (without downloading)
 * 4. Maintain proper naming conventions and metadata
 */
class EmailRedownloader {

    private $appwrite_client;
    private static $is_redownloading = false;

    public function __construct(AppwriteClient $client) {
        $this->appwrite_client = $client;
    }

    /**
     * Check if a redownload operation is currently in progress.
     * Used by UploadHandler to skip processing during redownloads.
     *
     * @return bool
     */
    public static function is_redownloading() {
        return self::$is_redownloading;
    }

    /**
     * Get statistics about files associated with an email in Appwrite.
     *
     * @param string $email The email address to query
     * @return array {
     *     @type string   $status  'success' or 'error'
     *     @type string   $message Status message
     *     @type int      $total   Total number of files found
     *     @type string[] $file_ids Array of file IDs
     * }
     */
    public function get_email_stats($email) {
        if (empty($email) || !is_email($email)) {
            return [
                'status' => 'error',
                'message' => 'Invalid email address',
                'total' => 0,
                'file_ids' => []
            ];
        }

        $documents = $this->appwrite_client->list_documents_by_email($email);
        
        if ($documents === false) {
            return [
                'status' => 'error',
                'message' => 'Failed to query Appwrite database',
                'total' => 0,
                'file_ids' => []
            ];
        }

        $file_ids = [];
        foreach ($documents as $doc) {
            if (!empty($doc['fileId'])) {
                $file_ids[] = $doc['fileId'];
            }
        }

        // Deduplicate file IDs (in case of duplicate database entries)
        $file_ids = array_unique($file_ids);
        $file_ids = array_values($file_ids); // Re-index array after unique

        return [
            'status' => 'success',
            'message' => 'Found ' . count($file_ids) . ' unique files',
            'total' => count($file_ids),
            'file_ids' => $file_ids
        ];
    }

    /**
     * Redownload all files associated with an email address.
     *
     * @param string $email The email address to query
     * @param bool   $create_attachments Whether to create WordPress attachments (default: true)
     * @return array {
     *     @type string $status       'success', 'partial', or 'error'
     *     @type string $message      Status message
     *     @type array  $results      Array of file processing results keyed by file_id
     *     @type array  $summary      Summary statistics
     * }
     */
    public function redownload_by_email($email, $create_attachments = true) {
        if (empty($email) || !is_email($email)) {
            return [
                'status' => 'error',
                'message' => 'Invalid email address',
                'results' => [],
                'summary' => [
                    'total' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'skipped' => 0
                ]
            ];
        }

        // Get files associated with email
        $stats = $this->get_email_stats($email);
        
        if ($stats['status'] === 'error') {
            return [
                'status' => 'error',
                'message' => $stats['message'],
                'results' => [],
                'summary' => [
                    'total' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'skipped' => 0
                ]
            ];
        }

        if (empty($stats['file_ids'])) {
            return [
                'status' => 'success',
                'message' => 'No files found for this email',
                'results' => [],
                'summary' => [
                    'total' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'skipped' => 0
                ]
            ];
        }

        // Process each file
        $results = [];
        $summary = [
            'total' => count($stats['file_ids']),
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0
        ];

        foreach ($stats['file_ids'] as $file_id) {
            $result = $this->process_file($file_id, $create_attachments);
            $results[$file_id] = $result;
            
            if ($result['status'] === 'success') {
                $summary['successful']++;
            } elseif ($result['status'] === 'skipped') {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
            }
        }

        // Determine overall status
        if ($summary['successful'] === $summary['total']) {
            $status = 'success';
            $message = 'All files downloaded successfully';
        } elseif ($summary['successful'] > 0) {
            $status = 'partial';
            $message = sprintf(
                'Downloaded %d of %d files (%d failed, %d skipped)',
                $summary['successful'],
                $summary['total'],
                $summary['failed'],
                $summary['skipped']
            );
        } else {
            $status = 'error';
            $message = 'Failed to download any files';
        }

        return [
            'status' => $status,
            'message' => $message,
            'results' => $results,
            'summary' => $summary
        ];
    }

    /**
     * Process a single file from Appwrite.
     *
     * @param string $file_id             Appwrite file ID
     * @param bool   $create_attachments  Whether to create WordPress attachment
     * @return array {
     *     @type string $status         'success', 'skipped', or 'error'
     *     @type string $message        Status message
     *     @type string $file_name      Original file name
     *     @type string $local_path     Local file path (if successful)
     *     @type int    $attachment_id  WordPress attachment ID (if created)
     * }
     */
    private function process_file($file_id, $create_attachments) {
        // Set flag to prevent UploadHandler from re-uploading
        self::$is_redownloading = true;
        
        $result = [
            'status' => 'error',
            'message' => '',
            'file_name' => '',
            'local_path' => null,
            'attachment_id' => null
        ];

        // Get file metadata from Appwrite
        $file_meta = $this->appwrite_client->get_file_metadata($file_id);
        
        if (!$file_meta) {
            $result['message'] = 'Failed to get file metadata from Appwrite';
            return $result;
        }

        $file_name = $file_meta['name'] ?? 'unknown';
        $result['file_name'] = $file_name;

        // Check if file already exists in WordPress
        if ($create_attachments) {
            $existing_attachment = $this->find_attachment_by_file_id($file_id);
            if ($existing_attachment) {
                $result['status'] = 'skipped';
                $result['message'] = 'File already exists as WordPress attachment';
                $result['attachment_id'] = $existing_attachment;
                return $result;
            }
        }

        // Download file from Appwrite
        $file_content = $this->appwrite_client->download_file($file_id);
        
        if ($file_content === false) {
            $result['message'] = 'Failed to download file from Appwrite';
            return $result;
        }

        if (empty($file_content)) {
            $result['message'] = 'Downloaded empty content from Appwrite';
            return $result;
        }

        // Determine proper directory structure (year/month format)
        $upload_dir = wp_upload_dir();
        $time = current_time('mysql');
        
        // Create year/month subdirectory
        $subdir = '/' . date('Y', strtotime($time)) . '/' . date('m', strtotime($time));
        $target_dir = $upload_dir['basedir'] . $subdir;
        
        // Ensure directory exists
        if (!file_exists($target_dir)) {
            if (!wp_mkdir_p($target_dir)) {
                $result['message'] = 'Failed to create directory: ' . $target_dir;
                return $result;
            }
        }

        // Generate unique filename to avoid conflicts
        $unique_filename = wp_unique_filename($target_dir, $file_name);
        $target_path = $target_dir . '/' . $unique_filename;

        // Write file to disk
        $bytes_written = @file_put_contents($target_path, $file_content);

        if ($bytes_written === false) {
            $result['message'] = 'Failed to write file to disk';
            return $result;
        }

        // Verify file was written correctly
        if (!file_exists($target_path) || filesize($target_path) !== strlen($file_content)) {
            $result['message'] = 'File verification failed after write';
            return $result;
        }

        $result['local_path'] = $target_path;

        // Create WordPress attachment if requested
        if ($create_attachments) {
            $attachment_id = $this->create_wordpress_attachment($target_path, $file_id);
            
            if ($attachment_id) {
                $result['attachment_id'] = $attachment_id;
                $result['status'] = 'success';
                $result['message'] = 'File downloaded and WordPress attachment created';
            } else {
                // File downloaded but attachment creation failed
                $result['status'] = 'success';
                $result['message'] = 'File downloaded but attachment creation failed';
            }
        } else {
            $result['status'] = 'success';
            $result['message'] = 'File downloaded successfully';
        }

        return $result;
    }

    /**
     * Create a WordPress attachment from a local file.
     *
     * @param string $file_path Full path to the file
     * @param string $file_id   Appwrite file ID (for metadata)
     * @return int|false Attachment ID on success, false on failure
     */
    private function create_wordpress_attachment($file_path, $file_id) {
        if (!file_exists($file_path)) {
            return false;
        }

        $file_name = basename($file_path);
        $file_type = wp_check_filetype($file_name, null);
        
        $upload_dir = wp_upload_dir();
        
        // Get relative path for _wp_attached_file
        $attached_file = str_replace($upload_dir['basedir'] . '/', '', $file_path);

        // Prepare attachment data
        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_file_name(pathinfo($file_name, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit'
        ];

        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment, $file_path);

        if (is_wp_error($attachment_id)) {
            error_log('BlitzCDN: Failed to create attachment: ' . $attachment_id->get_error_message());
            self::$is_redownloading = false;
            return false;
        }

        // Store original Appwrite file ID for reference
        update_post_meta($attachment_id, '_blitzcdn_source_file_id', $file_id);
        update_post_meta($attachment_id, '_blitzcdn_imported_from_email', true);

        // DO NOT generate attachment metadata - this would create local thumbnails
        // WordPress will generate them on-demand if needed, but we want to use CDN versions
        // Just set basic metadata without thumbnail generation
        if (strpos($file_type['type'], 'image/') === 0) {
            // Basic metadata without generating thumbnails
            $metadata = [
                'file' => ltrim($virtual_file, '/'),
                'width' => 0,
                'height' => 0,
                'sizes' => [] // No local sizes - they're on CDN
            ];
            
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        return $attachment_id;
    }

    /**
     * Find an existing WordPress attachment by Appwrite file ID.
     *
     * @param string $file_id Appwrite file ID
     * @return int|false Attachment ID if found, false otherwise
     */
    private function find_attachment_by_file_id($file_id) {
        // Check both current BlitzCDN metadata and imported metadata
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_blitzcdn_file_id',
                    'value' => $file_id,
                    'compare' => '='
                ],
                [
                    'key' => '_blitzcdn_source_file_id',
                    'value' => $file_id,
                    'compare' => '='
                ]
            ]
        ]);

        if ($query->have_posts()) {
            return $query->posts[0];
        }

        return false;
    }

    /**
     * Process a batch of file IDs.
     *
     * @param string[] $file_ids          Array of Appwrite file IDs
     * @param bool     $create_attachments Whether to create WordPress attachments
     * @return array Results keyed by file ID
     */
    public function process_batch($file_ids, $create_attachments = true) {
        $results = [];

        foreach ($file_ids as $file_id) {
            try {
                $results[$file_id] = $this->process_file($file_id, $create_attachments);
            } catch (\Exception $e) {
                $results[$file_id] = [
                    'status' => 'error',
                    'message' => 'Exception: ' . $e->getMessage(),
                    'file_name' => '',
                    'local_path' => null,
                    'attachment_id' => null
                ];
            }
        }

        // Clear flag after batch processing
        self::$is_redownloading = false;

        return $results;
    }

    /**
     * Link files by email - creates WordPress attachments pointing to CDN URLs without downloading.
     * Much faster than redownload_by_email since it doesn't download actual files.
     *
     * @param string $email The email address to query
     * @return array {
     *     @type string $status       'success', 'partial', or 'error'
     *     @type string $message      Status message
     *     @type array  $results      Array of file processing results keyed by file_id
     *     @type array  $summary      Summary statistics
     * }
     */
    public function link_by_email($email) {
        if (empty($email) || !is_email($email)) {
            return [
                'status' => 'error',
                'message' => 'Invalid email address',
                'results' => [],
                'summary' => [
                    'total' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'skipped' => 0
                ]
            ];
        }

        // Get files associated with email
        $stats = $this->get_email_stats($email);
        
        if ($stats['status'] === 'error') {
            return [
                'status' => 'error',
                'message' => $stats['message'],
                'results' => [],
                'summary' => [
                    'total' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'skipped' => 0
                ]
            ];
        }

        if (empty($stats['file_ids'])) {
            return [
                'status' => 'success',
                'message' => 'No files found for this email',
                'results' => [],
                'summary' => [
                    'total' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'skipped' => 0
                ]
            ];
        }

        // Process each file
        $results = [];
        $summary = [
            'total' => count($stats['file_ids']),
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0
        ];

        foreach ($stats['file_ids'] as $file_id) {
            $result = $this->link_file($file_id);
            $results[$file_id] = $result;
            
            if ($result['status'] === 'success') {
                $summary['successful']++;
            } elseif ($result['status'] === 'skipped') {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
            }
        }

        // Determine overall status
        if ($summary['successful'] === $summary['total']) {
            $status = 'success';
            $message = 'All files linked successfully';
        } elseif ($summary['successful'] > 0) {
            $status = 'partial';
            $message = sprintf(
                'Linked %d of %d files (%d failed, %d skipped)',
                $summary['successful'],
                $summary['total'],
                $summary['failed'],
                $summary['skipped']
            );
        } else {
            $status = 'error';
            $message = 'Failed to link any files';
        }

        return [
            'status' => $status,
            'message' => $message,
            'results' => $results,
            'summary' => $summary
        ];
    }

    /**
     * Link a single file - create WordPress attachment pointing to CDN URL without downloading.
     *
     * @param string $file_id Appwrite file ID
     * @return array {
     *     @type string $status         'success', 'skipped', or 'error'
     *     @type string $message        Status message
     *     @type string $file_name      Original file name
     *     @type int    $attachment_id  WordPress attachment ID (if created)
     * }
     */
    private function link_file($file_id) {
        $result = [
            'status' => 'error',
            'message' => '',
            'file_name' => '',
            'attachment_id' => null
        ];

        // Get file metadata from Appwrite
        $file_meta = $this->appwrite_client->get_file_metadata($file_id);
        
        if (!$file_meta) {
            $result['message'] = 'Failed to get file metadata from Appwrite';
            return $result;
        }

        $file_name = $file_meta['name'] ?? 'unknown';
        $result['file_name'] = $file_name;

        // Check if file already exists in WordPress
        $existing_attachment = $this->find_attachment_by_file_id($file_id);
        if ($existing_attachment) {
            $result['status'] = 'skipped';
            $result['message'] = 'File already exists as WordPress attachment';
            $result['attachment_id'] = $existing_attachment;
            return $result;
        }

        // Get CDN URL
        $cdn_url = $this->get_cdn_url_for_file($file_id);
        
        if (!$cdn_url) {
            $result['message'] = 'Failed to generate CDN URL';
            return $result;
        }

        // Create WordPress attachment pointing to CDN URL
        $attachment_id = $this->create_virtual_attachment($file_id, $file_name, $cdn_url);
        
        if ($attachment_id) {
            $result['attachment_id'] = $attachment_id;
            $result['status'] = 'success';
            $result['message'] = 'WordPress attachment created (linked to CDN)';
        } else {
            $result['message'] = 'Failed to create WordPress attachment';
        }

        return $result;
    }

    /**
     * Create a virtual WordPress attachment that points to CDN URL without local file.
     *
     * @param string $file_id   Appwrite file ID
     * @param string $file_name Original file name
     * @param string $cdn_url   CDN URL for the file
     * @return int|false Attachment ID on success, false on failure
     */
    private function create_virtual_attachment($file_id, $file_name, $cdn_url) {
        $file_type = wp_check_filetype($file_name, null);
        
        // Create a virtual path for _wp_attached_file (won't exist locally)
        $upload_dir = wp_upload_dir();
        $time = current_time('mysql');
        $subdir = '/' . date('Y', strtotime($time)) . '/' . date('m', strtotime($time));
        $virtual_file = $subdir . '/' . sanitize_file_name($file_name);

        // Prepare attachment data
        $attachment = [
            'post_mime_type' => $file_type['type'],
            'post_title' => sanitize_file_name(pathinfo($file_name, PATHINFO_FILENAME)),
            'post_content' => '',
            'post_status' => 'inherit',
            'guid' => $cdn_url // Use CDN URL as GUID
        ];

        // Insert attachment
        $attachment_id = wp_insert_attachment($attachment, false); // false = no local file

        if (is_wp_error($attachment_id)) {
            error_log('BlitzCDN: Failed to create virtual attachment: ' . $attachment_id->get_error_message());
            return false;
        }

        // Set metadata
        update_post_meta($attachment_id, '_wp_attached_file', ltrim($virtual_file, '/'));
        update_post_meta($attachment_id, '_blitzcdn_file_id', $file_id);
        update_post_meta($attachment_id, '_blitzcdn_cdn_url', $cdn_url);
        update_post_meta($attachment_id, '_blitzcdn_virtual_attachment', true);

        // For images, try to get dimensions from Appwrite metadata if available
        if (strpos($file_type['type'], 'image/') === 0) {
            $metadata = [
                'file' => ltrim($virtual_file, '/'),
                'width' => 0,
                'height' => 0,
                'sizes' => [] // No local sizes since file isn't downloaded
            ];
            
            wp_update_attachment_metadata($attachment_id, $metadata);
        }

        return $attachment_id;
    }

    /**
     * Get CDN URL for a file ID.
     *
     * @param string $file_id Appwrite file ID
     * @return string|false CDN URL or false on failure
     */
    private function get_cdn_url_for_file($file_id) {
        $project_id = $this->appwrite_client->get_project_id();
        $bucket_id = $this->appwrite_client->get_bucket_id();
        $cdn_domain = $this->appwrite_client->get_cdn_domain();
        
        if (!empty($cdn_domain)) {
            // Ensure proper URL format
            $cdn_domain = rtrim($cdn_domain, '/');
            
            // If CDN domain doesn't have protocol, add https://
            if (!preg_match('/^https?:\/\//', $cdn_domain)) {
                $cdn_domain = 'https://' . $cdn_domain;
            }
            
            // CDN domain still needs full Appwrite path structure
            return $cdn_domain . '/storage/buckets/' . $bucket_id . '/files/' . $file_id . '/view?project=' . $project_id;
        }
        
        // Fallback to Appwrite endpoint with proper format
        $endpoint = $this->appwrite_client->get_endpoint();
        
        if ($endpoint && $project_id && $bucket_id) {
            $endpoint = rtrim($endpoint, '/');
            
            // Ensure proper URL format
            if (!preg_match('/^https?:\/\//', $endpoint)) {
                $endpoint = 'https://' . $endpoint;
            }
            
            return $endpoint . '/storage/buckets/' . $bucket_id . '/files/' . $file_id . '/view?project=' . $project_id;
        }
        
        return false;
    }

    /**
     * Process a batch of file IDs for linking (no download).
     *
     * @param string[] $file_ids Array of Appwrite file IDs
     * @return array Results keyed by file ID
     */
    public function link_batch($file_ids) {
        $results = [];

        foreach ($file_ids as $file_id) {
            try {
                $results[$file_id] = $this->link_file($file_id);
            } catch (\Exception $e) {
                $results[$file_id] = [
                    'status' => 'error',
                    'message' => 'Exception: ' . $e->getMessage(),
                    'file_name' => '',
                    'attachment_id' => null
                ];
            }
        }

        return $results;
    }
}
