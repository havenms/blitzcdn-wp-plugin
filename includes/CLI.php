<?php

namespace BlitzCDN;

/**
 * WP-CLI Commands for BlitzCDN
 * Usage: wp blitzcdn migrate [--manual]
 */

if (defined('WP_CLI') && WP_CLI) {
    class CLI {

        /**
         * Start background migration
         *
         * ## OPTIONS
         *
         * [--manual]
         * : Manually process batches instead of using Action Scheduler
         *
         * @when after_wp_load
         */
        public function migrate($args, $assoc_args) {
            $background_migrator = Core::get_instance()->get_background_migrator();
            
            // Start migration
            if ($background_migrator->start_migration()) {
                \WP_CLI::success('Background migration started');
            } else {
                \WP_CLI::error('Failed to start background migration. Action Scheduler may not be available.');
            }

            // If --manual flag is set, process batches manually
            if (isset($assoc_args['manual'])) {
                $this->process_manually($background_migrator);
            }
        }

        /**
         * Get migration status
         *
         * @when after_wp_load
         */
        public function status($args, $assoc_args) {
            $background_migrator = Core::get_instance()->get_background_migrator();
            $status = $background_migrator->get_status();

            \WP_CLI::line("Status: {$status['status']}");
            \WP_CLI::line("Processed: {$status['processed']} / {$status['total']} assets");
            
            // Show image count if available
            if (isset($status['processed_images']) && isset($status['total_images'])) {
                \WP_CLI::line("Images: {$status['processed_images']} / {$status['total_images']}");
            }
            
            if ($status['total'] > 0) {
                $percentage = round(($status['processed'] / $status['total']) * 100, 2);
                \WP_CLI::line("Progress: {$percentage}%");
            }
        }

        /**
         * Manually process migration batches (for when Action Scheduler fails)
         *
         * @when after_wp_load
         */
        public function process_batch($args, $assoc_args) {
            $background_migrator = Core::get_instance()->get_background_migrator();
            $result = $background_migrator->manual_execute_batch();

            if (isset($result['error'])) {
                \WP_CLI::error($result['error']);
            } else {
                \WP_CLI::line("Processed: {$result['processed']} / {$result['total']} assets ({$result['percentage']}%)");
                if (isset($result['processed_images']) && isset($result['total_images'])) {
                    \WP_CLI::line("Images: {$result['processed_images']} / {$result['total_images']}");
                }
                if ($result['status'] === 'completed') {
                    \WP_CLI::success('Migration completed!');
                }
            }
        }

        /**
         * Run diagnostics on Action Scheduler setup
         *
         * @when after_wp_load
         */
        public function diagnostics($args, $assoc_args) {
            try {
                $report = Diagnostics::generate_report();

                \WP_CLI::line("\n=== ACTION SCHEDULER ===");
                \WP_CLI::line("Loaded: " . ($report['action_scheduler']['action_scheduler_loaded'] ? 'Yes' : 'No'));
                \WP_CLI::line("Functions Available: " . ($report['action_scheduler']['as_schedule_single_action_exists'] ? 'Yes' : 'No'));
                \WP_CLI::line("Initialized: " . ($report['action_scheduler']['action_scheduler_init_exists'] ? 'Yes' : 'No'));

                \WP_CLI::line("\n=== WORDPRESS CRON ===");
                \WP_CLI::line("WP-Cron Disabled: " . ($report['wordpress_cron']['wp_cron_disabled'] ? 'Yes' : 'No'));
                \WP_CLI::line("Alternate WP-Cron: " . ($report['wordpress_cron']['alternate_wp_cron'] ? 'Yes' : 'No'));
                \WP_CLI::line("Scheduled Crons: " . $report['wordpress_cron']['scheduled_crons']);
                \WP_CLI::line("Loopback Request: " . ($report['wordpress_cron']['loopback_test']['success'] ? 'Success' : 'Failed'));
                if (!$report['wordpress_cron']['loopback_test']['success']) {
                    \WP_CLI::line("Error: " . $report['wordpress_cron']['loopback_test']['error']);
                }

                \WP_CLI::line("\n=== MIGRATION ===");
                \WP_CLI::line("Status: " . $report['migration']['status']);
                \WP_CLI::line("Processed: " . ($report['migration']['processed'] ?? 0) . " / " . ($report['migration']['total'] ?? 0));
            } catch (\Exception $e) {
                \WP_CLI::error('Diagnostics failed: ' . $e->getMessage());
            }
        }

        /**
         * Process migration batches manually
         */
        private function process_manually($background_migrator) {
            $batch_count = 0;
            \WP_CLI::line("Starting manual batch processing...");

            while (true) {
                $result = $background_migrator->manual_execute_batch();
                
                if (isset($result['error'])) {
                    \WP_CLI::error($result['error']);
                    break;
                }

                $batch_count++;
                \WP_CLI::log("Batch {$batch_count}: {$result['processed']} / {$result['total']} assets ({$result['percentage']}%)");
                
                // Show image count if available
                if (isset($result['processed_images']) && isset($result['total_images'])) {
                    \WP_CLI::log("  Images: {$result['processed_images']} / {$result['total_images']}");
                }

                if ($result['status'] === 'completed') {
                    \WP_CLI::success("Migration completed! Processed {$result['total']} assets in {$batch_count} batches.");
                    break;
                }

                // Small delay between batches
                sleep(1);
            }
        }
    }

    \WP_CLI::add_command('blitzcdn', 'BlitzCDN\CLI');
}
