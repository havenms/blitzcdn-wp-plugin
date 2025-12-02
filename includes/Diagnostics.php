<?php

namespace BlitzCDN;

class Diagnostics {
    
    /**
     * Get diagnostic information about Action Scheduler setup
     */
    public static function get_action_scheduler_diagnostics() {
        $diagnostics = [
            'action_scheduler_loaded' => class_exists('ActionScheduler', false),
            'as_schedule_single_action_exists' => function_exists('as_schedule_single_action'),
            'as_unschedule_all_actions_exists' => function_exists('as_unschedule_all_actions'),
            'action_scheduler_init_exists' => function_exists('action_scheduler_init'),
            'action_scheduler_run_hook_exists' => has_action('action_scheduler_run'),
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'alternate_wp_cron_enabled' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
        ];
        
        return $diagnostics;
    }

    /**
     * Get information about pending actions
     */
    public static function get_pending_actions() {
        if (!function_exists('as_get_scheduled_actions')) {
            return ['error' => 'Action Scheduler functions not available'];
        }

        try {
            // Check if ActionScheduler_Store class exists before using it
            if (!class_exists('ActionScheduler_Store')) {
                return ['error' => 'ActionScheduler_Store not available'];
            }

            $actions = as_get_scheduled_actions([
                'hook' => BackgroundMigrator::ACTION_HOOK,
                'status' => ActionScheduler_Store::STATUS_PENDING
            ]);

            return [
                'pending_count' => count($actions),
                'actions' => array_map(function($action) {
                    return [
                        'id' => $action->get_id(),
                        'hook' => $action->get_hook(),
                        'scheduled' => $action->get_schedule()->get_timestamp(),
                        'args' => $action->get_args(),
                    ];
                }, $actions)
            ];
        } catch (\Exception $e) {
            return ['error' => 'Failed to get pending actions: ' . $e->getMessage()];
        }
    }

    /**
     * Get WordPress cron diagnostics
     */
    public static function get_wordpress_cron_diagnostics() {
        $diagnostics = [
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'alternate_wp_cron' => defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            'loopback_test' => self::test_loopback_request(),
        ];

        // Check if any crons are scheduled
        if (function_exists('_get_cron_array')) {
            $crons = _get_cron_array();
            $diagnostics['scheduled_crons'] = is_array($crons) ? count($crons) : 0;
        } else {
            $diagnostics['scheduled_crons'] = 'Unknown';
        }
        
        return $diagnostics;
    }

    /**
     * Test if the site can make loopback requests to itself
     */
    private static function test_loopback_request() {
        $response = wp_remote_get(admin_url('admin-ajax.php'), [
            'blocking' => true,
            'sslverify' => false,
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message()
            ];
        }

        return [
            'success' => true,
            'status_code' => wp_remote_retrieve_response_code($response)
        ];
    }

    /**
     * Get migration status
     */
    public static function get_migration_status() {
        try {
            $status = get_option(BackgroundMigrator::OPTION_STATUS, ['status' => 'idle']);
            $status['pending_actions'] = self::get_pending_actions();
            return $status;
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => 'Failed to get migration status: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate full diagnostic report
     */
    public static function generate_report() {
        return [
            'action_scheduler' => self::get_action_scheduler_diagnostics(),
            'wordpress_cron' => self::get_wordpress_cron_diagnostics(),
            'migration' => self::get_migration_status(),
            'site_url' => site_url(),
            'admin_url' => admin_url(),
            'wp_version' => $GLOBALS['wp_version'],
            'php_version' => phpversion(),
            'wordpress_debug' => defined('WP_DEBUG') && WP_DEBUG,
        ];
    }
}
