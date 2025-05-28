<?php
/**
 * Simple script to directly test Google Cloud credentials
 * Run with: php test-credentials.php
 */

// Set environment variables for the current process
putenv('GOOGLE_APPLICATION_CREDENTIALS=/var/www/vhosts/jumlajumla.com/mapi.jumlajumla.com/storage/app/jumlajumla-1f0f0-98ab02854aef.json');
putenv('GOOGLE_CLOUD_PROJECT_ID=jumlajumla-1f0f0');

// Path to credentials file
$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
echo "Testing Google Cloud credentials at path: $credentialsPath\n\n";

// Basic checks
if (!file_exists($credentialsPath)) {
    echo "ERROR: Credentials file does not exist at path: $credentialsPath\n";
    exit(1);
}

// Check file permissions
echo "File permissions: " . substr(sprintf('%o', fileperms($credentialsPath)), -4) . "\n";
$processUser = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : 'unknown';
$fileOwner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($credentialsPath))['name'] : 'unknown';
echo "File owner: $fileOwner\n";
echo "Process running as user: $processUser\n";

// Read and parse credentials file
try {
    $credentialsJson = file_get_contents($credentialsPath);
    $credentials = json_decode($credentialsJson, true);
    
    if ($credentials === null) {
        echo "ERROR: Failed to parse JSON: " . json_last_error_msg() . "\n";
        exit(1);
    }
    
    // Extract and show key info (redacted for security)
    echo "Credentials loaded successfully.\n";
    echo "Project ID: " . ($credentials['project_id'] ?? 'N/A') . "\n";
    echo "Client Email: " . ($credentials['client_email'] ?? 'N/A') . "\n";
    echo "Auth URI: " . ($credentials['auth_uri'] ?? 'N/A') . "\n";
    
    // Check for required fields
    $requiredFields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($credentials[$field]) || empty($credentials[$field])) {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        echo "WARNING: Missing required fields: " . implode(', ', $missingFields) . "\n";
    } else {
        echo "All required fields present.\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: Exception when reading credentials: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";
echo "====== Next Steps ======\n";
echo "1. Add these lines to your .env.local file:\n\n";
echo "GOOGLE_APPLICATION_CREDENTIALS=$credentialsPath\n";
echo "GOOGLE_CLOUD_PROJECT_ID=" . ($credentials['project_id'] ?? 'jumlajumla-1f0f0') . "\n\n";
echo "2. Make sure the Speech-to-Text API is enabled in your Google Cloud project\n";
echo "3. Restart your web server to reload environment variables\n";
echo "4. Try the Test Credentials button again\n";
echo "=======================\n"; 