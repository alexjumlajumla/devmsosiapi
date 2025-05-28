<?php
/**
 * Direct Voice API Test
 * This bypasses middleware and directly calls the transcription service
 */

// For security, require a specific query parameter
if (!isset($_GET['auth']) || $_GET['auth'] !== 'testing') {
    header('HTTP/1.1 403 Forbidden');
    echo "Access denied. Missing or invalid auth parameter.";
    exit;
}

// Include Laravel bootstrap
require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Import VoiceOrderService
use App\Services\VoiceOrderService;

// Output as JSON
header('Content-Type: application/json');

try {
    // Get environment variables
    $googleCreds = env('GOOGLE_APPLICATION_CREDENTIALS');
    $googleProjectId = env('GOOGLE_CLOUD_PROJECT_ID');
    $openAiKey = env('OPENAI_API_KEY');
    
    // Check if credentials exist
    $credStatus = [
        'google_creds_path' => $googleCreds,
        'google_creds_exists' => !empty($googleCreds) && file_exists($googleCreds),
        'google_project_id' => $googleProjectId,
        'openai_key' => !empty($openAiKey) ? 'Set (starts with ' . substr($openAiKey, 0, 3) . '...)' : 'Not set'
    ];
    
    // Create the voice service
    $voiceService = new VoiceOrderService();
    
    // List supported languages
    $languages = $voiceService->getSupportedLanguages();
    
    // If a test transcription is requested
    if (isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
        $audioFile = $_FILES['audio']['tmp_name'];
        $language = $_POST['language'] ?? 'en-US';
        
        // Transcribe the audio
        $transcription = $voiceService->transcribeAudio($audioFile, $language);
        
        echo json_encode([
            'status' => 'success',
            'credentials_status' => $credStatus,
            'transcription' => $transcription,
            'supported_languages' => $languages
        ]);
    } else {
        // Just return information about the service
        echo json_encode([
            'status' => 'ready',
            'message' => 'Upload an audio file using a POST request to test transcription',
            'credentials_status' => $credStatus,
            'supported_languages' => $languages
        ]);
    }
} catch (\Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ]);
} 