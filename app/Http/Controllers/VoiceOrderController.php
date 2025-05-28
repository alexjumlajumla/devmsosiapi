<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Log, Storage, Validator};
use App\Services\{VoiceOrderService, AIOrderService};
use App\Models\{AIAssistantLog, VoiceOrder};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\File;
use Google\Cloud\Speech\V1\{
    RecognitionConfig,
    RecognitionAudio,
    SpeechClient
};
use Google\Protobuf\Internal\RepeatedField;
use Google\Cloud\Speech\V1\StreamingRecognitionConfig;
use Google\Cloud\Speech\V1\StreamingRecognizeRequest;
use Google\ApiCore\ApiException;
use Google\Cloud\Speech\V1\SpeechContext;

class VoiceOrderController extends Controller
{
    private const MAX_AUDIO_SIZE = 10240; // 10MB
    private const MAX_TEXT_LENGTH = 1000;
    private const TRANSCRIPTION_CACHE_TTL = 300; // 5 minutes
    
    private const SUPPORTED_AUDIO_TYPES = [
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac'
    ];

    public function __construct(
        private VoiceOrderService $voiceOrderService,
        private AIOrderService $aiOrderService
    ) {
        $this->middleware('auth:api');
    }

    /**
     * Process voice order request
     */
    public function processVoiceOrder(Request $request)
    {
        $startTime = microtime(true);
        $userId = Auth::id();
        $sessionId = $request->input('session_id', Str::uuid()->toString());
        
        try {
            // Validate request
            $validated = $this->validateRequest($request);
            $language = $validated['language'] ?? 'en-US';
            
            // Prepare logging data
            $logData = $this->prepareLogData($request, $userId, $sessionId);
            Log::info('Voice order request received', $logData['metadata']);
            
            // Process input (audio or text)
            $inputResult = $this->processInput($request, $language, $logData);
            
            // Process order intent with AI
            $orderData = $this->aiOrderService->process($inputResult['text']);
            
            // Generate recommendations
            $recommendations = $this->generateRecommendations($orderData);
            
            // Create success response
            $response = $this->createSuccessResponse(
                $inputResult,
                $orderData,
                $recommendations,
                $sessionId,
                $startTime,
                $userId
            );
            
            // Log success
            $this->logSuccess($logData, $inputResult, $recommendations, $startTime, $userId);
            
            return response()->json($response);
            
        } catch (ValidationException $e) {
            return $this->handleValidationError($e, $logData ?? []);
        } catch (\Exception $e) {
            return $this->handleProcessingError($e, $logData ?? [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'metadata' => $this->getRequestMetadata($request)
            ]);
        }
    }

