<?php

namespace BlitzCDN;

use WP_Background_Process;

class BackgroundProcess extends WP_Background_Process {

    /**
     * @var string
     */
    protected $action = 'migrate_all_attachments';

    /**
     * @var string
     */
    protected $prefix = 'blitzcdn';

    /**
     * Task
     *
     * Override this method to perform any actions required on each
     * queue item. Return the modified item for further processing
     * in the next pass through. Or, return false to remove the
     * item from the queue.
     *
     * @param mixed $item Queue item to iterate over
     *
     * @return mixed
     */
    protected function task($item) {
        $attachment_id = $item;
        $upload_handler = Core::get_instance()->get_upload_handler();

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!$metadata) {
            return false; // Remove from queue
        }

        try {
            $upload_handler->handle_upload_phase_2($metadata, $attachment_id);
            error_log("BlitzCDN Background Migration: Successfully migrated attachment ID $attachment_id");
        } catch (\Exception $e) {
            error_log("BlitzCDN Background Migration: Failed to migrate attachment ID $attachment_id. Error: " . $e->getMessage());
        }

        return false; // Remove from queue
    }

    /**
     * Complete
     *
     * Override if applicable, but ensure that the below actions are
     * performed, or, call parent::complete().
     */
    protected function complete() {
        parent::complete();
        error_log('BlitzCDN Background Migration: All tasks completed.');
    }
}
