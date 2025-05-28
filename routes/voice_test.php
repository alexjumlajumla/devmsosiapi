<?php

use App\Http\Controllers\VoiceOrderController;
use Illuminate\Support\Facades\Route;

/**
 * Voice Order System - Test API Endpoint
 * 
 * This standalone endpoint provides a simple way to test the voice order functionality
 * without requiring authentication. It performs both speech-to-text transcription
 * and intent analysis in a single request.
 * 
 * Used by: public/voice-test/index.html
 */
Route::post('/api/voice-test-api', function() {
    try {
        $request = request();
        $audioFile = $request->file('audio');
        $language = $request->input('language', 'en-US');
        
        if (!$audioFile) {
            return response()->json([
                'success' => false,
                'message' => 'No audio file provided'
            ], 400);
        }
        
        // Step 1: Process audio with Google Speech-to-Text
        $voiceController = app(VoiceOrderController::class);
        $transcriptionResult = $voiceController->transcribeAudio($audioFile, $language);
        
        if (!isset($transcriptionResult['text']) || empty($transcriptionResult['text'])) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to transcribe audio',
                'transcription_error' => $transcriptionResult['error'] ?? 'Unknown error'
            ], 500);
        }
        
        // Step 2: Process with OpenAI for intent understanding
        try {
            $apiKey = config('services.openai.api_key');
            $openAi = new Orhanerday\OpenAi\OpenAi($apiKey);
            
            $response = $openAi->chat([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a helpful assistant for a food ordering system. Extract key food items, quantities, and special instructions from the user\'s voice transcription.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $transcriptionResult['text'],
                    ],
                ],
                'temperature' => 0.7,
                'max_tokens' => 150,
            ]);
            
            $openAiResult = json_decode($response, true);
            
            return response()->json([
                'success' => true,
                'transcription' => $transcriptionResult['text'],
                'openai_analysis' => $openAiResult,
                'language' => $language
            ]);
        } catch (\Exception $openAiError) {
            return response()->json([
                'success' => false,
                'message' => 'OpenAI processing failed',
                'transcription' => $transcriptionResult['text'],
                'error' => $openAiError->getMessage()
            ], 500);
        }
        
    } catch (\Exception $e) {
        \Log::error('Voice test failed: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Voice test failed: ' . $e->getMessage(),
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    }
});

/**
 * Text Search API Endpoint
 * 
 * Allows searching for products based on text input
 * Used by: public/voice-test/index.html
 */
Route::post('/api/search/text', function() {
    try {
        $request = request();
        $query = $request->input('query');
        $sessionId = $request->input('session_id', 'web-'.time());
        
        if (empty($query)) {
            return response()->json([
                'success' => false,
                'message' => 'No search query provided'
            ], 400);
        }
        
        // Process text input with the AIOrderService
        $aiOrderService = app(\App\Services\AIOrderService::class);
        $foodIntelligenceService = app(\App\Services\FoodIntelligenceService::class);
        
        // Process the search query as an order intent
        $orderData = $aiOrderService->processOrderIntent($query, null);
        
        // Get product recommendations based on the intent
        $recommendations = $foodIntelligenceService->filterProducts($orderData, null);
        
        // Apply confidence-based filtering if helper exists
        if (class_exists('\\App\\Helpers\\VoiceConfidenceHelper')) {
            $recommendations = \App\Helpers\VoiceConfidenceHelper::filterRecommendationsByConfidence(
                $recommendations, 
                1.0  // For text search, we use max confidence since user typed it
            );
        }
        
        // Generate recommendation text
        $recommendationText = $aiOrderService->generateRecommendation($orderData);
        
        return response()->json([
            'success' => true,
            'query' => $query,
            'session_id' => $sessionId,
            'recommendations' => $recommendations,
            'recommendation_text' => $recommendationText,
            'confidence_score' => 1.0, // High confidence since user typed this directly
            'intent_data' => $orderData
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Error in text search API', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error processing text search: ' . $e->getMessage()
        ], 500);
    }
});

/**
 * Category Search API Endpoint
 * 
 * Allows searching for products by category
 * Used by: public/voice-test/index.html
 */
Route::post('/api/search/category', function() {
    try {
        $request = request();
        $category = $request->input('category');
        $sessionId = $request->input('session_id', 'web-'.time());
        
        if (empty($category)) {
            return response()->json([
                'success' => false,
                'message' => 'No category provided'
            ], 400);
        }
        
        // Map common category names to our system categories
        $categoryMapping = [
            'burger' => 'burgers',
            'pizza' => 'pizza',
            'vegetarian' => 'vegetarian',
            'chicken' => 'chicken',
            'dessert' => 'desserts',
            'drinks' => 'beverages'
        ];
        
        $mappedCategory = $categoryMapping[$category] ?? $category;
        
        // Create a simple intent that just has the category
        $orderIntent = [
            'intent' => 'browse',
            'filters' => [$mappedCategory],
            'exclusions' => [],
            'cuisine_type' => null,
            'product_types' => [$mappedCategory],
            'dietary_preferences' => [],
            'keywords' => [$mappedCategory]
        ];
        
        // Get products for this category
        $foodIntelligenceService = app(\App\Services\FoodIntelligenceService::class);
        $recommendations = $foodIntelligenceService->filterProducts($orderIntent, null);
        
        // No need to apply confidence filtering for category searches as confidence is high
        
        return response()->json([
            'success' => true,
            'category' => $category,
            'session_id' => $sessionId,
            'recommendations' => $recommendations,
            'recommendation_text' => "Here are some " . ucfirst($mappedCategory) . " options for you.",
            'confidence_score' => 1.0, // High confidence since user selected a specific category
            'intent_data' => $orderIntent
        ]);
        
    } catch (\Exception $e) {
        \Log::error('Error in category search API', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error processing category search: ' . $e->getMessage()
        ], 500);
    }
});
