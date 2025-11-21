<?php

namespace BlitzCDN\Admin;

use BlitzCDN\Core;

class Migrator {

    private $appwrite_client;

    public function __construct($appwrite_client) {
        $this->appwrite_client = $appwrite_client;
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_blitzcdn_migrate_batch', [$this, 'ajax_migrate_batch']);
        add_action('wp_ajax_blitzcdn_start_background_migration', [$this, 'ajax_start_background_migration']);
        add_action('wp_ajax_blitzcdn_get_migration_stats', [$this, 'ajax_get_stats']);
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'settings_page_blitzcdn') {
            return;
        }

        wp_enqueue_script('blitzcdn-migration', BLITZCDN_URL . 'assets/js/migration.js', ['jquery'], BLITZCDN_VERSION, true);
        wp_localize_script('blitzcdn-migration', 'blitzcdn_migration', [
            'nonce' => wp_create_nonce('blitzcdn_migration_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
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

        $total = $query->found_posts;
        wp_send_json_success(['total' => $total, 'ids' => $query->posts]);
    }

    public function ajax_migrate_batch() {
        check_ajax_referer('blitzcdn_migration_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $ids = isset($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        
        if (empty($ids)) {
            wp_send_json_error('No IDs provided');
        }

        $results = [];
        $upload_handler = Core::get_instance()->get_upload_handler(); // Need to expose this in Core

        foreach ($ids as $id) {
            $metadata = wp_get_attachment_metadata($id);
            if (!$metadata) {
                $results[$id] = ['status' => 'error', 'message' => 'No metadata'];
                continue;
            }

            try {
                // Reuse the logic in UploadHandler
                // We need to make sure we don't double-process if already done, but the query filters that.
                // However, handle_upload_phase_2 checks for existing meta too.
                
                $new_metadata = $upload_handler->handle_upload_phase_2($metadata, $id);
                
                // We don't strictly need to update metadata if handle_upload_phase_2 only modifies side-effects (postmeta),
                // but it returns metadata, and sometimes plugins modify it.
                // In our case, handle_upload_phase_2 modifies postmeta directly for BlitzCDN fields.
                // It doesn't modify the $metadata array structure regarding sizes (it reads it).
                // So we might not need wp_update_attachment_metadata unless we changed something inside $metadata.
                // But let's be safe.
                
                $results[$id] = ['status' => 'success'];
            } catch (\Exception $e) {
                $results[$id] = ['status' => 'error', 'message' => $e->getMessage()];
            }
        }

        wp_send_json_success($results);
    }

    public function ajax_start_background_migration() {
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

        $ids = $query->posts;
        
        if (empty($ids)) {
            wp_send_json_error('No items to migrate.');
        }

        $bg_process = Core::get_instance()->get_background_process();

        foreach ($ids as $id) {
            $bg_process->push_to_queue($id);
        }

        $bg_process->save()->dispatch();

        wp_send_json_success(['message' => 'Background migration started. You can close this window.', 'count' => count($ids)]);
    }
}
