<?php
require_once __DIR__ . '/vendor/autoload.php';

// Load .env
$env_path = __DIR__ . '/.env';
if (file_exists($env_path)) {
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(sprintf('%s=%s', trim($name), trim($value)));
    }
}

use Appwrite\Client;
use Appwrite\Services\Databases;

$project_id = getenv('APPWRITE_PROJECT_ID');
$api_key = getenv('APPWRITE_API_KEY');
$endpoint = getenv('APPWRITE_ENDPOINT');
$db_id = getenv('APPWRITE_DB_ID');
$collection_id = getenv('APPWRITE_COLLECTION_ID');

echo "Config:\n";
echo "Endpoint: $endpoint\n";
echo "Project: $project_id\n";
echo "DB ID: $db_id\n";
echo "Collection ID: $collection_id\n";

if (!$project_id || !$api_key || !$db_id || !$collection_id) {
    echo "Missing configuration.\n";
    exit(1);
}

$client = new Client();
$client
    ->setEndpoint($endpoint)
    ->setProject($project_id)
    ->setKey($api_key);

$databases = new Databases($client);

$data = [
    'email' => 'debug@example.com',
    'fileId' => 'debug-file-id',
    'originalUrl' => 'http://example.com/image.jpg',
    'createdAt' => date('c')
];

echo "Creating document...\n";

try {
    $result = $databases->createDocument(
        $db_id,
        $collection_id,
        'unique()',
        $data
    );
    echo "Success! ID: " . $result['$id'] . "\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    if (strpos($e->getMessage(), 'Attribute not found') !== false) {
        echo "\nPOSSIBLE CAUSE: You haven't created the attributes in the Appwrite Collection.\n";
        echo "Please create the following attributes in Collection '$collection_id':\n";
        echo "- email (string)\n";
        echo "- fileId (string)\n";
        echo "- originalUrl (string)\n";
        echo "- createdAt (string)\n";
    }
}
