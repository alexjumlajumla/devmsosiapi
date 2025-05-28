<?php

namespace App\Services;

use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\SpeechClient;
use Google\Cloud\Speech\V1\SpeechContext;
use Google\Cloud\Speech\V1\StreamingRecognitionConfig;
use Google\Cloud\Speech\V1\StreamingRecognizeRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Exception;
use RuntimeException;
use App\Models\VoiceOrder;
use App\Models\User;
use App\Models\AIAssistantLog;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use Carbon\Carbon;

class VoiceOrderService
{
    /**
     * @var SpeechClient Google Cloud Speech client
     */
    protected SpeechClient $speechClient;
    
    /**
     * @var array Supported language codes and their display names
     */
    protected array $supportedLanguages;
    
    /**
     * @var array Custom user-defined phrases to improve recognition
     */
    protected array $customPhrases = [];
    
    /**
     * @var array Recognized audio formats
     */
    protected array $supportedFormats = [
        'webm' => RecognitionConfig\AudioEncoding::WEBM_OPUS,
        'wav' => RecognitionConfig\AudioEncoding::LINEAR16,
        'mp3' => RecognitionConfig\AudioEncoding::MP3,
        'flac' => RecognitionConfig\AudioEncoding::FLAC,
    ];

    /**
     * Constructor with optional dependency injection for testing
     */
    public function __construct(SpeechClient $client = null)
    {
        // Try multiple paths for credentials file if none provided directly
        if ($client === null) {
            // Get the credentials path from config or env
            $credentialsPath = config('services.google.credentials');
            
            // If it's a function (from our config update), execute it
            if (is_callable($credentialsPath)) {
                $credentialsPath = $credentialsPath();
            }
            
            // Check standard paths if still not found
            if (empty($credentialsPath) || !file_exists($credentialsPath)) {
                $possiblePaths = [
                    base_path('jumlajumla-1f0f0-98ab02854aef.json'), // Root path with specific filename
                    storage_path('app/jumlajumla-1f0f0-98ab02854aef.json'), // Storage path with specific filename
                    storage_path('app/google-service-account.json'),
                    base_path('google-credentials.json'),
                    base_path('google-service-account.json'),
                    storage_path('google-credentials.json'),
                ];
                
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $credentialsPath = $path;
                        \Log::info("Found Google credentials at: $path");
                        break;
                    }
                }
            }
            
            if (empty($credentialsPath) || !file_exists($credentialsPath)) {
                throw new \Exception('Google Speech credentials file not found. Please check your configuration.');
            }
            
