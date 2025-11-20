<?php

namespace BlitzCDN;

class Compatibility {

    public function __construct() {
        add_action('blitzcdn_upload_complete', [$this, 'clear_elementor_cache']);
    }

    public function clear_elementor_cache($attachment_id) {
        if (class_exists('\Elementor\Plugin')) {
            // Check if files_manager exists and clear_cache method exists
            $instance = \Elementor\Plugin::instance();
            if (isset($instance->files_manager) && method_exists($instance->files_manager, 'clear_cache')) {
                $instance->files_manager->clear_cache();
            }
        }
    }
}
