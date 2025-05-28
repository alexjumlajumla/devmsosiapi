<?php

namespace App\Services;

use Orhanerday\OpenAi\OpenAi;
use App\Models\User;
use App\Models\AIAssistantLog;
use App\Models\OrderDetail;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Exception;
use JsonException;

class AIOrderService
{
    protected ?OpenAi $openAi = null;
    protected bool $apiInitialized = false;
    protected array $defaultResponse = [
        'intent' => '',
        'filters' => [],
        'cuisine_type' => '',
        'exclusions' => [],
        'portion_size' => '',
        'spice_level' => '',
        'confidence' => 0.0,
        'error' => null
    ];

    public function __construct()
    {
        $this->initializeOpenAI();
    }

    /**
     * Initialize the OpenAI API client
     */
    private function initializeOpenAI(): void
    {
        if ($this->apiInitialized) {
            return;
        }

        try {
            $apiKey = Config::get('services.openai.api_key') ?: getenv('OPENAI_API_KEY');

            if (empty($apiKey)) {
                throw new Exception('OpenAI API key not configured');
            }

            $this->openAi = new OpenAi($apiKey);
            $this->apiInitialized = true;
        } catch (Exception $e) {
            Log::error('OpenAI initialization failed: ' . $e->getMessage());
            $this->apiInitialized = false;
        }
    }

    /**
     * Process order intent from transcription
     */
    public function process(string $transcription, ?User $user = null): array
    {
        try {
            if (!$this->apiInitialized) {
                throw new Exception('OpenAI API not initialized');
            }

            $transcription = trim($transcription);
            if (empty($transcription)) {
                throw new Exception('Empty transcription provided');
            }

            $userContext = $user ? $this->getUserOrderContext($user) : '';
            $response = $this->getOpenAIResponse($transcription, $userContext);
            
            return $this->processOpenAIResponse($response, $transcription);
        } catch (Exception $e) {
            Log::error('Order processing failed: ' . $e->getMessage());
            return array_merge($this->defaultResponse, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get and decode OpenAI API response
     */
    private function getOpenAIResponse(string $transcription, string $userContext): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => $this->getSystemPrompt()
            ],
            [
                'role' => 'user',
                'content' => $this->getUserPrompt($transcription, $userContext)
            ]
        ];

        $response = $this->openAi->chat([
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages,
            'temperature' => 0.3,
            'max_tokens' => 500,
            'response_format' => ['type' => 'json_object']
        ]);

        return $this->decodeApiResponse($response);
    }

