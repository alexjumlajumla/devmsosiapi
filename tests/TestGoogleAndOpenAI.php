<?php

// This is a standalone script to test Google Cloud and OpenAI credentials
// Run with: php tests/TestGoogleAndOpenAI.php

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

echo "======= TESTING CREDENTIALS =======\n\n";

// Test Google Cloud credentials
echo "Testing Google Cloud credentials...\n";
try {
    $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    echo "GOOGLE_APPLICATION_CREDENTIALS path: $credentialsPath\n";
    
    if (!file_exists($credentialsPath)) {
        echo "⚠️ WARNING: Credentials file does not exist at the specified path\n";
    } else {
        echo "✓ Credentials file exists\n";
        
        $fileContent = file_get_contents($credentialsPath);
        $json = json_decode($fileContent, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "⚠️ WARNING: Credentials file is not valid JSON\n";
        } else {
            echo "✓ Credentials file contains valid JSON\n";
            
            if (isset($json['type']) && $json['type'] === 'service_account') {
                echo "✓ Credentials file contains service account information\n";
                echo "  Project ID: " . ($json['project_id'] ?? 'Not found') . "\n";
                echo "  Client email: " . ($json['client_email'] ?? 'Not found') . "\n";
            } else {
                echo "⚠️ WARNING: Credentials file does not appear to be a valid service account key\n";
            }
        }
    }
    
    // Try to initialize Google Cloud Speech client
    echo "\nAttempting to initialize Google Cloud Speech client...\n";
    $speechClient = new Google\Cloud\Speech\V1\SpeechClient();
    echo "✓ Successfully initialized Google Cloud Speech client\n";
    $speechClient->close();
    
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

// Test OpenAI credentials
echo "\nTesting OpenAI credentials...\n";
try {
    $apiKey = getenv('OPENAI_API_KEY');
    
    if (empty($apiKey)) {
        echo "⚠️ WARNING: OPENAI_API_KEY environment variable is not set\n";
    } else {
        echo "✓ OPENAI_API_KEY is set\n";
        echo "  Key preview: " . substr($apiKey, 0, 5) . "..." . substr($apiKey, -5) . "\n";
        
        // Initialize OpenAI client
        echo "\nAttempting to connect to OpenAI API...\n";
        $openAi = new \Orhanerday\OpenAi\OpenAi($apiKey);
        
        $response = $openAi->chat([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a test system. Respond with "OpenAI connection successful."',
                ],
                [
                    'role' => 'user',
                    'content' => 'Test connection',
                ],
            ],
            'max_tokens' => 10
        ]);
        
        $decoded = json_decode($response, true);
        
        if (isset($decoded['error'])) {
            echo "❌ ERROR: " . $decoded['error']['message'] . "\n";
        } else {
            $content = $decoded['choices'][0]['message']['content'] ?? 'No content';
            echo "✓ Received response: \"$content\"\n";
        }
    }
} catch (\Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n======= TEST COMPLETE =======\n"; 