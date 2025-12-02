<?php

namespace BlitzCDN;

class BackgroundMigrator {

    const ACTION_HOOK = 'blitzcdn_background_migration_batch';
    const OPTION_STATUS = 'blitzcdn_migration_status';
    const BATCH_SIZE = 5; // Keep it small to avoid timeouts

    public function __construct() {
        // Register Action Scheduler hook
        add_action(self::ACTION_HOOK, [$this, 'process_batch']);
    }

    public function start_migration() {
        // Check if Action Scheduler is available
        if (!function_exists('as_schedule_single_action')) {
            error_log('BlitzCDN: Action Scheduler is not available. Please ensure woocommerce/action-scheduler is installed.');
            return false;
        }

        // Reset status
        update_option(self::OPTION_STATUS, [
            'status' => 'running',
            'processed' => 0,
            'total' => $this->get_total_items(),
            'start_time' => time(),
        ]);

        // Clear any existing scheduled actions
        as_unschedule_all_actions(self::ACTION_HOOK);

        // Schedule first batch immediately
        as_schedule_single_action(time(), self::ACTION_HOOK);
        
        // Force Action Scheduler to process immediately
        // This helps if WP-Cron is not working properly
        do_action('action_scheduler_run');
        
        return true;
    }

    public function stop_migration() {
        $status = get_option(self::OPTION_STATUS, []);
        $status['status'] = 'stopped';
        update_option(self::OPTION_STATUS, $status);
        
        // Unschedule all pending actions
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION_HOOK);
        }
    }

    public function get_status() {
        $status = get_option(self::OPTION_STATUS, ['status' => 'idle']);
        
        // If running, check if there are pending actions
        if ($status['status'] === 'running') {
            // Check if Action Scheduler has any pending actions
            if (function_exists('as_has_scheduled_action')) {
                $has_pending = as_has_scheduled_action(self::ACTION_HOOK);
                // If no pending actions and we haven't completed, something might be wrong
                // But don't auto-stop, let the user decide
            }
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
            // Migration complete
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
                    // Update processed count on success
                    $status['processed']++;
                } catch (\Exception $e) {
                    error_log("BlitzCDN Migration Error (ID $id): " . $e->getMessage());
                    // Still increment processed to avoid getting stuck on problematic items
                    $status['processed']++;
                }
            } else {
                // No metadata, skip but count as processed
                $status['processed']++;
            }
        }

        update_option(self::OPTION_STATUS, $status);

        // Schedule next batch using Action Scheduler
        // Use a small delay (1 second) to avoid overwhelming the system
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 1, self::ACTION_HOOK);
            // Also trigger the queue runner to ensure it runs
            do_action('action_scheduler_run');
        }
    }

    /**
     * Manual execution trigger - call this via direct HTTP request or WP-CLI
     * Useful when WP-Cron loopback requests are not working
     */
    public function manual_execute_batch() {
        $status = $this->get_status();
        
        if ($status['status'] !== 'running') {
            return ['error' => 'Migration is not running'];
        }

        // Process one batch
        $this->process_batch();
        
        $updated_status = $this->get_status();
        return [
            'success' => true,
            'processed' => $updated_status['processed'],
            'total' => $updated_status['total'],
            'percentage' => $updated_status['total'] > 0 ? round(($updated_status['processed'] / $updated_status['total']) * 100, 2) : 0,
            'status' => $updated_status['status']
        ];
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
