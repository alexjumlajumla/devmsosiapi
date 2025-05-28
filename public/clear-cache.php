<?php
/**
 * Cache clearing utility
 * This script clears the Laravel cache to ensure configuration changes take effect
 */

// For security, require auth parameter
if (!isset($_GET['auth']) || $_GET['auth'] !== 'fixit') {
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Missing or invalid auth parameter.";
    exit;
}

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Required paths
$basePath = realpath(__DIR__ . '/..');
$artisanPath = $basePath . '/artisan';
$bootstrapPath = $basePath . '/bootstrap/cache';
$storagePath = $basePath . '/storage/framework';

// Results array
$results = [];

// Function to run artisan command
function runCommand($command) {
    $output = [];
    $returnCode = -1;
    
    try {
        $cmd = 'cd ' . escapeshellarg(dirname(__DIR__)) . ' && php artisan ' . $command . ' 2>&1';
        exec($cmd, $output, $returnCode);
        return [
            'success' => $returnCode === 0,
            'command' => $command,
            'output' => implode("\n", $output),
            'code' => $returnCode
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'command' => $command,
            'output' => $e->getMessage(),
            'code' => -1
        ];
    }
}

// Clear all caches
$cacheCommands = [
    'config:clear' => 'Clear configuration cache',
    'cache:clear' => 'Clear application cache',
    'view:clear' => 'Clear compiled views',
    'route:clear' => 'Clear route cache',
    'optimize:clear' => 'Clear all cached files'
];

foreach ($cacheCommands as $command => $description) {
    $results[$command] = runCommand($command);
    $results[$command]['description'] = $description;
}

// Check file permissions
$pathsToCheck = [
    $bootstrapPath => 'Bootstrap cache directory',
    $storagePath => 'Storage framework directory',
    $artisanPath => 'Artisan command script'
];

$permissions = [];
foreach ($pathsToCheck as $path => $description) {
    $permissions[$path] = [
        'path' => $path,
        'description' => $description,
        'exists' => file_exists($path),
        'writable' => is_writable($path),
        'permissions' => file_exists($path) ? substr(sprintf('%o', fileperms($path)), -4) : 'N/A'
    ];
}

// Output results
?>
<!DOCTYPE html>
<html>
<head>
    <title>Laravel Cache Clear</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1, h2 { color: #333; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #ddd; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Laravel Cache Clearing Utility</h1>
    
    <h2>Cache Clear Results</h2>
    <table>
        <tr>
            <th>Command</th>
            <th>Description</th>
            <th>Status</th>
            <th>Output</th>
        </tr>
        <?php foreach ($results as $command => $result): ?>
        <tr>
            <td><code><?php echo htmlspecialchars($command); ?></code></td>
            <td><?php echo htmlspecialchars($result['description']); ?></td>
            <td>
                <?php if ($result['success']): ?>
                <span class="success">Success</span>
                <?php else: ?>
                <span class="error">Failed (<?php echo $result['code']; ?>)</span>
                <?php endif; ?>
            </td>
            <td>
                <pre><?php echo htmlspecialchars($result['output']); ?></pre>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>File Permissions</h2>
    <table>
        <tr>
            <th>Path</th>
            <th>Description</th>
            <th>Exists</th>
            <th>Writable</th>
            <th>Permissions</th>
        </tr>
        <?php foreach ($permissions as $path => $info): ?>
        <tr>
            <td><code><?php echo htmlspecialchars($path); ?></code></td>
            <td><?php echo htmlspecialchars($info['description']); ?></td>
            <td>
                <?php if ($info['exists']): ?>
                <span class="success">Yes</span>
                <?php else: ?>
                <span class="error">No</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($info['writable']): ?>
                <span class="success">Yes</span>
                <?php else: ?>
                <span class="error">No</span>
                <?php endif; ?>
            </td>
            <td><?php echo htmlspecialchars($info['permissions']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>Next Steps</h2>
    <p>Now that the cache has been cleared:</p>
    <ol>
        <li>Try reloading the <a href="https://mapi.jumlajumla.com/voice-test/index.html" target="_blank">Voice Test page</a></li>
        <li>If issues persist, check the <a href="https://mapi.jumlajumla.com/fix-credentials.php?auth=fixit" target="_blank">Google credentials</a> and <a href="https://mapi.jumlajumla.com/check-openai.php?auth=fixit" target="_blank">OpenAI configuration</a></li>
        <li>You may need to restart PHP-FPM or the web server for changes to take full effect</li>
    </ol>
</body>
</html> 