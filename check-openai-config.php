<?php

// Simple script to test OpenAI API key setup
echo "OpenAI API Key Checker\n";
echo "=====================\n\n";

// 1. Get OpenAI API key from .env file if it exists
$envFile = file_exists('.env') ? '.env' : (file_exists('.env.local') ? '.env.local' : null);

if (!$envFile) {
    echo "❌ No .env or .env.local file found!\n";
    exit(1);
}

echo "✅ Found environment file: $envFile\n";

// Parse .env file to find OPENAI_API_KEY
$apiKey = null;
$envContent = file_get_contents($envFile);
$lines = explode("\n", $envContent);

foreach ($lines as $line) {
    if (strpos($line, 'OPENAI_API_KEY=') === 0) {
        $apiKey = trim(substr($line, strlen('OPENAI_API_KEY=')));
        // Remove any quotes if present
        $apiKey = trim($apiKey, '"\'');
        break;
    }
}

if (empty($apiKey)) {
    echo "❌ OPENAI_API_KEY not found in $envFile\n";
    echo "Please add OPENAI_API_KEY=your_api_key to your $envFile file\n";
    exit(1);
}

// Check key format
if (!preg_match('/^(sk-|sk-org-)/', $apiKey)) {
    echo "❌ Invalid API key format. OpenAI keys should start with 'sk-' or 'sk-org-'\n";
    echo "Current key format: " . substr($apiKey, 0, 5) . "...\n";
    exit(1);
}

echo "✅ Found OpenAI API key: " . substr($apiKey, 0, 5) . "...\n";

// Check if orhanerday/open-ai is installed
if (!file_exists('vendor/orhanerday/open-ai/src/OpenAi.php')) {
    echo "❌ orhanerday/open-ai library not found!\n";
    echo "Run 'composer require orhanerday/open-ai' to install it\n";
    exit(1);
}

echo "✅ orhanerday/open-ai library found\n";

// Test the API key with a simple request
echo "\n🔄 Testing API key with OpenAI...\n";

require 'vendor/autoload.php';
use Orhanerday\OpenAi\OpenAi;

try {
    $openAi = new OpenAi($apiKey);
    
    // Use chat completion API with the new package format
    $response = $openAi->chat([
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are a helpful assistant.'
            ],
            [
                'role' => 'user',
                'content' => 'Test connection with a short response'
            ]
        ],
        'temperature' => 0.7,
        'max_tokens' => 10
    ]);
    
    $decoded = json_decode($response, true);
    
    if (isset($decoded['error'])) {
        echo "❌ API Error: " . $decoded['error']['message'] . "\n";
        echo "Error Type: " . ($decoded['error']['type'] ?? 'unknown') . "\n";
        exit(1);
    }
    
    if (isset($decoded['choices']) && is_array($decoded['choices']) && count($decoded['choices']) > 0) {
        $model = $decoded['model'] ?? 'unknown';
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        
        echo "✅ API connection successful!\n";
        echo "Model: $model\n";
        echo "Response: $content\n";
    } else {
        echo "❌ Unexpected response format from OpenAI\n";
        echo "Response: " . print_r($decoded, true) . "\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ Exception: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ OpenAI API configuration looks good!\n"; 