            try {
                // If we found a valid file, use its contents directly
                if (!empty($credentialsPath) && file_exists($credentialsPath)) {
                    $credentials = json_decode(file_get_contents($credentialsPath), true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $this->speechClient = new SpeechClient([
                            'credentials' => $credentials
                        ]);
                        \Log::info("Successfully loaded Google credentials file directly");
                    } else {
                        // Fall back to path-based config if JSON parsing fails
                        $this->speechClient = new SpeechClient([
                            'credentials' => $credentialsPath
                        ]);
                        \Log::info("Using credentials path: $credentialsPath");
                    }
                } else {
                    // Last resort - try to use ADC without explicit path
                    $this->speechClient = new SpeechClient();
                    \Log::info("Using application default credentials without explicit path");
                }
            } catch (\Exception $e) {
                \Log::error("Failed to initialize SpeechClient: " . $e->getMessage(), [
                    'credentials_path' => $credentialsPath ?? 'Not set',
                    'file_exists' => $credentialsPath ? file_exists($credentialsPath) : false,
                    'is_readable' => $credentialsPath ? is_readable($credentialsPath) : false
                ]);
                throw $e; // Re-throw to maintain original behavior
            }
        } else {
            $this->speechClient = $client;
        }
        
        // Define supported languages for the voice recognition system
        $this->supportedLanguages = [
            'en-US' => 'English (US)',
            'en-GB' => 'English (UK)',
            'ar-SA' => 'Arabic',
            'es-ES' => 'Spanish',
            'fr-FR' => 'French',
            'hi-IN' => 'Hindi',
            'sw-TZ' => 'Swahili',
            'zh-CN' => 'Chinese (Simplified)',
        ];
    }

    /**
     * Transcribe audio with support for multiple languages
     * Enhanced with retry mechanism, confidence scores, and timestamps
     * 
     * @param string $audioFilePath Path to the audio file
     * @param string $languageCode Language code
     * @return array Transcription result with metadata
     */
    public function transcribeAudio(string $audioFilePath, string $languageCode = 'en-US'): array
    {
        // Validate file exists and is readable
        if (!file_exists($audioFilePath) || !is_readable($audioFilePath)) {
            Log::warning("Audio file not found or unreadable: {$audioFilePath}", [
                'path' => $audioFilePath
            ]);
            return [
                'transcription' => '',
                'confidence' => 0,
                'error' => 'File not found or unreadable'
            ];
        }
        
        // Validate language code
        if (!array_key_exists($languageCode, $this->supportedLanguages)) {
            Log::info("Unsupported language code provided: {$languageCode}, defaulting to en-US");
            $languageCode = 'en-US'; // Default to English if invalid
        }
        
        try {
            // Process audio format if needed
            $processedFilePath = $this->processAudioFile($audioFilePath);
            
            // Read audio file contents
            $audioContent = file_get_contents($processedFilePath);
            
            // Create audio object
            $audio = (new RecognitionAudio())
                ->setContent($audioContent);
    
            // Create speech context with proper SpeechContext object
            $allPhrases = array_merge($this->getFoodRelatedPhrases(), $this->customPhrases);
            $speechContext = new SpeechContext();
            $speechContext->setPhrases($allPhrases);
            $speechContext->setBoost(20.0);
    
            // Get file format and choose appropriate encoding
            $fileExtension = pathinfo($processedFilePath, PATHINFO_EXTENSION);
            $encoding = $this->getEncodingForFormat($fileExtension);
            
            // Configure the recognition settings
            $config = (new RecognitionConfig())
                ->setEncoding($encoding)
                ->setLanguageCode($languageCode)
                ->setModel('command_and_search') // Optimized for short commands
                ->setUseEnhanced(true) // Use enhanced model
                ->setProfanityFilter(false) // Allow all words
                ->setEnableAutomaticPunctuation(true)
                ->setEnableWordTimeOffsets(true) // Enable word timestamps
                ->setSpeechContexts([$speechContext]);
            
            // Use Laravel's retry helper to attempt recognition multiple times
            $response = retry(3, function() use ($config, $audio) {
                return $this->speechClient->recognize($config, $audio);
            }, 100); // 100ms delay between retries
            
            // Process and structure the results
            $transcription = '';
            $confidence = 0;
            $wordTimestamps = [];
            $alternatives = [];
            
            foreach ($response->getResults() as $result) {
                foreach ($result->getAlternatives() as $index => $alternative) {
                    // For the first/best alternative, append to the main transcription
                    if ($index === 0) {
                        $transcription .= $alternative->getTranscript() . ' ';
                        $confidence = max($confidence, $alternative->getConfidence());
                        
                        // Process word level information if available
                        foreach ($alternative->getWords() as $wordInfo) {
                            $startTime = $wordInfo->getStartTime()->getSeconds() + 
                                         $wordInfo->getStartTime()->getNanos() / 1e9;
                            $endTime = $wordInfo->getEndTime()->getSeconds() + 
                                       $wordInfo->getEndTime()->getNanos() / 1e9;
                                       
                            $wordTimestamps[] = [
                                'word' => $wordInfo->getWord(),
                                'start_time' => $startTime,
                                'end_time' => $endTime
                            ];
                        }
                    }
                    
                    // Store all alternatives
                    $alternatives[] = [
                        'transcript' => $alternative->getTranscript(),
                        'confidence' => $alternative->getConfidence()
                    ];
                }
            }
            
            // Clean up temporary processed file if different from original
            if ($processedFilePath !== $audioFilePath && file_exists($processedFilePath)) {
                @unlink($processedFilePath);
            }
    
            return [
                'transcription' => trim($transcription),
                'confidence' => $confidence,
                'word_timestamps' => $wordTimestamps,
                'alternatives' => $alternatives,
                'language' => $languageCode,
                'success' => true
            ];
        } catch (Exception $e) {
            // Enhanced error logging with context
            Log::error('Speech recognition error', [
                'file' => $audioFilePath,
                'language' => $languageCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'transcription' => '',
                'confidence' => 0,
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];
        }
    }
    
    /**
     * Streaming transcription for real-time voice processing
     * 
     * @param resource $audioStream Stream resource of audio data
     * @param string $languageCode Language code
     * @param callable $callback Function to call for each piece of transcription
     * @return void
     */
    public function streamTranscription($audioStream, string $languageCode = 'en-US', callable $callback = null): void
    {
        if (!array_key_exists($languageCode, $this->supportedLanguages)) {
            $languageCode = 'en-US';
        }
        
        try {
            // Create speech context
            $allPhrases = array_merge($this->getFoodRelatedPhrases(), $this->customPhrases);
            $speechContext = new SpeechContext();
            $speechContext->setPhrases($allPhrases);
            $speechContext->setBoost(20.0);
            
            // Configure recognition
            $config = (new RecognitionConfig())
                ->setEncoding(RecognitionConfig\AudioEncoding::LINEAR16)
                ->setSampleRateHertz(16000) // For streaming, a fixed rate is often required
                ->setLanguageCode($languageCode)
                ->setModel('command_and_search')
                ->setProfanityFilter(false)
                ->setEnableAutomaticPunctuation(true)
                ->setSpeechContexts([$speechContext]);
                
            // Create streaming config
            $streamingConfig = (new StreamingRecognitionConfig())
                ->setConfig($config)
                ->setInterimResults(true); // Show interim results
            
            // Start streaming recognition
            $stream = $this->speechClient->streamingRecognize();
            
            // Send streaming config first
            $request = new StreamingRecognizeRequest();
            $request->setStreamingConfig($streamingConfig);
            $stream->write($request);
            
            // Function to handle incoming responses
            $responseHandler = function($response) use ($callback) {
                foreach ($response->getResults() as $result) {
                    $alternative = $result->getAlternatives()[0];
                    $transcription = $alternative->getTranscript();
                    $isFinal = $result->getIsFinal();
                    $stability = $result->getStability();
                    
                    if ($callback) {
                        $callback([
                            'transcription' => $transcription,
                            'is_final' => $isFinal,
                            'stability' => $stability
                        ]);
                    }
                }
            };
            
            // Stream audio data in chunks
            $chunkSize = 8192; // Adjust based on your needs
            while (!feof($audioStream)) {
                $chunk = fread($audioStream, $chunkSize);
                if ($chunk) {
                    $request = new StreamingRecognizeRequest();
                    $request->setAudioContent($chunk);
                    $stream->write($request);
                    
                    // Process any available responses
                    foreach ($stream->responses() as $response) {
                        $responseHandler($response);
                    }
                }
            }
            
            // Close stream and process final responses
            $stream->writesDone();
            foreach ($stream->closeWriteAndReadAll() as $response) {
                $responseHandler($response);
            }
            
        } catch (Exception $e) {
            Log::error('Streaming recognition error', [
                'language' => $languageCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if ($callback) {
                $callback([
                    'transcription' => '',
                    'is_final' => true,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Set custom speech context phrases dynamically
     * 
     * @param array $phrases Array of phrases to improve recognition
     * @return self For method chaining
     */
    public function setCustomPhrases(array $phrases): self
    {
        $this->customPhrases = $phrases;
        return $this;
    }
    
    /**
     * Add custom phrases to the existing set
     * 
     * @param array $phrases Array of phrases to add
     * @return self For method chaining
     */
    public function addCustomPhrases(array $phrases): self
    {
        $this->customPhrases = array_merge($this->customPhrases, $phrases);
        return $this;
    }
    
    /**
     * Get a list of common food-related phrases to improve recognition
     * 
     * @return array Food-related phrases
     */
    private function getFoodRelatedPhrases(): array
    {
        // Cache the phrases for better performance
        return Cache::remember('food_related_phrases', 86400, function() {
            return [
                // Common food items
                'pizza', 'burger', 'pasta', 'salad', 'sandwich', 'sushi',
                'rice', 'noodles', 'chicken', 'beef', 'fish', 'vegetarian',
                
                // Common dietary preferences
                'gluten-free', 'dairy-free', 'vegan', 'vegetarian', 'halal', 'kosher',
                'paleo', 'keto', 'low carb', 'sugar-free', 'organic',
                
                // Common allergens
                'peanuts', 'nuts', 'shellfish', 'dairy', 'gluten', 'soy', 'eggs',
                
                // Common cuisines
                'Italian', 'Chinese', 'Mexican', 'Indian', 'Thai', 'Japanese',
                'Mediterranean', 'American', 'Ethiopian', 'French', 'Greek',
                
                // Common cooking methods
                'grilled', 'fried', 'baked', 'roasted', 'steamed', 'raw',
                
                // Order-related phrases
                'I want', 'I would like', 'Can I have', 'Give me', 'Order',
                'without', 'extra', 'no', 'please', 'thanks', 'delivery',
            ];
        });
    }
    
    /**
     * Get RecognitionConfig AudioEncoding for a file format
     * 
     * @param string $format File extension or format string
     * @return int AudioEncoding value
     */
    private function getEncodingForFormat(string $format): int
    {
        $format = strtolower($format);
        
        // Check if the format is supported, default to WEBM_OPUS
        return $this->supportedFormats[$format] ?? RecognitionConfig\AudioEncoding::WEBM_OPUS;
    }
    
    /**
     * Get list of supported languages
     * 
     * @return array Supported language codes and names
     */
    public function getSupportedLanguages(): array
    {
        return $this->supportedLanguages;
    }

    /**
     * Process and convert audio file to an optimal format
     * Uses FFmpeg if available, otherwise returns the original file
     * 
     * @param string $filePath Original audio file path
     * @return string Path to processed file (may be the same as input)
     */
    public function processAudioFile(string $filePath): string
    {
        // Get file info
        $fileInfo = pathinfo($filePath);
        $extension = strtolower($fileInfo['extension'] ?? '');
        
        // Check if the format is already supported
        if (array_key_exists($extension, $this->supportedFormats)) {
            return $filePath;
        }
        
        // Define the output file
        $outputPath = storage_path('app/temp_audio_' . Str::random(8) . '.webm');
        
        // Check if FFmpeg is available
        $ffmpegPath = trim(shell_exec('which ffmpeg') ?: '');
        if (empty($ffmpegPath)) {
            Log::warning('FFmpeg not found. Audio conversion skipped.', [
                'input_file' => $filePath,
                'format' => $extension
            ]);
            return $filePath;
        }
        
        // Convert using FFmpeg (basic implementation)
        $command = "{$ffmpegPath} -i \"{$filePath}\" -c:a libopus -b:a 32k -vbr on \"{$outputPath}\" 2>&1";
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            Log::warning('Audio conversion failed', [
                'input_file' => $filePath,
                'output_file' => $outputPath,
                'command' => $command,
                'output' => implode("\n", $output),
                'return_code' => $returnCode
            ]);
            return $filePath;
        }
        
        // Verify the output file exists
        if (!file_exists($outputPath) || filesize($outputPath) === 0) {
            Log::warning('Audio conversion output file is missing or empty', [
                'output_file' => $outputPath
            ]);
            return $filePath;
        }
        
        return $outputPath;
    }
    
    /**
     * Try to auto-detect the language of the audio content
     * This is a placeholder implementation that could be expanded
     * 
     * @param string $audioFilePath Path to audio file
     * @return string|null Detected language code or null if detection failed
     */
    public function detectLanguage(string $audioFilePath): ?string
    {
        // Implement language detection logic here
        // For now, just return null to indicate no detection
        return null;
    }

    /**
     * Clean up resources on destruction
     */
    public function __destruct()
    {
        $this->speechClient->close();
    }

    /**
     * Get the audio duration using ffprobe if available
     * 
     * @param string $filePath Path to audio file
     * @return float|null Duration in seconds or null if couldn't determine
     */
    public function getAudioDuration(string $filePath): ?float
    {
        // Check if ffprobe is available on the system
        $ffprobeAvailable = false;
        try {
            exec('which ffprobe', $output, $returnVar);
            $ffprobeAvailable = ($returnVar === 0);
        } catch (\Exception $e) {
            Log::warning('Failed to check for ffprobe: ' . $e->getMessage());
        }
        
        if (!$ffprobeAvailable) {
            Log::info('ffprobe not available for audio duration detection');
            return null;
        }
        
        try {
            // Execute ffprobe to get duration
            $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($filePath);
            $output = [];
            exec($command, $output, $returnVar);
            
            if ($returnVar === 0 && !empty($output[0])) {
                return (float) $output[0];
            }
        } catch (\Exception $e) {
            Log::warning('Error getting audio duration: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Create a new VoiceOrder record from the given data
     * 
     * @param array $data The voice order data including transcription, intent, etc.
     * @param User|null $user The authenticated user, if any
     * @return VoiceOrder|null The created voice order or null if creation failed
     */
    public function createVoiceOrder(array $data, ?User $user = null): ?VoiceOrder
    {
        try {
            // Prepare data for voice order model with fallbacks for optional fields
            $voiceOrderData = [
                'user_id' => $user?->id,
                'session_id' => $data['session_id'] ?? null,
                'transcription_text' => $data['transcription_text'] ?? $data['transcription'] ?? null,
                'intent_data' => $data['intent_data'] ?? null,
                'filters_detected' => $data['filters_detected'] ?? null,
                'product_ids' => $data['product_ids'] ?? null,
                'recommendation_text' => $data['recommendation_text'] ?? null,
                'audio_url' => $data['audio_url'] ?? null,
                'audio_format' => $data['audio_format'] ?? 'unknown',
                'status' => $data['status'] ?? 'pending',
                'processing_time_ms' => $data['processing_time_ms'] ?? null,
                'confidence_score' => $data['confidence'] ?? $data['confidence_score'] ?? null,
                'log_id' => $data['log_id'] ?? null,
                'shop_id' => $data['shop_id'] ?? null,
                'currency_id' => $data['currency_id'] ?? null,
                'address_id' => $data['address_id'] ?? null,
                'delivery_type' => $data['delivery_type'] ?? 'delivery',
            ];
            
            // Handle possible JSON encoding issues with arrays
            foreach (['intent_data', 'filters_detected', 'product_ids', 'feedback'] as $field) {
                if (isset($voiceOrderData[$field]) && is_array($voiceOrderData[$field])) {
                    // Already an array, no need to encode or modify
                } elseif (isset($voiceOrderData[$field]) && is_string($voiceOrderData[$field])) {
                    // Try to decode if it's a string that might be JSON
                    try {
                        $decoded = json_decode($voiceOrderData[$field], true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $voiceOrderData[$field] = $decoded;
                        }
                    } catch (\Exception $e) {
                        // Keep as is if decode fails
                        Log::warning("Failed to decode JSON for field {$field}", [
                            'error' => $e->getMessage(),
                            'value' => $voiceOrderData[$field]
                        ]);
                    }
                }
            }
            
            // Get audio duration if file path is provided and not already set
            if (!isset($voiceOrderData['audio_duration']) && isset($data['audio_file_path'])) {
                $voiceOrderData['audio_duration'] = $this->getAudioDuration($data['audio_file_path']);
            }
            
            // Create and save the voice order
            $voiceOrder = new VoiceOrder($voiceOrderData);
            $voiceOrder->save();
            
            return $voiceOrder;
        } catch (\Exception $e) {
            Log::error('Voice order creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
            
            return null;
        }
    }
    
    /**
     * Mark a voice order as fulfilled by staff
     * 
     * @param int $id Voice order ID
     * @param int|null $agentId User ID of staff member who fulfilled it
     * @return VoiceOrder|null Updated voice order or null if not found
     */
    public function markAsFulfilled(int $id, ?int $agentId = null): ?VoiceOrder
    {
        $voiceOrder = VoiceOrder::find($id);
        
        if (!$voiceOrder) {
            return null;
        }
        
        $voiceOrder->status = 'fulfilled';
        $voiceOrder->assigned_agent_id = $agentId ?? Auth::id();
        $voiceOrder->save();
        
        return $voiceOrder;
    }
    
    /**
     * Assign a voice order to an agent
     * 
     * @param int $id Voice order ID
     * @param int $agentId User ID of staff member/agent
     * @return VoiceOrder|null Updated voice order or null if not found
     */
    public function assignAgent(int $id, int $agentId): ?VoiceOrder
    {
        $voiceOrder = VoiceOrder::find($id);
        
        if (!$voiceOrder) {
            return null;
        }
        
        $voiceOrder->assigned_agent_id = $agentId;
        $voiceOrder->save();
        
        return $voiceOrder;
    }
    
    /**
     * Save feedback for a voice order
     * 
     * @param int $id Voice order ID
     * @param array $feedback Feedback data
     * @return VoiceOrder|null Updated voice order or null if not found
     */
    public function saveFeedback(int $id, array $feedback): ?VoiceOrder
    {
        $voiceOrder = VoiceOrder::find($id);
        
        if (!$voiceOrder) {
            return null;
        }
        
        $voiceOrder->is_feedback_provided = true;
        $voiceOrder->was_helpful = $feedback['was_helpful'] ?? null;
        $voiceOrder->feedback = $feedback;
        $voiceOrder->save();
        
        // Also update the AI log if available
        if ($voiceOrder->log_id) {
            $log = AIAssistantLog::find($voiceOrder->log_id);
            if ($log) {
                $log->is_feedback_provided = true;
                $log->was_helpful = $feedback['was_helpful'] ?? null;
                $log->feedback_comment = $feedback['comment'] ?? null;
                $log->feedback = $feedback;
                $log->save();
            }
        }
        
        return $voiceOrder;
    }
    
    /**
     * Link a voice order to a regular order when it's converted
     * 
     * @param int $voiceOrderId Voice order ID
     * @param int $orderId Regular order ID
     * @return VoiceOrder|null Updated voice order or null if not found
     */
    public function linkToOrder(int $voiceOrderId, int $orderId): ?VoiceOrder
    {
        $voiceOrder = VoiceOrder::find($voiceOrderId);
        
        if (!$voiceOrder) {
            return null;
        }
        
        $voiceOrder->order_id = $orderId;
        $voiceOrder->status = 'converted';
        $voiceOrder->save();
        
        return $voiceOrder;
    }
    
    /**
     * Get voice order statistics
     * 
     * @param string|null $period Time period ('today', 'week', 'month', 'all')
     * @return array Statistics data
     */
    public function getStats(?string $period = 'all'): array
    {
        $query = VoiceOrder::query();
        
        // Apply time filter
        switch ($period) {
            case 'today':
                $query->whereDate('created_at', Carbon::today());
                break;
            case 'week':
                $query->where('created_at', '>=', Carbon::now()->subWeek());
                break;
            case 'month':
                $query->where('created_at', '>=', Carbon::now()->subMonth());
                break;
            case 'all':
            default:
                // No filter - get all
                break;
        }
        
        // Get counts by status
        $statusCounts = $query->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();
            
        // Get conversion rate
        $totalCount = array_sum($statusCounts);
        $convertedCount = $statusCounts['converted'] ?? 0;
        $conversionRate = $totalCount > 0 ? round(($convertedCount / $totalCount) * 100, 2) : 0;
        
        // Get average confidence score
        $avgConfidence = VoiceOrder::avg('confidence_score');
        
        // Get most common intents/filters
        $popularFilters = $this->getPopularFilters($period);
        
        return [
            'total_count' => $totalCount,
            'status_counts' => $statusCounts,
            'conversion_rate' => $conversionRate,
            'average_confidence' => round($avgConfidence ?? 0, 2),
            'popular_filters' => $popularFilters,
            'time_period' => $period
        ];
    }
    
    /**
     * Get most popular filters/tags from voice orders
     * 
     * @param string|null $period Time period
     * @return array Popular filters with counts
     */
    private function getPopularFilters(?string $period = 'all'): array
    {
        $query = VoiceOrder::query();
        
        // Apply time filter same as getStats()
        switch ($period) {
            case 'today':
                $query->whereDate('created_at', Carbon::today());
                break;
            case 'week':
                $query->where('created_at', '>=', Carbon::now()->subWeek());
                break;
            case 'month':
                $query->where('created_at', '>=', Carbon::now()->subMonth());
                break;
        }
        
        // Get orders with filters
        $orders = $query->whereNotNull('filters_detected')
            ->get();
            
        // Extract and count filters
        $filterCounts = [];
        foreach ($orders as $order) {
            $filters = $order->filters_detected['filters'] ?? [];
            foreach ($filters as $filter) {
                $filter = strtolower($filter);
                if (!isset($filterCounts[$filter])) {
                    $filterCounts[$filter] = 0;
                }
                $filterCounts[$filter]++;
            }
        }
        
        // Sort by popularity
        arsort($filterCounts);
        
        // Return top 10
        return array_slice($filterCounts, 0, 10, true);
    }
    
    /**
     * Get voice orders for a specific user
     * 
     * @param int $userId User ID
     * @param int $limit Max number of orders to return
     * @return Collection Voice orders
     */
    public function getUserVoiceOrders(int $userId, int $limit = 50): Collection
    {
        return VoiceOrder::where('user_id', $userId)
            ->with(['order', 'assignedAgent'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
    
    /**
     * Retry processing a failed voice order
     * 
     * @param int $id Voice order ID
     * @param AIOrderService $aiOrderService
     * @param FoodIntelligenceService $foodIntelligenceService
     * @return VoiceOrder|null Updated voice order or null if failed
     */
    public function retryProcessing(int $id, AIOrderService $aiOrderService, FoodIntelligenceService $foodIntelligenceService): ?VoiceOrder
    {
        $voiceOrder = VoiceOrder::find($id);
        
        if (!$voiceOrder || empty($voiceOrder->transcription_text)) {
            return null;
        }
        
        try {
            $startTime = microtime(true);
            
            // Get user if available
            $user = $voiceOrder->user;
            
            // Reprocess the order intent
            $transcription = $voiceOrder->transcription_text;
            $orderData = $aiOrderService->processOrderIntent($transcription, $user);
            
            // Get recommendations
            $recommendations = $foodIntelligenceService->filterProducts($orderData, $user);
            
            // Generate text recommendation
            $recommendationText = $aiOrderService->generateRecommendation($orderData);
            
            // Update the voice order
            $voiceOrder->intent_data = $orderData;
            $voiceOrder->filters_detected = $orderData;
            $voiceOrder->product_ids = $recommendations->pluck('id')->toArray();
            $voiceOrder->recommendation_text = $recommendationText;
            $voiceOrder->ai_processing_duration_ms = (int)((microtime(true) - $startTime) * 1000);
            $voiceOrder->status = 'pending'; // Reset to pending
            $voiceOrder->save();
            
            return $voiceOrder;
        } catch (\Exception $e) {
            Log::error('Failed to retry voice order processing: ' . $e->getMessage(), [
                'voice_order_id' => $id,
                'exception' => $e
            ]);
            
            return null;
        }
    }
} 