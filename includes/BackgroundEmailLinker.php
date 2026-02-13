<?php

namespace BlitzCDN;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles background processing of email-based media linking via WP-Cron.
 * Creates WordPress attachments pointing to CDN URLs without downloading files.
 */
class BackgroundEmailLinker {

    const ACTION_HOOK = 'blitzcdn_background_email_link_batch';
    const OPTION_STATUS = 'blitzcdn_email_link_status';
    const BATCH_SIZE = 10; // Process 10 files per batch

    private $email_redownloader;

    public function __construct(EmailRedownloader $email_redownloader) {
        $this->email_redownloader = $email_redownloader;
        
        // Register Action Scheduler hook
        add_action(self::ACTION_HOOK, [$this, 'process_batch']);
    }

    /**
     * Start background linking for an email address.
     *
     * @param string $email Email address to process
     * @return array Result with status and details
     */
    public function start_linking($email) {
        if (empty($email) || !is_email($email)) {
            return [
                'success' => false,
                'message' => 'Invalid email address'
            ];
        }

        // Check if Action Scheduler is available
        if (!function_exists('as_schedule_single_action')) {
            return [
                'success' => false,
                'message' => 'Action Scheduler is not available'
            ];
        }

        // Get file IDs for this email
        $stats = $this->email_redownloader->get_email_stats($email);
        
        if ($stats['status'] === 'error') {
            return [
                'success' => false,
                'message' => $stats['message']
            ];
        }

        if (empty($stats['file_ids'])) {
            return [
                'success' => false,
                'message' => 'No files found for this email'
            ];
        }

        // Initialize status
        update_option(self::OPTION_STATUS, [
            'status' => 'running',
            'email' => $email,
            'file_ids' => $stats['file_ids'],
            'total' => count($stats['file_ids']),
            'processed' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'start_time' => time(),
            'current_index' => 0
        ]);

        // Clear any existing scheduled actions
        as_unschedule_all_actions(self::ACTION_HOOK);

        // Schedule first batch immediately
        as_schedule_single_action(time(), self::ACTION_HOOK);
        
        // Force Action Scheduler to process immediately
        do_action('action_scheduler_run');
        
        return [
            'success' => true,
            'message' => 'Background linking started',
            'total' => count($stats['file_ids'])
        ];
    }

    /**
     * Stop background linking.
     *
     * @return bool Success status
     */
    public function stop_linking() {
        // Update status FIRST before unscheduling
        $status = get_option(self::OPTION_STATUS, []);
        $status['status'] = 'stopped';
        $status['stopped_time'] = time();
        update_option(self::OPTION_STATUS, $status);
        
        // Unschedule all pending actions
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION_HOOK);
        }
        
        return true;
    }

    /**
     * Get current linking status.
     *
     * @return array Status information
     */
    public function get_status() {
        $status = get_option(self::OPTION_STATUS, ['status' => 'idle']);
        
        // Calculate percentage if running
        if (isset($status['total']) && $status['total'] > 0) {
            $status['percentage'] = round(($status['processed'] / $status['total']) * 100, 2);
        } else {
            $status['percentage'] = 0;
        }
        
        return $status;
    }

    /**
     * Process a batch of files.
     * Called by Action Scheduler.
     */
    public function process_batch() {
        $status = $this->get_status();

        if ($status['status'] !== 'running') {
            return;
        }

        $file_ids = $status['file_ids'] ?? [];
        $current_index = $status['current_index'] ?? 0;

        // Get batch of file IDs to process
        $batch = array_slice($file_ids, $current_index, self::BATCH_SIZE);

        if (empty($batch)) {
            // Processing complete
            $status['status'] = 'completed';
            $status['completed_time'] = time();
            $status['processed'] = $status['total']; // Ensure 100%
            update_option(self::OPTION_STATUS, $status);
            return;
        }

        // Process batch
        $results = $this->email_redownloader->link_batch($batch);

        // Update counters
        foreach ($results as $file_id => $result) {
            $status['processed']++;
            
            if ($result['status'] === 'success') {
                $status['successful']++;
            } elseif ($result['status'] === 'skipped') {
                $status['skipped']++;
            } else {
                $status['failed']++;
            }
        }

        // Update current index
        $status['current_index'] = $current_index + self::BATCH_SIZE;
        update_option(self::OPTION_STATUS, $status);

        // Re-check status before scheduling next batch (in case stop was called)
        $current_status = get_option(self::OPTION_STATUS, []);
        if ($current_status['status'] !== 'running') {
            return;
        }

        // Schedule next batch
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 1, self::ACTION_HOOK);
        }
    }

    /**
     * Manual execution trigger - for direct HTTP requests or WP-CLI.
     *
     * @return array Execution result
     */
    public function manual_execute_batch() {
        $status = $this->get_status();
        
        if ($status['status'] !== 'running') {
            return ['error' => 'Linking is not running'];
        }

        // Process one batch
        $this->process_batch();
        
        $updated_status = $this->get_status();
        return [
            'success' => true,
            'processed' => $updated_status['processed'],
            'total' => $updated_status['total'],
            'percentage' => $updated_status['percentage'],
            'status' => $updated_status['status']
        ];
    }
}
