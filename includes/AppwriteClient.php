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
    private $cdn_domain;
    private $is_configured = false;

    public function __construct() {
        $this->load_env();
        
        $this->project_id = getenv('APPWRITE_PROJECT_ID') ?: ($_ENV['APPWRITE_PROJECT_ID'] ?? '');
        $api_key = getenv('APPWRITE_API_KEY') ?: ($_ENV['APPWRITE_API_KEY'] ?? '');
        $this->bucket_id = getenv('APPWRITE_BUCKET_ID') ?: ($_ENV['APPWRITE_BUCKET_ID'] ?? '');
        $this->endpoint = getenv('APPWRITE_ENDPOINT') ?: ($_ENV['APPWRITE_ENDPOINT'] ?? 'https://cloud.appwrite.io/v1');
        $this->cdn_domain = getenv('APPWRITE_CDN_DOMAIN') ?: ($_ENV['APPWRITE_CDN_DOMAIN'] ?? '');

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

    private function load_env() {
        $env_path = dirname(__DIR__) . '/.env';
        if (file_exists($env_path)) {
            $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                
                if (!getenv($name)) {
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
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

    public function get_cdn_domain() {
        return $this->cdn_domain;
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
