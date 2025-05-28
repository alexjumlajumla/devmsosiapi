<?php
/**
 * Simple script to test Google Cloud Speech-to-Text API
 * 
 * Make sure you have installed the required packages:
 * composer require google/cloud-speech
 * 
 * Run with: php test-speech.php
 */

// Set credentials path for this script
putenv('GOOGLE_APPLICATION_CREDENTIALS=/var/www/vhosts/jumlajumla.com/mapi.jumlajumla.com/storage/app/jumlajumla-1f0f0-98ab02854aef.json');

// Include composer autoloader (adjust path if needed)
require_once __DIR__ . '/vendor/autoload.php';

echo "=== Google Cloud Speech-to-Text API Test ===\n\n";

try {
    // Get the credentials file path
    $credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    echo "Using credentials from: $credentialsPath\n";
    
    // Load and check credentials file
    if (!file_exists($credentialsPath)) {
        echo "ERROR: Credentials file does not exist at path: $credentialsPath\n";
        exit(1);
    }
    
    $credentialsJson = file_get_contents($credentialsPath);
    $credentials = json_decode($credentialsJson, true);
    
    if ($credentials === null) {
        echo "ERROR: Failed to parse JSON: " . json_last_error_msg() . "\n";
        exit(1);
    }
    
    echo "Credentials loaded successfully.\n";
    echo "Project ID: " . ($credentials['project_id'] ?? 'N/A') . "\n";
    
    // Initialize the Speech client
    echo "Initializing Google Cloud Speech client...\n";
    
    // Create speech client with direct credentials
    $speech = new Google\Cloud\Speech\V1\SpeechClient([
        'credentials' => $credentials
    ]);
    
    // Define a sample audio content - just a simple test
    $content = file_get_contents(__DIR__ . '/test-audio.flac');
    if (!$content) {
        echo "Test audio file not found. Using base64 encoded test audio...\n";
        // Small base64 encoded test audio file (silence)
        $content = base64_decode("//NExAARqoIIAAhEuWAAABQBAAAAAJw/QCF/wIEA+oEAQgmMaX8ChQEI3QEz/APdAME8/6CGgf/NExAkPCUIAGDGKcAAAQPqB//tACkM0cQVIB9QQID18ByQwBAEMnxCIYjDYLQCEAlgJ//NExBQPWoIAAGGGcIf8QwBWwJf/WfAXwQ5KAGT5mX/0QgahWDTAFGxCIBjAwYHgCABAwf/8eQZ//NExCEOooIAAGGGcJgHEAChZf/8HQUNBwf//BqFh4IQhBIDhgfBnZNEHQRDJkOFAyQaEiEdTIYSA");
    } else {
        echo "Using test audio file...\n";
    }
    
    // Prepare the audio data
    $audio = new Google\Cloud\Speech\V1\RecognitionAudio();
    $audio->setContent($content);
    
    // Configure the recognition settings
    $config = new Google\Cloud\Speech\V1\RecognitionConfig();
    $config->setEncoding(Google\Cloud\Speech\V1\RecognitionConfig\AudioEncoding::FLAC);
    $config->setSampleRateHertz(16000);
    $config->setLanguageCode('en-US');
    
    // Make the API call
    echo "Sending request to Speech-to-Text API...\n";
    $response = $speech->recognize($config, $audio);
    
    // Process the results
    foreach ($response->getResults() as $result) {
        $alternatives = $result->getAlternatives();
        $mostLikely = $alternatives[0];
        $transcript = $mostLikely->getTranscript();
        $confidence = $mostLikely->getConfidence();
        
        echo "Transcript: \"$transcript\"\n";
        echo "Confidence: " . sprintf("%.2f%%", $confidence * 100) . "\n";
    }
    
    echo "\nSuccess! Speech-to-Text API is working correctly.\n";
    
    // Clean up
    $speech->close();
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Exception type: " . get_class($e) . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n====== Next Steps ======\n";
echo "1. Add these lines to your .env.local file if you haven't already:\n\n";
echo "GOOGLE_APPLICATION_CREDENTIALS=/var/www/vhosts/jumlajumla.com/mapi.jumlajumla.com/storage/app/jumlajumla-1f0f0-98ab02854aef.json\n";
echo "GOOGLE_CLOUD_PROJECT_ID=" . ($credentials['project_id'] ?? 'jumlajumla-1f0f0') . "\n\n";
echo "2. Restart your web server to reload environment variables\n";
echo "3. Try the Test Credentials button again in your web application\n"; 