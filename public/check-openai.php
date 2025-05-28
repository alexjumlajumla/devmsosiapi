<?php
/**
 * OpenAI API configuration check and fix
 * Access via https://mapi.jumlajumla.com/check-openai.php
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

// Get the OpenAI API key from environment
$apiKey = getenv('OPENAI_API_KEY');
$apiModel = getenv('OPENAI_MODEL') ?: 'gpt-3.5-turbo';
$apiTemp = getenv('OPENAI_TEMPERATURE') ?: '0.7';

// Check config values from Laravel config
require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$configKey = config('services.openai.api_key');
$configModel = config('services.openai.model');
$configTemp = config('services.openai.temperature');

// Information array
$info = [
    'env_vars' => [
        'OPENAI_API_KEY' => $apiKey ? substr($apiKey, 0, 3) . '...' . substr($apiKey, -4) : '(not set)',
        'OPENAI_MODEL' => $apiModel,
        'OPENAI_TEMPERATURE' => $apiTemp
    ],
    'config_values' => [
        'services.openai.api_key' => $configKey ? substr($configKey, 0, 3) . '...' . substr($configKey, -4) : '(not set)',
        'services.openai.model' => $configModel,
        'services.openai.temperature' => $configTemp
    ],
    'test_status' => null,
    'test_message' => null
];

// Test the OpenAI API connection
if (!empty($configKey)) {
    try {
        $openai = new \Orhanerday\OpenAi\OpenAi($configKey);
        
        // Simple test completion
        $response = $openai->chat([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => 'Test connection with a short response']
            ],
            'temperature' => 0.7,
            'max_tokens' => 10
        ]);
        
        $decoded = json_decode($response, true);
        
        if (isset($decoded['error'])) {
            $info['test_status'] = 'error';
            $info['test_message'] = $decoded['error']['message'] ?? 'Unknown error';
        } else {
            $content = $decoded['choices'][0]['message']['content'] ?? 'No content';
            $info['test_status'] = 'success';
            $info['test_message'] = "API response: \"$content\"";
        }
    } catch (\Exception $e) {
        $info['test_status'] = 'error';
        $info['test_message'] = "Exception: " . $e->getMessage();
    }
} else {
    $info['test_status'] = 'error';
    $info['test_message'] = "API key not available in config";
}

// Output as HTML
?>
<!DOCTYPE html>
<html>
<head>
    <title>OpenAI API Check</title>
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
    <h1>OpenAI API Configuration Check</h1>
    
    <h2>Environment Variables</h2>
    <table>
        <tr><th>Name</th><th>Value</th></tr>
        <?php foreach ($info['env_vars'] as $key => $value): ?>
        <tr>
            <td><?php echo htmlspecialchars($key); ?></td>
            <td><?php echo htmlspecialchars($value); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>Laravel Config Values</h2>
    <table>
        <tr><th>Name</th><th>Value</th></tr>
        <?php foreach ($info['config_values'] as $key => $value): ?>
        <tr>
            <td><?php echo htmlspecialchars($key); ?></td>
            <td><?php echo htmlspecialchars($value); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    
    <h2>API Test Result</h2>
    <?php if ($info['test_status'] === 'success'): ?>
    <p class="success">Success! OpenAI API is working correctly.</p>
    <p><?php echo htmlspecialchars($info['test_message']); ?></p>
    <?php else: ?>
    <p class="error">Error: OpenAI API test failed.</p>
    <p><?php echo htmlspecialchars($info['test_message']); ?></p>
    <?php endif; ?>
    
    <h2>How to Fix Common Issues</h2>
    <ol>
        <li>Make sure your <code>.env.local</code> file contains a valid OpenAI API key:
            <pre>OPENAI_API_KEY=sk-xxxxxxxxxxxxxxxxxxxxxxxx</pre>
        </li>
        <li>Ensure your API key is active and has not been revoked or expired</li>
        <li>Check that your OpenAI account has billing set up and is in good standing</li>
        <li>After making any changes to environment files, restart the PHP-FPM service or web server</li>
    </ol>
    
    <h2>Next Steps</h2>
    <p>After fixing any issues, please:</p>
    <ol>
        <li>Reload this page to verify the API key is working</li>
        <li>Try the <a href="https://mapi.jumlajumla.com/voice-test/index.html" target="_blank">Voice Test page</a> again</li>
    </ol>
</body>
</html> 