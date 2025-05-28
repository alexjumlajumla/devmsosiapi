<?php
/**
 * Direct Google Cloud credentials check
 * This script checks if the Google Cloud credentials file exists and is valid
 */

// Disable error reporting for security
error_reporting(0);

// Functions to format output
function success($message) {
    return json_encode([
        'status' => 'success',
        'message' => $message
    ]);
}

function error($message, $details = null) {
    $response = [
        'status' => 'error',
        'message' => $message
    ];
    
    if ($details) {
        $response['details'] = $details;
    }
    
    return json_encode($response);
}

// Set content type to JSON
header('Content-Type: application/json');

// Get the credentials path from environment
$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');

// Check if the environment variable is set
if (empty($credentialsPath)) {
    echo error('GOOGLE_APPLICATION_CREDENTIALS environment variable is not set');
    exit;
}

// Check if the file exists
if (!file_exists($credentialsPath)) {
    echo error('Credentials file not found', [
        'path' => $credentialsPath,
        'directory_exists' => is_dir(dirname($credentialsPath)) ? 'Yes' : 'No',
        'parent_writable' => is_writable(dirname($credentialsPath)) ? 'Yes' : 'No'
    ]);
    exit;
}

// Check if the file is readable
if (!is_readable($credentialsPath)) {
    echo error('Credentials file is not readable', [
        'path' => $credentialsPath,
        'permissions' => substr(sprintf('%o', fileperms($credentialsPath)), -4),
        'owner' => function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($credentialsPath))['name'] : 'Unknown'
    ]);
    exit;
}

// Try to read and parse the file
try {
    $contents = file_get_contents($credentialsPath);
    $credentials = json_decode($contents, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo error('Invalid JSON in credentials file', [
            'json_error' => json_last_error_msg()
        ]);
        exit;
    }
    
    // Check required fields
    $requiredFields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($credentials[$field]) || empty($credentials[$field])) {
            $missingFields[] = $field;
        }
    }
    
    if (!empty($missingFields)) {
        echo error('Missing required fields in credentials file', [
            'missing_fields' => $missingFields
        ]);
        exit;
    }
    
    // Success - the file exists and appears valid
    echo success('Google Cloud credentials file is valid: ' . $credentials['project_id']);
    
} catch (Exception $e) {
    echo error('Error reading credentials file', [
        'error' => $e->getMessage()
    ]);
} 