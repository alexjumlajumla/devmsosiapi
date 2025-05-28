<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FoodIntelligenceService
{
    /**
     * Weight values for different types of matches
     */
    private array $scoringWeights = [
        'intent_match' => 30,      // Direct match to primary intent
        'category_match' => 20,    // Match in category
        'filter_match' => 15,      // Each matched filter
        'cuisine_match' => 25,     // Cuisine type match
        'personalization' => 20,   // User's previous orders
        'popularity' => 10,        // Popularity score
        'rating' => 8,             // Average rating
        'exclusion_penalty' => -50, // Strong penalty for exclusions
        'spice_level' => 12,       // Matching spice level preference
    ];

    /**
     * Filter products based on AI-extracted intent, filters, and exclusions
     * Enhanced with relevance scoring
     */
    public function filterProducts(array $orderData, ?User $user = null): Collection
    {
        $intent = $orderData['intent'] ?? null;
        $filters = $orderData['filters'] ?? [];
        $exclusions = $orderData['exclusions'] ?? [];
        $cuisineType = $orderData['cuisine_type'] ?? '';
        $spiceLevel = $orderData['spice_level'] ?? '';
        
        $query = Product::query()
            ->with(['translation', 'category.translation', 'stocks'])
            ->where('active', 1);
            
        // Apply primary intent search - looking for the specific food mentioned
        if (!empty($intent)) {
            $query->where(function($q) use ($intent) {
                $q->whereHas('translation', function($subq) use ($intent) {
                    $subq->where('title', 'like', '%' . $intent . '%')
                        ->orWhere('description', 'like', '%' . $intent . '%');
                })
                ->orWhereHas('category.translation', function($subq) use ($intent) {
                    $subq->where('title', 'like', '%' . $intent . '%');
                });
            });
        }
        
        // Apply cuisine type filter if specified
        if (!empty($cuisineType)) {
            $query->where(function($q) use ($cuisineType) {
                $q->whereHas('category.translation', function($subq) use ($cuisineType) {
                    $subq->where('title', 'like', '%' . $cuisineType . '%');
                })
                ->orWhereJsonContains('ingredient_tags', strtolower($cuisineType));
            });
        }
        
        // Apply calorie filters
        if (in_array('low calorie', $filters) || in_array('low-calorie', $filters)) {
            $query->where('calories', '<', 500);
        } elseif (in_array('high protein', $filters) || in_array('high-protein', $filters)) {
            $query->where('protein_grams', '>', 15);
        }
        
        // Apply dietary preference filters
        $this->applyDietaryFilters($query, $filters);
        
        // Apply allergen exclusions - these are critical and must be enforced
        foreach ($exclusions as $exclusion) {
            if ($this->isAllergen($exclusion)) {
                $query->whereJsonDoesntContain('allergen_flags', $exclusion);
            } else {
                // Exclude products with this ingredient
                $query->where(function($q) use ($exclusion) {
                    $q->whereJsonDoesntContain('ingredient_tags', $exclusion)
                      ->orWhereNull('ingredient_tags');
                });
            }
        }
        
        // Get personalized product IDs if user is available
        $personalizedProducts = [];
        if ($user) {
            $personalizedProducts = $this->getPersonalizedProductIds($user);
        }
        
        // Order by popularity and rating as baseline
        $results = $query->orderBy('popularity_score', 'desc')
            ->orderBy('average_rating', 'desc')
            ->limit(25) // Fetch more results initially, will score and trim later
            ->get();
        
        // If we don't have enough results, fallback to broader search
        if ($results->count() < 3 && !empty($intent)) {
            $fallbackResults = $this->fallbackSearch($intent, $filters, $exclusions);
            
            // Only use fallback if it gives better results
            if ($fallbackResults->count() > $results->count()) {
                $results = $fallbackResults;
            }
        }
        
        // Calculate relevance score for each product
        $results = $this->calculateProductScores($results, $orderData, $personalizedProducts);
        
        // Sort by calculated score and limit to 15 products
        return $results->sortByDesc('score')->take(15)->values();
    }
    
    /**
     * Calculate relevance scores for each product based on multiple factors
     */
    private function calculateProductScores(Collection $products, array $orderData, array $personalizedProducts): Collection
    {
        $intent = $orderData['intent'] ?? '';
        $filters = $orderData['filters'] ?? [];
        $exclusions = $orderData['exclusions'] ?? [];
        $cuisineType = $orderData['cuisine_type'] ?? '';
        $spiceLevel = $orderData['spice_level'] ?? '';
        
        return $products->map(function($product) use ($intent, $filters, $exclusions, $cuisineType, $spiceLevel, $personalizedProducts) {
            $score = 0;
            $matchedFactors = [];
            
            // Check for intent match in title or description
            $title = strtolower($product->translation->title ?? '');
            $description = strtolower($product->translation->description ?? '');
            $category = strtolower($product->category->translation->title ?? '');
            
            // Intent matching - highest priority
            if (!empty($intent) && (strpos($title, strtolower($intent)) !== false || 
                strpos($description, strtolower($intent)) !== false)) {
                $score += $this->scoringWeights['intent_match'];
                $matchedFactors[] = 'intent_match';
            }
            
            // Category matching
            if (!empty($intent) && strpos($category, strtolower($intent)) !== false) {
                $score += $this->scoringWeights['category_match'];
                $matchedFactors[] = 'category_match';
            }
            
            // Cuisine type matching
            if (!empty($cuisineType) && 
                (strpos($category, strtolower($cuisineType)) !== false || 
                $this->arrayContainsValue($product->ingredient_tags ?? [], strtolower($cuisineType)))) {
                $score += $this->scoringWeights['cuisine_match'];
                $matchedFactors[] = 'cuisine_match';
            }
            
            // Filter matching (dietary preferences, etc.)
            $matchedFiltersCount = 0;
            foreach ($filters as $filter) {
                $filter = strtolower($filter);
                $tagToCheck = $this->getDietaryFilterTag($filter);
                
                if ($tagToCheck && $this->arrayContainsValue($product->ingredient_tags ?? [], $tagToCheck)) {
                    $matchedFiltersCount++;
                    $matchedFactors[] = "filter:{$filter}";
                }
            }
            $score += $matchedFiltersCount * $this->scoringWeights['filter_match'];
            
            // Penalize for partial exclusion matches (though exact matches are filtered out in query)
            foreach ($exclusions as $exclusion) {
                if (strpos($title, strtolower($exclusion)) !== false || 
                    strpos($description, strtolower($exclusion)) !== false) {
                    $score += $this->scoringWeights['exclusion_penalty'];
                    $matchedFactors[] = "exclusion:{$exclusion}";
                }
            }
            
            // Spice level matching if applicable
            if (!empty($spiceLevel) && !empty($product->spice_level)) {
                if (strtolower($product->spice_level) === strtolower($spiceLevel)) {
                    $score += $this->scoringWeights['spice_level'];
                    $matchedFactors[] = "spice_level";
                }
            }
            
            // Personalization boost
            if (in_array($product->id, $personalizedProducts)) {
                $score += $this->scoringWeights['personalization'];
                $matchedFactors[] = 'personalization';
            }
            
            // Popularity factor
            if ($product->popularity_score > 0) {
                $popularityBoost = min(($product->popularity_score / 5), 10) * $this->scoringWeights['popularity'] / 10;
                $score += $popularityBoost;
                $matchedFactors[] = 'popularity';
            }
            
            // Rating factor
            if ($product->average_rating > 0) {
                $ratingBoost = min($product->average_rating, 5) * $this->scoringWeights['rating'] / 5;
                $score += $ratingBoost;
                $matchedFactors[] = 'rating';
            }
            
            // Store the calculated score and match explanations
            $product->score = max(0, $score); // Ensure score is never negative
            $product->score_factors = $matchedFactors;
            
            return $product;
        });
    }
    
    /**
     * Helper to check if an array contains a specific value (case-insensitive)
     */
    private function arrayContainsValue(?array $array, string $value): bool
    {
        if (empty($array)) {
            return false;
        }
        
        $value = strtolower($value);
        foreach ($array as $item) {
            if (strtolower($item) === $value) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Convert filter names to standardized ingredient tags
     */
    private function getDietaryFilterTag(string $filter): ?string
    {
        $dietaryFilters = [
            'vegetarian' => 'vegetarian',
            'vegan' => 'vegan',
            'halal' => 'halal',
            'kosher' => 'kosher',
            'gluten-free' => 'gluten_free',
            'gluten free' => 'gluten_free',
            'dairy-free' => 'dairy_free',
            'dairy free' => 'dairy_free',
            'keto' => 'keto_friendly',
            'keto friendly' => 'keto_friendly',
            'paleo' => 'paleo_friendly',
            'paleo friendly' => 'paleo_friendly',
        ];
        
        return $dietaryFilters[strtolower($filter)] ?? null;
    }
    
    /**
     * Apply dietary filters to the query
     */
    private function applyDietaryFilters($query, array $filters): void
    {
        foreach ($filters as $filter) {
            $tagName = $this->getDietaryFilterTag(strtolower($filter));
            
            if ($tagName) {
                $query->whereJsonContains('ingredient_tags', $tagName);
            }
        }
    }
    
    /**
     * Fallback search when specific filters return too few results
     */
    private function fallbackSearch(string $intent, array $filters, array $exclusions): Collection
    {
        // Simplified query with just the most essential filters
        $query = Product::query()
            ->with(['translation', 'category.translation', 'stocks'])
            ->where('active', 1)
            ->where(function($q) use ($intent) {
                $q->whereHas('translation', function($subq) use ($intent) {
                    $subq->where('title', 'like', '%' . $intent . '%')
                        ->orWhere('description', 'like', '%' . $intent . '%');
                })
                ->orWhereHas('category.translation', function($subq) use ($intent) {
                    $subq->where('title', 'like', '%' . $intent . '%');
                });
            });
        
        // Only keep allergen exclusions for safety
        foreach ($exclusions as $exclusion) {
            if ($this->isAllergen($exclusion)) {
                $query->whereJsonDoesntContain('allergen_flags', $exclusion);
            }
        }
        
        return $query->orderBy('popularity_score', 'desc')
            ->limit(15)
            ->get();
    }
    
    /**
     * Get personalized product IDs based on user's order history
     */
    private function getPersonalizedProductIds(User $user): array
    {
        return Cache::remember('user_personalized_products_' . $user->id, 1440, function() use ($user) {
            // Get products the user has ordered before
            $orderHistory = OrderDetail::join('orders', 'order_details.order_id', '=', 'orders.id')
                ->where('orders.user_id', $user->id)
                ->where('orders.created_at', '>=', now()->subMonths(6))
                ->select('order_details.product_id', DB::raw('COUNT(*) as order_count'))
                ->groupBy('order_details.product_id')
                ->orderBy('order_count', 'desc')
                ->limit(20)
                ->pluck('product_id')
                ->toArray();
                
            return $orderHistory;
        });
    }
    
    /**
     * Check if an exclusion term is likely an allergen
     */
    private function isAllergen(string $term): bool
    {
        $commonAllergens = [
            'peanuts', 'peanut', 'nuts', 'tree nuts', 'gluten', 'dairy', 'milk', 
            'eggs', 'egg', 'soy', 'fish', 'shellfish', 'wheat', 'sesame', 'sulphites',
            'mustard', 'celery', 'lupin', 'molluscs'
        ];
        
        return in_array(strtolower($term), $commonAllergens);
    }
    
    /**
     * Update product popularity score based on order frequency
     */
    public function updatePopularityScores(): void
    {
        // Get products ordered in the last 30 days with count
        $popularProducts = DB::table('order_details')
            ->join('orders', 'order_details.order_id', '=', 'orders.id')
            ->where('orders.created_at', '>=', now()->subDays(30))
            ->select('order_details.product_id', DB::raw('COUNT(*) as order_count'))
            ->groupBy('order_details.product_id')
            ->get();
            
        foreach ($popularProducts as $product) {
            Product::where('id', $product->product_id)
                ->update(['popularity_score' => $product->order_count]);
        }
    }
    
    /**
     * Generate product recommendations based on current cart contents
     */
    public function generateCartRecommendations(array $cartProductIds, int $limit = 4): Collection
    {
        if (empty($cartProductIds)) {
            return collect();
        }
        
        // Find products frequently ordered together
        $relatedProducts = DB::table('order_details as od1')
            ->join('order_details as od2', function($join) use ($cartProductIds) {
                $join->on('od1.order_id', '=', 'od2.order_id')
                    ->whereIn('od1.product_id', $cartProductIds)
                    ->whereNotIn('od2.product_id', $cartProductIds);
            })
            ->select('od2.product_id', DB::raw('COUNT(*) as frequency'))
            ->groupBy('od2.product_id')
            ->orderBy('frequency', 'desc')
            ->limit($limit)
            ->pluck('product_id')
            ->toArray();
            
        return Product::whereIn('id', $relatedProducts)
            ->with(['translation', 'stocks'])
            ->where('active', 1)
            ->get();
    }
    
    /**
     * Calculate semantic similarity between query and products using embeddings
     * Only used if OpenAI embedding API is available
     * 
     * @param string $query User query text
     * @param Collection $products Collection of products to calculate similarity for
     * @return Collection Same collection with similarity scores added
     */
    public function calculateSemanticSimilarity(string $query, Collection $products): Collection
    {
        // Skip if OpenAI API is not configured
        if (empty(config('services.openai.api_key'))) {
            Log::info('OpenAI API key not configured, skipping semantic similarity calculation');
            return $products;
        }
        
        try {
            // Generate embedding for the query
            $queryEmbedding = $this->generateEmbedding($query);
            
            if (empty($queryEmbedding)) {
                return $products;
            }
            
            // Calculate similarity for each product
            return $products->map(function($product) use ($queryEmbedding) {
                $productText = $product->translation->title . ' ' . 
                               $product->translation->description . ' ' . 
                               $product->category->translation->title;
                               
                // Get or generate product embedding
                $productEmbedding = $this->getProductEmbedding($product->id, $productText);
                
                if (!empty($productEmbedding)) {
                    // Calculate cosine similarity
                    $similarity = $this->cosineSimilarity($queryEmbedding, $productEmbedding);
                    
                    // Add similarity score to product
                    $product->similarity = $similarity;
                    
                    // Boost the score with semantic similarity
                    if (isset($product->score)) {
                        $product->score += $similarity * 20; // Weight semantic similarity
                    }
                }
                
                return $product;
            });
        } catch (\Exception $e) {
            Log::error('Error calculating semantic similarity: ' . $e->getMessage());
            return $products;
        }
    }
    
    /**
     * Get or generate embedding for a product
     */
    private function getProductEmbedding(int $productId, string $text): ?array
    {
        $cacheKey = 'product_embedding_' . $productId;
        
        return Cache::remember($cacheKey, 60*24*7, function() use ($text) {
            return $this->generateEmbedding($text);
        });
    }
    
    /**
     * Generate an embedding vector for text using OpenAI API
     */
    private function generateEmbedding(string $text): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.openai.api_key'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/embeddings', [
                'input' => $text,
                'model' => 'text-embedding-ada-002'
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                return $data['data'][0]['embedding'] ?? null;
            }
            
            Log::error('OpenAI embedding API error', ['response' => $response->json()]);
            return null;
        } catch (\Exception $e) {
            Log::error('Error generating embedding: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Calculate cosine similarity between two embedding vectors
     */
    private function cosineSimilarity(array $vec1, array $vec2): float
    {
        $dot = 0;
        $norm1 = 0;
        $norm2 = 0;
        
        foreach ($vec1 as $i => $val1) {
            $val2 = $vec2[$i] ?? 0;
            $dot += $val1 * $val2;
            $norm1 += $val1 * $val1;
            $norm2 += $val2 * $val2;
        }
        
        if ($norm1 == 0 || $norm2 == 0) {
            return 0;
        }
        
        return $dot / (sqrt($norm1) * sqrt($norm2));
    }
    
    /**
     * Generate a description for a product using AI
     */
    public function generateAIDescription(Product $product, AIOrderService $aiService): string
    {
        $metadata = [
            'name' => $product->translation->title ?? $product->title,
            'ingredients' => $product->ingredient_tags ?? [],
            'allergens' => $product->allergen_flags ?? [],
            'calories' => $product->calories,
            'category' => $product->category->translation->title ?? $product->category->title ?? '',
        ];
        
        $prompt = "Generate a detailed food description for: " . json_encode($metadata);
        
        return $aiService->generateRecommendation(['prompt' => $prompt]);
    }
} 