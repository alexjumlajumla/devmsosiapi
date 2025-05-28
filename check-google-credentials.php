<?php
/**
 * Simple script to check Google Cloud credentials file
 * 
 * Run with: php check-google-credentials.php
 */

// Path to your Google Cloud credentials file
$credentialsPath = '/var/www/vhosts/jumlajumla.com/mapi.jumlajumla.com/storage/app/google-service-account.json';

echo "=== Google Cloud Credentials Check ===\n\n";

// Check if file exists
echo "Checking file: $credentialsPath\n";
if (!file_exists($credentialsPath)) {
    echo "ERROR: File does not exist.\n";
    echo "Make sure the file exists at the specified path.\n";
    exit(1);
}

echo "File exists.\n";

// Check if file is readable
if (!is_readable($credentialsPath)) {
    echo "ERROR: File is not readable.\n";
    echo "Current permissions: " . substr(sprintf('%o', fileperms($credentialsPath)), -4) . "\n";
    echo "Current owner: " . posix_getpwuid(fileowner($credentialsPath))['name'] . "\n";
    echo "Current process user: " . posix_getpwuid(posix_geteuid())['name'] . "\n";
    echo "Try running: chmod 644 $credentialsPath\n";
    exit(1);
}

echo "File is readable.\n";

// Check file size
$size = filesize($credentialsPath);
echo "File size: $size bytes\n";

if ($size < 100) {
    echo "WARNING: File size is unusually small for a credentials file.\n";
}

// Read file contents
$contents = file_get_contents($credentialsPath);
if ($contents === false) {
    echo "ERROR: Could not read file contents.\n";
    exit(1);
}

echo "File contents read successfully.\n";

// Parse JSON
$credentials = json_decode($contents, true);
if ($credentials === null) {
    echo "ERROR: Invalid JSON format. Error: " . json_last_error_msg() . "\n";
    echo "File preview: " . substr($contents, 0, 100) . "...\n";
    exit(1);
}

echo "JSON parsed successfully.\n";

// Check required fields
$requiredFields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email'];
$missingFields = [];

foreach ($requiredFields as $field) {
    if (!isset($credentials[$field]) || empty($credentials[$field])) {
        $missingFields[] = $field;
    }
}

if (!empty($missingFields)) {
    echo "ERROR: Missing required fields: " . implode(', ', $missingFields) . "\n";
    echo "Available fields: " . implode(', ', array_keys($credentials)) . "\n";
    exit(1);
}

echo "All required fields present.\n";
echo "Project ID: " . $credentials['project_id'] . "\n";
echo "Client Email: " . $credentials['client_email'] . "\n";
echo "Type: " . $credentials['type'] . "\n";

echo "\nSuccess! The credentials file appears to be valid.\n";
echo "Next steps:\n";
echo "1. Make sure the Speech-to-Text API is enabled for this project.\n";
echo "2. Ensure the service account has the necessary permissions.\n";
echo "3. Set GOOGLE_APPLICATION_CREDENTIALS in your .env or .env.local file to: $credentialsPath\n";
echo "4. Set GOOGLE_CLOUD_PROJECT_ID in your .env or .env.local file to: " . $credentials['project_id'] . "\n"; 