    /**
     * Decode and validate API response
     */
    private function decodeApiResponse(string $response): array
    {
        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            
            if (!is_array($decoded)) {
                throw new JsonException('Invalid JSON structure');
            }
            
            return $decoded;
        } catch (JsonException $e) {
            Log::error('OpenAI response decoding failed: ' . $e->getMessage());
            throw new Exception('Failed to process API response');
        }
    }

    /**
     * Process and validate OpenAI response
     */
    private function processOpenAIResponse(array $response, string $transcription): array
    {
        if (isset($response['error'])) {
            throw new Exception($response['error']['message'] ?? 'OpenAI API error');
        }

        $content = $response['choices'][0]['message']['content'] ?? '';
        
        try {
            $orderData = is_string($content) 
                ? json_decode($content, true, 512, JSON_THROW_ON_ERROR)
                : (is_array($content) ? $content : []);
                
            return $this->enhanceOrderData($orderData, $transcription);
        } catch (JsonException $e) {
            Log::warning('Failed to parse OpenAI content: ' . $e->getMessage());
            return $this->enhanceOrderData([], $transcription);
        }
    }

    /**
     * Generate system prompt
     */
    private function getSystemPrompt(): string
    {
        return "You are a food ordering assistant. Extract: intent (main dish), filters (dietary needs), cuisine_type, exclusions (ingredients to avoid), portion_size, spice_level. Return valid JSON.";
    }

    /**
     * Generate user prompt
     */
    private function getUserPrompt(string $transcription, string $userContext): string
    {
        $prompt = "Extract order details from: \"$transcription\"";
        
        if (!empty($userContext)) {
            $prompt .= "\nUser context: $userContext";
        }
        
        return $prompt;
    }

    /**
     * Get user's order history context
     */
    private function getUserOrderContext(User $user): string
    {
        try {
            return OrderDetail::join('orders', 'order_details.order_id', '=', 'orders.id')
                ->join('products', 'order_details.product_id', '=', 'products.id')
                ->join('product_translations', function($join) {
                    $join->on('products.id', '=', 'product_translations.product_id')
                        ->where('product_translations.locale', '=', app()->getLocale());
                })
                ->where('orders.user_id', $user->id)
                ->where('orders.created_at', '>=', now()->subMonths(3))
                ->select('product_translations.title', DB::raw('COUNT(*) as order_count'))
                ->groupBy('product_translations.title')
                ->orderBy('order_count', 'desc')
                ->limit(5)
                ->get()
                ->pluck('title')
                ->implode(', ') ?: '';
        } catch (Exception $e) {
            Log::error('Failed to get user order context: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Enhance and validate order data
     */
    private function enhanceOrderData(array $orderData, string $transcription): array
    {
        $result = array_merge($this->defaultResponse, $orderData);
        
        // Clean array fields
        $result['filters'] = $this->cleanArrayField($result['filters']);
        $result['exclusions'] = $this->cleanArrayField($result['exclusions']);
        
        // Detect missing fields
        if (empty($result['cuisine_type'])) {
            $result['cuisine_type'] = $this->detectCuisineType($transcription);
        }
        
        // Calculate confidence
        $result['confidence'] = $this->calculateConfidence($result, $transcription);
        
        return $result;
    }

    /**
     * Clean array values
     */
    private function cleanArrayField(array $items): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', array_map('strtolower', $items)),
            fn($item) => !empty($item)
        )));
    }

    /**
     * Detect cuisine from keywords
     */
    private function detectCuisineType(string $transcription): string
    {
        $cuisines = [
            'italian' => ['pasta', 'pizza', 'risotto'],
            'chinese' => ['wonton', 'dim sum', 'fried rice'],
            'indian' => ['curry', 'biryani', 'tandoori'],
            'thai' => ['pad thai', 'tom yum', 'satay'],
            'mexican' => ['taco', 'burrito', 'quesadilla'],
            'japanese' => ['sushi', 'ramen', 'tempura']
        ];
        
        $text = strtolower($transcription);
        
        foreach ($cuisines as $cuisine => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $cuisine;
                }
            }
        }
        
        return '';
    }

    /**
     * Calculate confidence score
     */
    private function calculateConfidence(array $data, string $transcription): float
    {
        $score = 0.5; // Base
        
        if (!empty($data['intent'])) {
            $score += 0.3;
            $score += str_contains(strtolower($transcription), strtolower($data['intent'])) ? 0.1 : 0;
        }
        
        foreach (['cuisine_type', 'filters', 'exclusions'] as $field) {
            if (!empty($data[$field])) {
                $score += 0.05;
            }
        }
        
        return min(1.0, max(0.0, $score));
    }

    /**
     * Generate food recommendations
     */
    public function generateRecommendation(array $orderData): string
    {
        try {
            if (!$this->apiInitialized) {
                throw new Exception('OpenAI API not initialized');
            }

            $response = $this->openAi->chat([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Generate 2-3 concise food recommendations based on: ' . json_encode($orderData)
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Suggest meal options considering dietary needs and preferences'
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 200
            ]);

            $decoded = $this->decodeApiResponse($response);
            
            if (isset($decoded['error'])) {
                throw new Exception($decoded['error']['message'] ?? 'OpenAI API error');
            }

            return $decoded['choices'][0]['message']['content'] ?? 'No recommendations available';
        } catch (Exception $e) {
            Log::error('Recommendation generation failed: ' . $e->getMessage());
            return 'Unable to generate recommendations. Please try again.';
        }
    }
}