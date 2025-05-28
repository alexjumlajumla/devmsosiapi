<?php
/**
 * Test Google Cloud Speech API connection
 * This script tests if the Google Cloud Speech API is working correctly
 */

// Set display errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Load Composer's autoloader
require_once __DIR__ . '/vendor/autoload.php';

use Google\Cloud\Speech\V1\SpeechClient;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\RecognitionConfig\AudioEncoding;
use Google\Cloud\Speech\V1\RecognitionAudio;

// Output header
echo "=== Google Cloud Speech API Test ===\n\n";

// Get the credentials path from environment
$credentialsPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');
echo "Using credentials path: $credentialsPath\n";

// Check if the file exists
if (empty($credentialsPath)) {
    echo "ERROR: GOOGLE_APPLICATION_CREDENTIALS is not set in environment.\n";
    echo "Trying fallback paths...\n";
    
    // Try some fallback paths
    $fallbackPaths = [
        __DIR__ . '/storage/app/google-service-account.json',
        __DIR__ . '/storage/app/jumlajumla-1f0f0-98ab02854aef.json',
        __DIR__ . '/jumlajumla-1f0f0-98ab02854aef.json',
    ];
    
    foreach ($fallbackPaths as $path) {
        if (file_exists($path)) {
            $credentialsPath = $path;
            echo "Found credentials at: $path\n";
            putenv("GOOGLE_APPLICATION_CREDENTIALS=$path");
            break;
        }
    }
}

if (empty($credentialsPath) || !file_exists($credentialsPath)) {
    echo "ERROR: Could not find valid credentials file.\n";
    exit(1);
}

echo "Credentials file found: " . (file_exists($credentialsPath) ? 'Yes' : 'No') . "\n";
echo "Credentials file readable: " . (is_readable($credentialsPath) ? 'Yes' : 'No') . "\n";

try {
    // Initialize the SpeechClient
    $speechClient = new SpeechClient();
    
    echo "SUCCESS: Google Cloud Speech client initialized successfully!\n";
    
    // Create a simple test audio content (just some text converted to base64)
    $testText = "This is a test for Google Speech API.";
    $testAudio = base64_encode($testText);
    
    // Try a simple synchronous speech recognition with minimal content
    // This is just to test if the API connection works, not actual speech recognition
    echo "\nAttempting a simple API call...\n";
    
    // Configure speech recognition
    $config = new RecognitionConfig();
    $config->setEncoding(AudioEncoding::LINEAR16);
    $config->setSampleRateHertz(16000);
    $config->setLanguageCode('en-US');
    
    // Create audio object
    $audio = new RecognitionAudio();
    $audio->setContent($testAudio);
    
    // Make the API call
    echo "Sending request to Google Cloud Speech API...\n";
    $response = $speechClient->recognize($config, $audio);
    
    echo "API responded successfully!\n";
    
    // Close the client
    $speechClient->close();
    
    echo "\nGoogle Cloud Speech API test completed successfully!\n";
    
    // If you want to test with an actual audio file, uncomment the code below:
    /*
    // Path to a test audio file (WAV format)
    $testAudioFile = __DIR__ . '/test-audio.wav';
    
    if (file_exists($testAudioFile)) {
        echo "\nTesting with an actual audio file: $testAudioFile\n";
        
        // Read audio file content
        $content = file_get_contents($testAudioFile);
        
        // Create audio object
        $audio = new RecognitionAudio();
        $audio->setContent($content);
        
        // Configure speech recognition
        $config = new RecognitionConfig();
        $config->setEncoding(AudioEncoding::LINEAR16);
        $config->setSampleRateHertz(16000);
        $config->setLanguageCode('en-US');
        
        // Detect speech in the audio file
        $response = $speechClient->recognize($config, $audio);
        $results = $response->getResults();
        
        if (count($results) > 0) {
            $alternatives = $results[0]->getAlternatives();
            $transcript = $alternatives[0]->getTranscript();
            $confidence = $alternatives[0]->getConfidence();
            
            echo "Transcription: $transcript\n";
            echo "Confidence: " . ($confidence * 100) . "%\n";
        } else {
            echo "No transcription results found\n";
        }
    }
    */
} catch (\Exception $e) {
    echo "ERROR: Failed to use Google Cloud Speech client.\n";
    echo "Error message: " . $e->getMessage() . "\n\n";
    echo "Exception trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 