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
    private $rewrite_urls = true; // Enable URL rewriting by default

    public function __construct(AppwriteClient $client) {
        $this->appwrite_client = $client;
    }

    /**
     * Enable or disable URL rewriting during linking.
     *
     * @param bool $enabled Whether to rewrite URLs in content
     */
    public function set_url_rewriting($enabled) {
        $this->rewrite_urls = (bool) $enabled;
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
                'file' => ltrim($attached_file, '/'),
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
     * Find a local WordPress attachment by filename that is NOT linked to CDN.
     *
     * @param string $filename The filename to search for
     * @return int|false Attachment ID if found, false otherwise
     */
    private function find_local_attachment_by_filename($filename) {
        global $wpdb;
        
        // Search for attachments where _wp_attached_file contains the filename
        // and does NOT have BlitzCDN metadata (meaning it's a local file)
        $query = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND p.post_status = 'inherit'
            AND pm.meta_key = '_wp_attached_file'
            AND pm.meta_value LIKE %s
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm2
                WHERE pm2.post_id = p.ID
                AND pm2.meta_key IN ('_blitzcdn_file_id', '_blitzcdn_cdn_url')
            )
            LIMIT 1",
            '%' . $wpdb->esc_like($filename)
        );
        
        $attachment_id = $wpdb->get_var($query);
        
        return $attachment_id ? (int) $attachment_id : false;
    }

    /**
     * Find any WordPress attachment (even deleted/trash) by filename.
     * Used to find old attachment IDs that WooCommerce products might still reference.
     *
     * @param string $filename The filename to search for
     * @return int|false Attachment ID if found, false otherwise
     */
    private function find_attachment_by_filename_including_deleted($filename) {
        global $wpdb;
        
        // Search for ANY attachment with this filename, regardless of status
        // Exclude only if it already has BlitzCDN metadata (already linked)
        $query = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'attachment'
            AND pm.meta_key = '_wp_attached_file'
            AND pm.meta_value LIKE %s
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->postmeta} pm2
                WHERE pm2.post_id = p.ID
                AND pm2.meta_key IN ('_blitzcdn_file_id', '_blitzcdn_cdn_url')
            )
            ORDER BY p.post_status = 'inherit' DESC, p.ID DESC
            LIMIT 1",
            '%' . $wpdb->esc_like($filename)
        );
        
        $attachment_id = $wpdb->get_var($query);
        
        return $attachment_id ? (int) $attachment_id : false;
    }

    /**
     * Update WooCommerce products to use new attachment ID instead of old one.
     * Updates featured images, gallery images, and variation images.
     *
     * @param int $old_attachment_id Old attachment ID being replaced
     * @param int $new_attachment_id New attachment ID to use
     * @return array {
     *     @type int $products_updated Number of products updated
     *     @type int $variations_updated Number of variations updated
     *     @type int $galleries_updated Number of gallery updates
     * }
     */
    private function update_woocommerce_product_images($old_attachment_id, $new_attachment_id) {
        global $wpdb;
        
        $stats = [
            'products_updated' => 0,
            'variations_updated' => 0,
            'galleries_updated' => 0
        ];
        
        // 1. Update featured/thumbnail images (_thumbnail_id)
        $updated = $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => $new_attachment_id],
            [
                'meta_key' => '_thumbnail_id',
                'meta_value' => $old_attachment_id
            ],
            ['%d'],
            ['%s', '%d']
        );
        
        if ($updated) {
            $stats['products_updated'] = $updated;
            
            // Clear product cache for updated products
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
                $new_attachment_id
            ));
            foreach ($product_ids as $product_id) {
                clean_post_cache($product_id);
            }
        }
        
        // 2. Update product galleries (_product_image_gallery)
        // Gallery is stored as comma-separated attachment IDs
        $gallery_results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} 
            WHERE meta_key = '_product_image_gallery' 
            AND meta_value LIKE %s",
            '%' . $old_attachment_id . '%'
        ));
        
        foreach ($gallery_results as $row) {
            $gallery_ids = explode(',', $row->meta_value);
            $updated_gallery = [];
            $changed = false;
            
            foreach ($gallery_ids as $id) {
                $id = trim($id);
                if ($id == $old_attachment_id) {
                    $updated_gallery[] = $new_attachment_id;
                    $changed = true;
                } else {
                    $updated_gallery[] = $id;
                }
            }
            
            if ($changed) {
                update_post_meta($row->post_id, '_product_image_gallery', implode(',', $updated_gallery));
                clean_post_cache($row->post_id);
                $stats['galleries_updated']++;
            }
        }
        
        // 3. Update variation images (variations are child posts of products)
        $variation_updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            SET pm.meta_value = %d
            WHERE p.post_type = 'product_variation'
            AND pm.meta_key = '_thumbnail_id'
            AND pm.meta_value = %d",
            $new_attachment_id,
            $old_attachment_id
        ));
        
        if ($variation_updated) {
            $stats['variations_updated'] = $variation_updated;
        }
        
        return $stats;
    }

    /**
     * Reconnect existing CDN attachments to WooCommerce products.
     * Scans all BlitzCDN attachments in media library and updates WooCommerce products
     * that might still reference old attachment IDs.
     *
     * @return array {
     *     @type string $status 'success' or 'error'
     *     @type string $message Status message
     *     @type int    $attachments_scanned Number of attachments scanned
     *     @type int    $products_updated Number of products updated
     *     @type int    $variations_updated Number of variations updated
     *     @type int    $galleries_updated Number of galleries updated
     * }
     */
    public function reconnect_woocommerce_images() {
        global $wpdb;
        
        $result = [
            'status' => 'error',
            'message' => '',
            'attachments_scanned' => 0,
            'products_updated' => 0,
            'variations_updated' => 0,
            'galleries_updated' => 0
        ];
        
        try {
            // Check if WooCommerce is active
            if (!class_exists('WooCommerce')) {
                $result['status'] = 'success';
                $result['message'] = 'WooCommerce not detected - no products to update';
                return $result;
            }
            
            // Get all attachments that have BlitzCDN metadata
            $blitzcdn_attachments = $wpdb->get_results(
                "SELECT DISTINCT p.ID, pm_file.meta_value as filename
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm_blitz ON p.ID = pm_blitz.post_id
                LEFT JOIN {$wpdb->postmeta} pm_file ON p.ID = pm_file.post_id AND pm_file.meta_key = '_wp_attached_file'
                WHERE p.post_type = 'attachment'
                AND p.post_status = 'inherit'
                AND pm_blitz.meta_key = '_blitzcdn_file_id'
                AND pm_blitz.meta_value != ''
                ORDER BY p.ID DESC"
            );
            
            if (empty($blitzcdn_attachments)) {
                $result['status'] = 'success';
                $result['message'] = 'No CDN attachments found';
                return $result;
            }
            
            $total_products_updated = 0;
            $total_variations_updated = 0;
            $total_galleries_updated = 0;
            
            foreach ($blitzcdn_attachments as $attachment) {
                $result['attachments_scanned']++;
                
                // Skip if no filename
                if (empty($attachment->filename)) {
                    continue;
                }
                
                // Get just the filename (not the full path)
                $filename = basename($attachment->filename);
                
                if (empty($filename)) {
                    continue;
                }
                
                // Find any old attachments with the same filename (but without BlitzCDN metadata)
                $old_attachment_id = $this->find_attachment_by_filename_including_deleted($filename);
                
                if ($old_attachment_id && $old_attachment_id != $attachment->ID) {
                    // Update WooCommerce products to use the new attachment ID
                    $stats = $this->update_woocommerce_product_images($old_attachment_id, $attachment->ID);
                    
                    $total_products_updated += $stats['products_updated'];
                    $total_variations_updated += $stats['variations_updated'];
                    $total_galleries_updated += $stats['galleries_updated'];
                }
            }
            
            $result['products_updated'] = $total_products_updated;
            $result['variations_updated'] = $total_variations_updated;
            $result['galleries_updated'] = $total_galleries_updated;
            
            if ($total_products_updated > 0 || $total_variations_updated > 0 || $total_galleries_updated > 0) {
                $result['status'] = 'success';
                $result['message'] = sprintf(
                    'Updated %d products, %d variations, and %d galleries',
                    $total_products_updated,
                    $total_variations_updated,
                    $total_galleries_updated
                );
            } else {
                $result['status'] = 'success';
                $result['message'] = 'No WooCommerce products needed updating';
            }
        } catch (\Exception $e) {
            error_log('BlitzCDN: Error reconnecting WooCommerce images: ' . $e->getMessage());
            $result['status'] = 'error';
            $result['message'] = 'Error: ' . $e->getMessage();
        }
        
        return $result;
    }

    /**
     * Convert a local WordPress attachment to use CDN URL.
     *
     * @param int    $attachment_id WordPress attachment ID
     * @param string $file_id       Appwrite file ID
     * @param string $cdn_url       CDN URL for the file
     * @return bool True on success, false on failure
     */
    private function convert_local_to_cdn_attachment($attachment_id, $file_id, $cdn_url) {
        // Update post GUID to use CDN URL
        $updated = wp_update_post([
            'ID' => $attachment_id,
            'guid' => $cdn_url
        ], true);
        
        if (is_wp_error($updated)) {
            error_log('BlitzCDN: Failed to update attachment GUID: ' . $updated->get_error_message());
            return false;
        }

        // Add BlitzCDN metadata
        update_post_meta($attachment_id, '_blitzcdn_file_id', $file_id);
        update_post_meta($attachment_id, '_blitzcdn_cdn_url', $cdn_url);
        update_post_meta($attachment_id, '_blitzcdn_converted_from_local', true);

        return true;
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

        // Get CDN URL early (needed for both new and existing attachments)
        $cdn_url = $this->get_cdn_url_for_file($file_id);
        
        if (!$cdn_url) {
            $result['message'] = 'Failed to generate CDN URL';
            return $result;
        }

        // Check if file already has BlitzCDN metadata (already linked to CDN)
        $existing_attachment = $this->find_attachment_by_file_id($file_id);
        if ($existing_attachment) {
            // Even though file is already linked, still rewrite URLs in content
            // (in case old local URLs still exist in posts/pages)
            if ($this->rewrite_urls) {
                $this->rewrite_file_urls_in_content($file_name, $cdn_url);
            }
            
            $result['status'] = 'skipped';
            $result['message'] = 'File already linked to CDN (URLs rewritten)';
            $result['attachment_id'] = $existing_attachment;
            return $result;
        }

        // Check if local file exists with same name but not linked to CDN
        $local_attachment = $this->find_local_attachment_by_filename($file_name);
        
        if ($local_attachment) {
            // Update existing local attachment to use CDN
            $updated = $this->convert_local_to_cdn_attachment($local_attachment, $file_id, $cdn_url);
            
            if ($updated) {
                // Rewrite URLs in content after successful conversion
                if ($this->rewrite_urls) {
                    $this->rewrite_file_urls_in_content($file_name, $cdn_url);
                }
                
                $result['attachment_id'] = $local_attachment;
                $result['status'] = 'success';
                $result['message'] = 'Local file updated to use CDN link';
            } else {
                $result['message'] = 'Failed to update local file to CDN link';
            }
            
            return $result;
        }

        // Check if there's an old (possibly deleted) attachment with same filename
        // This handles the case where files were deleted during hack but WooCommerce still references them
        $old_attachment_id = $this->find_attachment_by_filename_including_deleted($file_name);

        // Create new WordPress attachment pointing to CDN URL
        $attachment_id = $this->create_virtual_attachment($file_id, $file_name, $cdn_url);
        
        if ($attachment_id) {
            // If there was an old attachment, update WooCommerce products to use new attachment ID
            if ($old_attachment_id) {
                $this->update_woocommerce_product_images($old_attachment_id, $attachment_id);
            }
            
            // Rewrite URLs in content after successful creation
            if ($this->rewrite_urls) {
                $this->rewrite_file_urls_in_content($file_name, $cdn_url);
            }
            
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

    /**
     * Rewrite URLs in post content to use CDN URL for a specific filename.
     * Searches for any URL containing the filename and replaces it with the CDN URL.
     *
     * @param string $filename The filename to search for (e.g., "image.jpg")
     * @param string $cdn_url  The CDN URL to replace with
     * @return array {
     *     @type int $posts_updated Number of posts updated
     *     @type int $replacements_made Total number of URL replacements made
     * }
     */
    private function rewrite_file_urls_in_content($filename, $cdn_url) {
        global $wpdb;
        
        $posts_updated = 0;
        $replacements_made = 0;
        
        if (empty($filename) || empty($cdn_url)) {
            return [
                'posts_updated' => 0,
                'replacements_made' => 0
            ];
        }
        
        // Get all post types that can contain content
        $post_types = get_post_types(['public' => true], 'names');
        $post_types[] = 'attachment'; // Also check attachment descriptions
        $post_types_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        
        // Build the query to find posts containing the filename
        $query = "SELECT ID, post_content FROM {$wpdb->posts} 
            WHERE post_type IN ({$post_types_placeholders}) 
            AND post_content LIKE %s";
        
        $query_params = array_merge($post_types, ['%' . $wpdb->esc_like($filename) . '%']);
        $posts = $wpdb->get_results($wpdb->prepare($query, $query_params));
        
        // Prepare CDN URL variants (http and https)
        $cdn_url_https = preg_replace('/^http:/', 'https:', $cdn_url);
        $cdn_url_http = preg_replace('/^https:/', 'http:', $cdn_url);
        
        foreach ($posts as $post) {
            $original_content = $post->post_content;
            $updated_content = $original_content;
            $post_replacements = 0;
            
            // Use regex to find and replace URLs containing the filename
            // Pattern matches URLs in various formats: src="...", href="...", url(...), plain URLs
            
            // Pattern 1: Find URLs in attributes (src, href, data-src, etc.)
            $pattern1 = '/((?:src|href|data-src|data-href|data-lazy-src|poster)=["\'])(https?:\/\/[^"\']*' . preg_quote($filename, '/') . '[^"\']*)(["\'])/i';
            $updated_content = preg_replace_callback($pattern1, function($matches) use ($cdn_url_https, $cdn_url_http, &$post_replacements) {
                $post_replacements++;
                $protocol_prefix = (strpos($matches[2], 'https://') === 0) ? $cdn_url_https : $cdn_url_http;
                return $matches[1] . $protocol_prefix . $matches[3];
            }, $updated_content);
            
            // Pattern 2: Find URLs in CSS url() declarations
            $pattern2 = '/(url\(["\']?)(https?:\/\/[^)"\']*)(' . preg_quote($filename, '/') . '[^)"\']*)(["\']?\))/i';
            $updated_content = preg_replace_callback($pattern2, function($matches) use ($cdn_url_https, $cdn_url_http, &$post_replacements) {
                $post_replacements++;
                $protocol_prefix = (strpos($matches[2], 'https://') === 0) ? $cdn_url_https : $cdn_url_http;
                return $matches[1] . $protocol_prefix . $matches[4];
            }, $updated_content);
            
            // Pattern 3: Find plain URLs in text/JSON (for Gutenberg blocks)
            $pattern3 = '/(https?:\/\/[^\s"\'<>,]*' . preg_quote($filename, '/') . '[^\s"\'<>,]*)/i';
            $updated_content = preg_replace_callback($pattern3, function($matches) use ($cdn_url_https, $cdn_url_http, &$post_replacements) {
                // Don't double-replace if already CDN URL
                if (strpos($matches[1], $cdn_url_https) !== false || strpos($matches[1], $cdn_url_http) !== false) {
                    return $matches[1];
                }
                $post_replacements++;
                return (strpos($matches[1], 'https://') === 0) ? $cdn_url_https : $cdn_url_http;
            }, $updated_content);
            
            // Pattern 4: Handle JSON-encoded URLs (Gutenberg blocks store escaped URLs)
            $pattern4 = '/(https?:\\\\\/\\\\\/[^\s"\'<>,\\\\]*' . preg_quote($filename, '/') . '[^\s"\'<>,\\\\]*)/i';
            $cdn_url_json = addslashes($cdn_url_https);
            $updated_content = preg_replace_callback($pattern4, function($matches) use ($cdn_url_json, &$post_replacements) {
                $post_replacements++;
                return $cdn_url_json;
            }, $updated_content);
            
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
        
        // Also check postmeta for URLs (some plugins/page builders store URLs in meta)
        $meta_results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_id, meta_key, meta_value FROM {$wpdb->postmeta} 
            WHERE meta_value LIKE %s",
            '%' . $wpdb->esc_like($filename) . '%'
        ));
        
        foreach ($meta_results as $meta) {
            $original_value = $meta->meta_value;
            $updated_value = $original_value;
            
            // Try to detect if this is serialized data
            if (is_serialized($original_value)) {
                $unserialized = @unserialize($original_value);
                if ($unserialized !== false) {
                    // Recursively replace URLs in serialized data
                    $updated_unserialized = $this->replace_urls_in_array($unserialized, $filename, $cdn_url_https);
                    if ($updated_unserialized !== $unserialized) {
                        $updated_value = serialize($updated_unserialized);
                    }
                }
            } elseif ($this->is_json($original_value)) {
                // Handle JSON data
                $json_data = json_decode($original_value, true);
                if ($json_data !== null) {
                    $updated_json = $this->replace_urls_in_array($json_data, $filename, $cdn_url_https);
                    if ($updated_json !== $json_data) {
                        $updated_value = wp_json_encode($updated_json);
                    }
                }
            } else {
                // Plain text - use regex replacement
                $pattern = '/(https?:\/\/[^\s"\'<>,]*' . preg_quote($filename, '/') . '[^\s"\'<>,]*)/i';
                $updated_value = preg_replace_callback($pattern, function($matches) use ($cdn_url_https, $cdn_url_http) {
                    if (strpos($matches[1], $cdn_url_https) !== false || strpos($matches[1], $cdn_url_http) !== false) {
                        return $matches[1];
                    }
                    return (strpos($matches[1], 'https://') === 0) ? $cdn_url_https : $cdn_url_http;
                }, $updated_value);
            }
            
            // Only update if value changed
            if ($updated_value !== $original_value) {
                $wpdb->update(
                    $wpdb->postmeta,
                    ['meta_value' => $updated_value],
                    ['meta_id' => $meta->meta_id],
                    ['%s'],
                    ['%d']
                );
                
                // Clear post meta cache
                wp_cache_delete($meta->post_id, 'post_meta');
            }
        }
        
        return [
            'posts_updated' => $posts_updated,
            'replacements_made' => $replacements_made
        ];
    }

    /**
     * Recursively replace URLs in arrays (for serialized/JSON data).
     *
     * @param mixed  $data     The data to process (array, string, or other)
     * @param string $filename The filename to search for
     * @param string $cdn_url  The CDN URL to replace with
     * @return mixed The processed data
     */
    private function replace_urls_in_array($data, $filename, $cdn_url) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->replace_urls_in_array($value, $filename, $cdn_url);
            }
        } elseif (is_string($data) && strpos($data, $filename) !== false) {
            $cdn_url_https = preg_replace('/^http:/', 'https:', $cdn_url);
            $cdn_url_http = preg_replace('/^https:/', 'http:', $cdn_url);
            
            $pattern = '/(https?:\/\/[^\s"\'<>,]*' . preg_quote($filename, '/') . '[^\s"\'<>,]*)/i';
            $data = preg_replace_callback($pattern, function($matches) use ($cdn_url_https, $cdn_url_http) {
                if (strpos($matches[1], $cdn_url_https) !== false || strpos($matches[1], $cdn_url_http) !== false) {
                    return $matches[1];
                }
                return (strpos($matches[1], 'https://') === 0) ? $cdn_url_https : $cdn_url_http;
            }, $data);
        }
        
        return $data;
    }

    /**
     * Check if a string is valid JSON.
     *
     * @param string $string The string to check
     * @return bool True if valid JSON, false otherwise
     */
    private function is_json($string) {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }
}

