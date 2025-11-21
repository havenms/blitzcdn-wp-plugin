<?php

namespace BlitzCDN;

class BackgroundMigrator {

    const CRON_HOOK = 'blitzcdn_background_migration_batch';
    const OPTION_STATUS = 'blitzcdn_migration_status';
    const BATCH_SIZE = 5; // Keep it small to avoid timeouts

    public function __construct() {
        add_action(self::CRON_HOOK, [$this, 'process_batch']);
    }

    public function start_migration() {
        // Reset status
        update_option(self::OPTION_STATUS, [
            'status' => 'running',
            'processed' => 0,
            'total' => $this->get_total_items(),
            'start_time' => time(),
        ]);

        // Schedule first batch
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time(), self::CRON_HOOK);
        }
    }

    public function stop_migration() {
        $status = get_option(self::OPTION_STATUS, []);
        $status['status'] = 'stopped';
        update_option(self::OPTION_STATUS, $status);
        
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function get_status() {
        $status = get_option(self::OPTION_STATUS, ['status' => 'idle']);
        
        // If running, update total in case new images were added or query was cached
        if ($status['status'] === 'running') {
             // Optional: Recalculate total remaining? 
             // For progress bar, we need total to be stable or increasing.
             // Let's just return what we have.
        }
        
        return $status;
    }

    public function process_batch() {
        $status = $this->get_status();

        if ($status['status'] !== 'running') {
            return;
        }

        $ids = $this->get_batch_ids(self::BATCH_SIZE);

        if (empty($ids)) {
            $status['status'] = 'completed';
            $status['completed_time'] = time();
            $status['processed'] = $status['total']; // Ensure 100%
            update_option(self::OPTION_STATUS, $status);
            return;
        }

        $upload_handler = Core::get_instance()->get_upload_handler();

        foreach ($ids as $id) {
            $metadata = wp_get_attachment_metadata($id);
            if ($metadata) {
                try {
                    $upload_handler->handle_upload_phase_2($metadata, $id);
                } catch (\Exception $e) {
                    error_log("BlitzCDN Migration Error (ID $id): " . $e->getMessage());
                }
            }
            // Update processed count
            $status['processed']++;
        }

        update_option(self::OPTION_STATUS, $status);

        // Schedule next batch
        wp_schedule_single_event(time(), self::CRON_HOOK);
    }

    private function get_total_items() {
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
        return $query->found_posts;
    }

    private function get_batch_ids($limit) {
        $query = new \WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => '_blitzcdn_file_id',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);
        return $query->posts;
    }
}
