<?php

namespace BlitzCDN;

use Appwrite\Client;
use Appwrite\Services\Storage;
use Appwrite\InputFile;

class AppwriteClient {

    private $client;
    private $storage;
    private $project_id;
    private $bucket_id;
    private $endpoint;
    private $is_configured = false;

    public function __construct() {
        $settings = get_option('blitzcdn_settings', []);

        $this->project_id = $settings['project_id'] ?? '';
        $api_key = $settings['api_key'] ?? '';
        $this->bucket_id = $settings['bucket_id'] ?? '';
        $this->endpoint = $settings['endpoint'] ?? 'https://cloud.appwrite.io/v1';

        if ($this->project_id && $api_key && $this->bucket_id) {
            $this->client = new Client();
            $this->client
                ->setEndpoint($this->endpoint)
                ->setProject($this->project_id)
                ->setKey($api_key);

            $this->storage = new Storage($this->client);
            $this->is_configured = true;
        }
    }

    public function is_configured() {
        return $this->is_configured;
    }

    public function get_bucket_id() {
        return $this->bucket_id;
    }

    public function get_project_id() {
        return $this->project_id;
    }

    public function get_endpoint() {
        return $this->endpoint;
    }

    /**
     * Upload a file to Appwrite Storage.
     *
     * @param string $file_path Absolute path to the file.
     * @param string $file_name Name of the file.
     * @return string|false File ID on success, false on failure.
     */
    public function upload_file($file_path, $file_name) {
        if (!$this->is_configured) {
            return false;
        }

        try {
            $file = $this->storage->createFile(
                $this->bucket_id,
                'unique()',
                InputFile::withPath($file_path, $file_name)
            );
            return $file['$id'];
        } catch (\Throwable $e) {
            error_log('BlitzCDN Upload Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a file from Appwrite Storage.
     *
     * @param string $file_id The ID of the file to delete.
     * @return bool True on success, false on failure.
     */
    public function delete_file($file_id) {
        if (!$this->is_configured) {
            return false;
        }

        try {
            $this->storage->deleteFile($this->bucket_id, $file_id);
            return true;
        } catch (\Throwable $e) {
            error_log('BlitzCDN Delete Error: ' . $e->getMessage());
            return false;
        }
    }
}