    /**
     * Test transcription endpoint
     */
    public function testTranscribe(Request $request)
    {
        try {
            $audioFile = $request->file('audio');
            $language = $request->input('language', 'en-US');
            
            $transcription = $this->transcribeAudio($audioFile->getPathname(), $language);
            
            return response()->json([
                'success' => true,
                'transcription' => $transcription['transcription'] ?? '',
                'confidence' => $transcription['confidence'] ?? null,
                'processing_time_ms' => $transcription['duration_ms'] ?? 0
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate the incoming request
     */
    private function validateRequest(Request $request): array
    {
        return Validator::make($request->all(), [
            'audio' => [
                'required_without:text',
                'file',
                'max:' . self::MAX_AUDIO_SIZE,
                function ($attribute, $value, $fail) {
                    if (!$value instanceof File) {
                        $fail('Invalid file provided.');
                        return;
                    }
                    
                    $mimeType = $value->getMimeType();
                    if (!isset(self::SUPPORTED_AUDIO_TYPES[$mimeType])) {
                        $fail(sprintf(
                            'Unsupported audio type: %s. Supported: %s',
                            $mimeType,
                            implode(', ', array_keys(self::SUPPORTED_AUDIO_TYPES))
                        ));
                    }
                }
            ],
            'text' => [
                'required_without:audio',
                'string',
                'max:' . self::MAX_TEXT_LENGTH,
                function ($attribute, $value, $fail) {
                    if (empty(trim($value))) {
                        $fail('Text cannot be empty.');
                    }
                }
            ],
            'language' => 'nullable|string|max:10|in:en-US,es-ES,fr-FR,de-DE',
            'session_id' => 'nullable|string|max:64'
        ])->validate();
    }

    /**
     * Prepare logging data
     */
    private function prepareLogData(Request $request, ?int $userId, string $sessionId): array
    {
        return [
            'user_id' => $userId,
            'request_type' => 'voice_order',
            'successful' => false,
            'session_id' => $sessionId,
            'metadata' => array_merge(
                $this->getRequestMetadata($request),
                [
                    'input_type' => $request->hasFile('audio') ? 'audio' : 'text',
                    'language' => $request->input('language', 'en-US')
                ]
            ),
            'audio_stored' => false
        ];
    }

    /**
     * Get sanitized request metadata
     */
    private function getRequestMetadata(Request $request): array
    {
        return [
            'request_time' => now()->toIso8601String(),
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $this->getSanitizedHeaders($request)
        ];
    }

    /**
     * Get sanitized headers
     */
    private function getSanitizedHeaders(Request $request): array
    {
        $headers = $request->headers->all();
        unset($headers['authorization'], $headers['cookie']);
        return $headers;
    }

    /**
     * Process input (audio or text)
     */
    private function processInput(Request $request, string $language, array &$logData): array
    {
        if ($request->hasFile('audio')) {
            return $this->processAudioInput($request, $language, $logData);
        }
        
        return [
            'text' => $request->input('text'),
            'confidence' => null,
            'audio_url' => null,
            'duration_ms' => 0
        ];
    }

    /**
     * Process audio input
     */
    private function processAudioInput(Request $request, string $language, array &$logData): array
    {
        $audioFile = $request->file('audio');
        $audioHash = $this->generateAudioHash($audioFile);
        
        // Check cache first
        if ($cached = Cache::get("voice_transcription:{$audioHash}:{$language}")) {
            Log::debug("Using cached transcription", [
                'audio_hash' => $audioHash,
                'language' => $language
            ]);
            
            return [
                'text' => $cached['text'],
                'confidence' => $cached['confidence'],
                'audio_url' => null,
                'duration_ms' => 0
            ];
        }

        // Store audio file and transcribe
        $audioUrl = $this->storeAudioFile($audioFile, $logData);
        $transcription = $this->transcribeAudio($audioFile->getPathname(), $language);
        
        // Cache the transcription
        if (!empty($transcription['transcription'])) {
            Cache::put(
                "voice_transcription:{$audioHash}:{$language}",
                [
                    'text' => $transcription['transcription'],
                    'confidence' => $transcription['confidence'] ?? null
                ],
                self::TRANSCRIPTION_CACHE_TTL
            );
        }

        return [
            'text' => $transcription['transcription'] ?? '',
            'confidence' => $transcription['confidence'] ?? null,
            'audio_url' => $audioUrl,
            'duration_ms' => $transcription['duration_ms'] ?? 0
        ];
    }

    /**
     * Generate hash for audio file
     */
    private function generateAudioHash($audioFile): string
    {
        return md5_file($audioFile->getPathname()) . ':' . $audioFile->getSize();
    }

    /**
     * Store audio file in S3
     */
    private function storeAudioFile($audioFile, array &$logData): ?string
    {
        $userId = Auth::id() ?? 'guest';
        $mimeType = $audioFile->getMimeType();
        $extension = self::SUPPORTED_AUDIO_TYPES[$mimeType] ?? $audioFile->guessExtension();
        
        $path = sprintf(
            '%s/%s/%s.%s',
            $userId,
            now()->format('Y-m-d'),
            Str::uuid(),
            $extension
        );

        try {
            $storedPath = Storage::disk('s3')->putFileAs(
                'voice-orders', 
                $audioFile, 
                $path,
                [
                    'ContentType' => $mimeType,
                    'Metadata' => [
                        'user-id' => (string)$userId,
                        'session-id' => $logData['session_id'],
                        'upload-time' => now()->toIso8601String()
                    ]
                ]
            );
            
            if ($storedPath) {
                $logData['audio_url'] = Storage::disk('s3')->url($storedPath);
                $logData['audio_format'] = $extension;
                $logData['audio_stored'] = true;
                $logData['metadata']['audio_size'] = $audioFile->getSize();
                $logData['metadata']['audio_mime'] = $mimeType;
                $logData['metadata']['audio_duration'] = $this->getAudioDuration($audioFile);
                
                return $logData['audio_url'];
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::error('Audio storage failed', [
                'error' => $e->getMessage(),
                'file' => $path
            ]);
            return null;
        }
    }

    /**
     * Get audio duration (placeholder - implement actual duration detection)
     */
    private function getAudioDuration($audioFile): ?float
    {
        // Implement actual duration detection if needed
        return null;
    }

    /**
     * Transcribe audio using Google Speech-to-Text
     */
    private function transcribeAudio(string $audioPath, string $language): array
    {
        $start = microtime(true);
        
        try {
            // Initialize Google Speech client
            $speechClient = new SpeechClient([
                'credentials' => config('services.google.credentials')
            ]);
            
            // Configure recognition
            $config = (new RecognitionConfig())
                ->setEncoding(RecognitionConfig\AudioEncoding::LINEAR16)
                ->setSampleRateHertz(16000)
                ->setLanguageCode($language)
                ->setEnableAutomaticPunctuation(true);
            
            // Create recognition audio
            $audio = (new RecognitionAudio())
                ->setContent(file_get_contents($audioPath));
            
            // Perform transcription
            $response = $speechClient->recognize($config, $audio);
            $transcription = '';
            $confidence = 0;
            $resultCount = 0;
            
            foreach ($response->getResults() as $result) {
                $alternatives = $result->getAlternatives();
                if (count($alternatives) > 0) {
                    $transcription .= $alternatives[0]->getTranscript() . ' ';
                    $confidence += $alternatives[0]->getConfidence();
                    $resultCount++;
                }
            }
            
            $averageConfidence = $resultCount > 0 ? ($confidence / $resultCount) : null;
            
            return [
                'transcription' => trim($transcription),
                'confidence' => $averageConfidence,
                'duration_ms' => round((microtime(true) - $start) * 1000)
            ];
            
        } catch (ApiException $e) {
            Log::error('Google Speech API error', [
                'error' => $e->getMessage(),
                'details' => $e->getMetadata()
            ]);
            
            return [
                'transcription' => '',
                'confidence' => null,
                'duration_ms' => round((microtime(true) - $start) * 1000),
                'error' => 'Speech recognition service error'
            ];
            
        } catch (\Exception $e) {
            Log::error('Transcription failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'transcription' => '',
                'confidence' => null,
                'duration_ms' => round((microtime(true) - $start) * 1000),
                'error' => $e->getMessage()
            ];
        } finally {
            if (isset($speechClient)) {
                $speechClient->close();
            }
        }
    }

    /**
     * Generate product recommendations
     */
    private function generateRecommendations(array $orderData): array
    {
        return [
            'text' => $this->aiOrderService->generateRecommendation($orderData),
            'items' => $this->aiOrderService->getRecommendedProducts($orderData)
        ];
    }

    /**
     * Create success response
     */
    private function createSuccessResponse(
        array $inputResult,
        array $orderData,
        array $recommendations,
        string $sessionId,
        float $startTime,
        ?int $userId
    ): array {
        $processingTime = round((microtime(true) - $startTime) * 1000);
        
        // Create log entry
        $logEntry = AIAssistantLog::create([
            'user_id' => $userId,
            'request_type' => 'voice_order',
            'successful' => true,
            'session_id' => $sessionId,
            'output' => $inputResult['text'],
            'response_content' => json_encode($recommendations['items']),
            'processing_time_ms' => $processingTime,
            'metadata' => [
                'transcription_time_ms' => $inputResult['duration_ms'] ?? 0,
                'confidence_score' => $inputResult['confidence'] ?? null,
                'audio_url' => $inputResult['audio_url'] ?? null
            ]
        ]);

        // Create voice order record
        $voiceOrder = VoiceOrder::create([
            'user_id' => $userId,
            'log_id' => $logEntry->id,
            'input' => $inputResult['text'],
            'response' => $recommendations['items'],
            'session_id' => $sessionId,
            'processing_time_ms' => $processingTime
        ]);

        return [
            'success' => true,
            'transcription' => $inputResult['text'],
            'intent_data' => $orderData,
            'recommendations' => $recommendations['items'],
            'recommendation_text' => $recommendations['text'],
            'session_id' => $sessionId,
            'log_id' => $logEntry->id,
            'voice_order_id' => $voiceOrder->id,
            'confidence_score' => $inputResult['confidence'] ?? null,
            'processing_time_ms' => $processingTime,
            'audio_url' => $inputResult['audio_url'] ?? null
        ];
    }

    /**
     * Log successful processing
     */
    private function logSuccess(
        array $logData,
        array $inputResult,
        array $recommendations,
        float $startTime,
        ?int $userId
    ): void {
        $logData['successful'] = true;
        $logData['output'] = $inputResult['text'];
        $logData['response_content'] = json_encode($recommendations['items']);
        $logData['processing_time_ms'] = round((microtime(true) - $startTime) * 1000);
        $logData['metadata']['transcription_time_ms'] = $inputResult['duration_ms'] ?? 0;
        $logData['metadata']['confidence_score'] = $inputResult['confidence'] ?? null;
        $logData['metadata']['audio_url'] = $inputResult['audio_url'] ?? null;

        AIAssistantLog::create($logData);
    }

    /**
     * Handle validation errors
     */
    private function handleValidationError(ValidationException $e, array $logData)
    {
        $errors = $e->errors();
        
        Log::warning('Voice order validation failed', [
            'errors' => $errors,
            'input' => $this->sanitizeInput($e->validator->getData())
        ]);

        $logData['metadata']['validation_errors'] = $errors;
        $logData['metadata']['failed_at'] = 'validation';
        
        AIAssistantLog::create($logData);

        return response()->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $errors,
            'error_code' => 'VALIDATION_ERROR'
        ], 422);
    }

    /**
     * Sanitize input for logging
     */
    private function sanitizeInput(array $input): array
    {
        unset($input['audio']);
        return $input;
    }

    /**
     * Handle processing errors
     */
    private function handleProcessingError(\Exception $e, array $logData)
    {
        Log::error('Voice order processing failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'log_data' => $logData
        ]);

        $logData['metadata']['error'] = $e->getMessage();
        $logData['metadata']['stack_trace'] = $e->getTraceAsString();
        $logData['metadata']['failed_at'] = 'processing';
        
        AIAssistantLog::create($logData);

        return response()->json([
            'success' => false,
            'message' => 'Processing error occurred',
            'error' => $e->getMessage(),
            'error_code' => 'PROCESSING_ERROR',
            'session_id' => $logData['session_id'] ?? null
        ], 500);
    }

    /**
     * Test OpenAI key
     */
    public function testOpenAIKey(Request $request)
    {
        try {
            $apiKey = $request->input('api_key') ?? config('services.openai.api_key');
            
            if (empty($apiKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No API key provided'
                ], 400);
            }
            
            // Test the key by making a simple request
            $response = $this->aiOrderService->testConnection($apiKey);
            
            return response()->json([
                'success' => true,
                'message' => 'OpenAI connection successful',
                'response' => $response
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'OpenAI connection failed: ' . $e->getMessage()
            ], 500);
        }
    }
}