<?php

namespace BlitzCDN;

use Appwrite\Client;
use Appwrite\Services\Storage;
use Appwrite\Services\Databases;
use Appwrite\Query;
use Appwrite\InputFile;

class AppwriteClient {

    private $client;
    private $storage;
    private $databases;
    private $project_id;
    private $bucket_id;
    private $db_id;
    private $collection_id;
    private $endpoint;
    private $cdn_domain;
    private $is_configured = false;

    public function __construct() {
        $this->load_env();
        
        $this->project_id = getenv('APPWRITE_PROJECT_ID') ?: ($_ENV['APPWRITE_PROJECT_ID'] ?? '');
        $api_key = getenv('APPWRITE_API_KEY') ?: ($_ENV['APPWRITE_API_KEY'] ?? '');
        $this->bucket_id = getenv('APPWRITE_BUCKET_ID') ?: ($_ENV['APPWRITE_BUCKET_ID'] ?? '');
        $this->db_id = getenv('APPWRITE_DB_ID') ?: ($_ENV['APPWRITE_DB_ID'] ?? '');
        $this->collection_id = getenv('APPWRITE_COLLECTION_ID') ?: ($_ENV['APPWRITE_COLLECTION_ID'] ?? '');
        $this->endpoint = getenv('APPWRITE_ENDPOINT') ?: ($_ENV['APPWRITE_ENDPOINT'] ?? 'https://cloud.appwrite.io/v1');
        $this->cdn_domain = getenv('APPWRITE_CDN_DOMAIN') ?: ($_ENV['APPWRITE_CDN_DOMAIN'] ?? '');

        if ($this->project_id && $api_key && $this->bucket_id) {
            $this->client = new Client();
            $this->client
                ->setEndpoint($this->endpoint)
                ->setProject($this->project_id)
                ->setKey($api_key);

            $this->storage = new Storage($this->client);
            $this->databases = new Databases($this->client);
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

    public function get_db_id() {
        return $this->db_id;
    }

    public function get_collection_id() {
        return $this->collection_id;
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

        // Validate file exists before attempting upload
        if (!file_exists($file_path)) {
            error_log('BlitzCDN Upload Error: File not found: ' . $file_path);
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

    /**
     * Download a file from Appwrite Storage.
     *
     * @param string $file_id The ID of the file to download.
     * @return string|false File content on success, false on failure.
     */
    public function download_file($file_id) {
        if (!$this->is_configured) {
            return false;
        }

        try {
            // getFileDownload returns the file content directly
            $content = $this->storage->getFileDownload($this->bucket_id, $file_id);
            return $content;
        } catch (\Throwable $e) {
            error_log('BlitzCDN Download Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a document in Appwrite Database.
     *
     * @param array $data Document data.
     * @return array|false Document object on success, false on failure.
     */
    public function create_document($data) {
        if (!$this->is_configured || !$this->db_id || !$this->collection_id) {
            return false;
        }

        try {
            return $this->databases->createDocument(
                $this->db_id,
                $this->collection_id,
                'unique()',
                $data
            );
        } catch (\Throwable $e) {
            error_log('BlitzCDN: Failed to create document: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * List documents by email query with pagination support.
     *
     * @param string $email The email to filter by.
     * @return array|false Array of documents on success, false on failure.
     */
    public function list_documents_by_email($email) {
        if (!$this->is_configured || !$this->db_id || !$this->collection_id) {
            return false;
        }

        try {
            $all_documents = [];
            $limit = 100; // Fetch in smaller batches for better performance
            $offset = 0;
            $has_more = true;

            while ($has_more) {
                $response = $this->databases->listDocuments(
                    $this->db_id,
                    $this->collection_id,
                    [
                        \Appwrite\Query::equal('email', [$email]),
                        \Appwrite\Query::limit($limit),
                        \Appwrite\Query::offset($offset)
                    ]
                );

                $documents = $response['documents'] ?? [];
                $all_documents = array_merge($all_documents, $documents);

                // Check if there are more documents to fetch
                if (count($documents) < $limit) {
                    $has_more = false;
                } else {
                    $offset += $limit;
                    
                    // Safety check: prevent infinite loops if API misbehaves
                    if ($offset > 50000) {
                        error_log('BlitzCDN: Safety limit reached while fetching documents for email: ' . $email);
                        break;
                    }
                }
            }

            return $all_documents;
        } catch (\Throwable $e) {
            error_log('BlitzCDN: Failed to list documents: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get file metadata from Appwrite Storage.
     *
     * @param string $file_id The ID of the file.
     * @return array|false File metadata on success, false on failure.
     */
    public function get_file_metadata($file_id) {
        if (!$this->is_configured) {
            return false;
        }

        try {
            return $this->storage->getFile($this->bucket_id, $file_id);
        } catch (\Throwable $e) {
            error_log('BlitzCDN: Failed to get file metadata: ' . $e->getMessage());
            return false;
        }
    }
}
