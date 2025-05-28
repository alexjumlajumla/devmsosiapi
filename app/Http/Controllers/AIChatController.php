<?php

namespace App\Http\Controllers;

use App\Services\AIOrderService;
use App\Services\FoodIntelligenceService;
use App\Models\AIAssistantLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AIChatController extends Controller
{
    protected AIOrderService $aiOrderService;
    protected FoodIntelligenceService $foodIntelligenceService;

    public function __construct(
        AIOrderService $aiOrderService,
        FoodIntelligenceService $foodIntelligenceService
    ) {
        $this->aiOrderService = $aiOrderService;
        $this->foodIntelligenceService = $foodIntelligenceService;
    }

    /**
     * Process text-based food orders
     */
    public function processTextOrder(Request $request)
    {
        $startTime = microtime(true);
        $userId = Auth::id();
        $success = false;
        $sessionId = $request->input('session_id', md5(uniqid()));
        
        Log::info('Text order request:', [
            'request' => $request->all(),
            'session_id' => $sessionId
        ]);
        
        $logData = [
            'user_id' => $userId,
            'request_type' => 'text_order',
            'successful' => false,
            'session_id' => $sessionId,
            'metadata' => [
                'request_time' => now()->toIso8601String(),
                'client_ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]
        ];

        try {
            // Validate the request
            $request->validate([
                'message' => 'required|string|max:1000',
                'context' => 'nullable|array',
            ]);

            $message = $request->input('message');
            $logData['input'] = $message;
            $logData['request_content'] = $message;
            
            // Get the authenticated user if available
            $user = Auth::user();
            
            // Process text message using AIOrderService
            $orderData = $this->aiOrderService->processOrderIntent($message, $user);
            Log::info('Order intent processed', ['data' => $orderData]);
            
            // Update log data with detected filters
            $logData['filters_detected'] = $orderData;
            $logData['metadata']['order_data'] = $orderData;

            // Get product recommendations based on order data
            $recommendations = $this->foodIntelligenceService->filterProducts($orderData);
            Log::info('Recommendations retrieved', ['count' => $recommendations->count()]);
            
            // Generate a recommendation explanation
            $recommendationText = $this->aiOrderService->generateRecommendation($orderData);
            Log::info('Generated recommendation text', ['text' => $recommendationText]);
            
            // Update log data with recommendations
            $logData['product_ids'] = $recommendations->pluck('id')->toArray();
            $logData['metadata']['products'] = $recommendations->pluck('id')->toArray();
            $logData['metadata']['recommendation_text'] = $recommendationText;
            $logData['output'] = $recommendationText;
            $logData['response_content'] = $recommendationText;

            $success = true;
            
            // Create the log entry
            $logData['successful'] = $success;
            $logData['processing_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $logEntry = AIAssistantLog::create($logData);
            
            $contextData = $request->input('context', []);
            
            return response()->json([
                'success' => true,
                'intent_data' => $orderData,
                'recommendations' => $recommendations,
                'recommendation_text' => $recommendationText,
                'session_id' => $sessionId,
                'log_id' => $logEntry->id,
                'context' => array_merge($contextData, [
                    'last_intent' => $orderData['intent'] ?? null,
                    'filters' => $orderData['filters'] ?? [],
                ])
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing text order: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $logData['output'] = $e->getMessage();
            $logData['response_content'] = $e->getMessage();
            
            // Calculate processing time
            $logData['processing_time_ms'] = (int)((microtime(true) - $startTime) * 1000);
            $logData['successful'] = false;
            $logEntry = AIAssistantLog::create($logData);
            
            return response()->json([
                'success' => false, 
                'message' => 'Failed to process text order. Error: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'log_id' => $logEntry->id
            ], 500);
        }
    }

    /**
     * Update conversation context
     */
    public function updateContext(Request $request)
    {
        try {
            $request->validate([
                'session_id' => 'required|string',
                'context' => 'required|array',
            ]);
            
            $sessionId = $request->input('session_id');
            $context = $request->input('context');
            
            // Store the context in a cache or database for later use
            $cacheKey = 'chat_context_' . $sessionId;
            cache()->put($cacheKey, $context, now()->addHours(2));
            
            return response()->json([
                'success' => true,
                'message' => 'Context updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update context: ' . $e->getMessage()
            ], 422);
        }
    }
} 