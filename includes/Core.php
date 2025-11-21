<?php

namespace BlitzCDN;

use BlitzCDN\Admin\Settings;
use BlitzCDN\Admin\Migrator;

class Core {

    private static $instance = null;
    private $appwrite_client;
    private $upload_handler;
    private $url_rewriter;
    private $compatibility;
    private $background_migrator;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_components();
    }

    private function init_components() {
        // Initialize Appwrite Client
        $this->appwrite_client = new AppwriteClient();

        // Initialize Upload Handler
        $this->upload_handler = new UploadHandler($this->appwrite_client);

        // Initialize URL Rewriter
        $this->url_rewriter = new UrlRewriter();

        // Initialize Compatibility
        $this->compatibility = new Compatibility();

        // Initialize Background Migrator
        $this->background_migrator = new BackgroundMigrator();

        // Initialize Admin Components
        if (is_admin()) {
            new Settings();
            new Migrator($this->appwrite_client);
        }
    }

    public static function activate() {
        // Set default options
        if (false === get_option('blitzcdn_settings')) {
            update_option('blitzcdn_settings', [
                'project_id' => '',
                'api_key' => '',
                'bucket_id' => '',
                'cdn_domain' => '',
                'endpoint' => 'https://cloud.appwrite.io/v1',
                'serve_from_cdn' => false,
                'safe_delete' => false,
                'delete_remote' => false,
            ]);
        }
    }

    public static function deactivate() {
        // Do not delete metadata or options on deactivate
    }

    public function get_upload_handler() {
        return $this->upload_handler;
    }

    public function get_background_migrator() {
        return $this->background_migrator;
    }
}
