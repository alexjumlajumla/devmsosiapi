<?php
/**
 * Google Cloud credentials diagnostic and fix script
 * Access this via https://mapi.jumlajumla.com/fix-credentials.php
 */

// For security, require a specific query parameter
if (!isset($_GET['auth']) || $_GET['auth'] !== 'fixit') {
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Missing or invalid auth parameter.";
    exit;
}

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Function to handle file operations with proper error handling
function safeFileOperation($operation, $args) {
    try {
        return call_user_func_array($operation, $args);
    } catch (Exception $e) {
        return false;
    }
}

// Function to check if a file exists and is readable
function checkFile($path) {
    $exists = file_exists($path);
    $readable = $exists ? is_readable($path) : false;
    $size = $exists ? filesize($path) : 0;
    $perms = $exists ? substr(sprintf('%o', fileperms($path)), -4) : 'N/A';
    
    return [
        'path' => $path,
        'exists' => $exists,
        'readable' => $readable,
        'size' => $size,
        'permissions' => $perms
    ];
}

// Get the environment settings
$envVars = [
    'GOOGLE_APPLICATION_CREDENTIALS' => getenv('GOOGLE_APPLICATION_CREDENTIALS'),
    'GOOGLE_CLOUD_PROJECT_ID' => getenv('GOOGLE_CLOUD_PROJECT_ID'),
    'APP_ENV' => getenv('APP_ENV')
];

// Define possible credential paths
$possiblePaths = [
    getenv('GOOGLE_APPLICATION_CREDENTIALS'),
    // Standard paths
    __DIR__ . '/../storage/app/google-service-account.json',
    __DIR__ . '/../storage/app/jumlajumla-1f0f0-98ab02854aef.json',
    // Absolute paths that might be used
    '/var/www/vhosts/jumlajumla.com/mapi.jumlajumla.com/storage/app/google-service-account.json',
    '/var/www/vhosts/jumlajumla.com/mapi.jumlajumla.com/storage/app/jumlajumla-1f0f0-98ab02854aef.json'
];

// Check each path
$fileChecks = [];
foreach ($possiblePaths as $path) {
    if (!empty($path)) {
        $fileChecks[$path] = checkFile($path);
    }
}

// Identify a working credential file we can use as source
$sourceFile = null;
foreach ($fileChecks as $path => $check) {
    if ($check['exists'] && $check['readable'] && $check['size'] > 100) {
        $sourceFile = $path;
        break;
    }
}

// Create missing directories if needed
$storageAppDir = __DIR__ . '/../storage/app';
if (!file_exists($storageAppDir)) {
    mkdir($storageAppDir, 0755, true);
}

// Define the target locations
$targetFiles = [
    __DIR__ . '/../storage/app/google-service-account.json',
    __DIR__ . '/../storage/app/jumlajumla-1f0f0-98ab02854aef.json'
];

// If we have a source file, copy it to all target locations
$fixResults = [];
if ($sourceFile) {
    foreach ($targetFiles as $targetFile) {
        $result = [
            'source' => $sourceFile,
            'target' => $targetFile,
            'success' => false,
            'error' => null
        ];
        
        try {
            // Copy the file
            if (copy($sourceFile, $targetFile)) {
                // Set permissions
                chmod($targetFile, 0644);
                $result['success'] = true;
            } else {
                $result['error'] = 'Copy operation failed';
            }
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
        }
        
        $fixResults[] = $result;
    }
}

// Output results as HTML
?>
<!DOCTYPE html>
<html>
<head>
    <title>Google Cloud Credentials Fix</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2 { color: #333; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Google Cloud Credentials Diagnostic Tool</h1>
    
    <h2>Environment Variables</h2>
    <table>
        <tr><th>Name</th><th>Value</th></tr>
        <?php foreach ($envVars as $key => $value): ?>
        <tr>
            <td><?php echo htmlspecialchars($key); ?></td>
            <td><?php echo htmlspecialchars($value ?: '(not set)'); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>Credential Files</h2>
    <table>
        <tr>
            <th>Path</th>
            <th>Exists</th>
            <th>Readable</th>
            <th>Size</th>
            <th>Permissions</th>
        </tr>
        <?php foreach ($fileChecks as $path => $check): ?>
        <tr>
            <td><?php echo htmlspecialchars($path); ?></td>
            <td><?php echo $check['exists'] ? '<span class="success">Yes</span>' : '<span class="error">No</span>'; ?></td>
            <td><?php echo $check['readable'] ? '<span class="success">Yes</span>' : '<span class="error">No</span>'; ?></td>
            <td><?php echo $check['size']; ?> bytes</td>
            <td><?php echo $check['permissions']; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>Fix Attempts</h2>
    <?php if (empty($sourceFile)): ?>
    <p class="error">No valid source credential file found. Cannot perform fixes.</p>
    <?php else: ?>
    <p>Using source file: <code><?php echo htmlspecialchars($sourceFile); ?></code></p>
    <table>
        <tr>
            <th>Target</th>
            <th>Result</th>
            <th>Details</th>
        </tr>
        <?php foreach ($fixResults as $result): ?>
        <tr>
            <td><?php echo htmlspecialchars($result['target']); ?></td>
            <td><?php echo $result['success'] ? '<span class="success">Success</span>' : '<span class="error">Failed</span>'; ?></td>
            <td><?php echo htmlspecialchars($result['error'] ?: 'No errors'); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
    
    <h2>Next Steps</h2>
    <p>After running this fix, please:</p>
    <ol>
        <li>Refresh the <a href="https://mapi.jumlajumla.com/voice-test/index.html" target="_blank">Voice Test page</a> to see if the issue is resolved</li>
        <li>If not, check that your .env or .env.local file has the correct path set for GOOGLE_APPLICATION_CREDENTIALS</li>
        <li>Restart the web server or PHP-FPM service to reload environment variables</li>
    </ol>
</body>
</html> 