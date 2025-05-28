<?php
/**
 * Find and copy Google Cloud credentials file to expected locations
 */

// Show errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "=== Google Cloud Credentials Copy Tool ===\n\n";

// Define source paths to check
$sourcePaths = [
    __DIR__ . '/jumlajumla-1f0f0-98ab02854aef.json',
    __DIR__ . '/google-credentials.json',
    __DIR__ . '/storage/app/jumlajumla-1f0f0-98ab02854aef.json',
    __DIR__ . '/storage/app/google-service-account.json',
    getenv('GOOGLE_APPLICATION_CREDENTIALS'),
];

// Define target paths to copy to
$targetPaths = [
    __DIR__ . '/storage/app/google-service-account.json',
    __DIR__ . '/storage/app/jumlajumla-1f0f0-98ab02854aef.json',
];

// Find the first existing source file
$sourceFile = null;
foreach ($sourcePaths as $path) {
    if (!empty($path) && file_exists($path) && is_readable($path)) {
        echo "Found credentials file at: $path\n";
        $sourceFile = $path;
        break;
    }
}

if (!$sourceFile) {
    echo "ERROR: Could not find any Google Cloud credentials file!\n";
    echo "Please place your 'jumlajumla-1f0f0-98ab02854aef.json' file in the project root directory.\n";
    exit(1);
}

// Check if it's a valid JSON file
$content = file_get_contents($sourceFile);
$json = json_decode($content);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "ERROR: The file at $sourceFile is not valid JSON!\n";
    echo "JSON error: " . json_last_error_msg() . "\n";
    exit(1);
}

// Ensure the storage directory exists
if (!is_dir(__DIR__ . '/storage/app')) {
    echo "Creating directory: " . __DIR__ . "/storage/app\n";
    mkdir(__DIR__ . '/storage/app', 0755, true);
}

// Copy to all target locations
$success = false;
foreach ($targetPaths as $targetPath) {
    echo "Copying to: $targetPath\n";
    if (copy($sourceFile, $targetPath)) {
        chmod($targetPath, 0644); // Ensure it's readable
        echo "SUCCESS: Copied credentials to $targetPath\n";
        $success = true;
    } else {
        echo "WARNING: Failed to copy to $targetPath\n";
    }
}

if ($success) {
    echo "\nCredentials have been successfully copied to at least one target location.\n";
    echo "Please try your voice transcription service again.\n";
} else {
    echo "\nERROR: Failed to copy the credentials file to any target location.\n";
    echo "Please check file permissions and try again.\n";
}

// Recommend updating .env.local
echo "\nRecommended .env.local settings:\n";
echo "GOOGLE_APPLICATION_CREDENTIALS=" . __DIR__ . "/storage/app/google-service-account.json\n"; 