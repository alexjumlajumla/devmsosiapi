<?php

// Load Composer autoloader
require __DIR__ . '/vendor/autoload.php';

// Load dotenv
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__, ['.env', '.env.local']);
$dotenv->safeLoad();

// Get API key from environment
$apiKey = $_ENV['OPENAI_API_KEY'] ?? null;

if (!$apiKey) {
    echo "ERROR: No OpenAI API key found in .env or .env.local files\n";
    exit(1);
}

echo "Using API key: " . substr($apiKey, 0, 7) . "...\n\n";

// Test function
function testOpenAI($apiKey, $model, $prompt) {
    echo "Testing with model: $model\n";
    echo "Prompt: $prompt\n";
    
    $ch = curl_init();
    
    // Always use the chat completions endpoint for recent OpenAI models
    $url = 'https://api.openai.com/v1/chat/completions';
    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 50,
        'temperature' => 0.7
    ];
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        echo "CURL Error: " . curl_error($ch) . "\n";
    }
    
    curl_close($ch);
    
    echo "HTTP Status Code: $httpCode\n";
    
    $response = json_decode($result, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "JSON decode error: " . json_last_error_msg() . "\n";
        echo "Raw response: " . $result . "\n";
    } else {
        if (isset($response['error'])) {
            echo "API Error: " . $response['error']['message'] . "\n";
            echo "Error type: " . $response['error']['type'] . "\n";
        } else {
            echo "Success! Response:\n";
            // Chat completion response format
            echo $response['choices'][0]['message']['content'] . "\n";
        }
    }
    
    echo "\n----------------------------\n\n";
}

// Now let's also test using the Orhanerday package
function testOpenAIPackage($apiKey, $model, $prompt) {
    echo "Testing with package, model: $model\n";
    echo "Prompt: $prompt\n";
    
    try {
        $openAi = new Orhanerday\OpenAi\OpenAi($apiKey);
        
        $response = $openAi->chat([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful assistant.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 50,
        ]);
        
        $decoded = json_decode($response, true);
        
        if (isset($decoded['error'])) {
            echo "API Error: " . $decoded['error']['message'] . "\n";
            echo "Error type: " . ($decoded['error']['type'] ?? 'unknown') . "\n";
        } else {
            echo "Success! Response:\n";
            echo $decoded['choices'][0]['message']['content'] . "\n";
        }
        
    } catch (\Exception $e) {
        echo "Package Exception: " . $e->getMessage() . "\n";
    }
    
    echo "\n----------------------------\n\n";
}

// Test the OpenAI models
$testCases = [
    ['gpt-3.5-turbo', 'Say hello in one word.'],
];

echo "Testing direct API calls:\n";
foreach ($testCases as [$model, $prompt]) {
    testOpenAI($apiKey, $model, $prompt);
}

echo "\nTesting via package:\n";
foreach ($testCases as [$model, $prompt]) {
    testOpenAIPackage($apiKey, $model, $prompt);
}

echo "Tests completed.\n"; 