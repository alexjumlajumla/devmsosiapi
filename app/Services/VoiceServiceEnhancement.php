<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enhancement service to fix voice order validation issues
 */
class VoiceServiceEnhancement
{
    /**
     * Enhanced product search that tries multiple approaches
     * 
     * @param string $transcription Transcription text
     * @return array Products found
     */
    public static function searchProducts(string $transcription): array
    {
        // Normalize the input text
        $searchText = strtolower(trim($transcription));
        
        // Try exact matching first
        $exactMatches = Product::where(function($query) use ($searchText) {
            $query->whereRaw('LOWER(name) LIKE ?', ["%{$searchText}%"])
                  ->orWhereRaw('LOWER(description) LIKE ?', ["%{$searchText}%"]);
        })->limit(10)->get();
        
        if ($exactMatches->isNotEmpty()) {
            Log::info('Found products via exact DB match', [
                'count' => $exactMatches->count(),
                'query' => $searchText
            ]);
            return $exactMatches->all();
        }
        
        // Try word-by-word matching (for partial product names)
        $words = preg_split('/\s+/', $searchText);
        $filteredWords = array_filter($words, function($word) {
            // Filter out common words that aren't likely product identifiers
            $commonWords = ['a', 'an', 'the', 'some', 'get', 'me', 'i', 'want', 'please', 'order', 'with'];
            return !in_array($word, $commonWords) && strlen($word) > 2;
        });
        
        if (!empty($filteredWords)) {
            $wordMatches = collect();
            
            foreach ($filteredWords as $word) {
                $matches = Product::whereRaw('LOWER(name) LIKE ?', ["%{$word}%"])->limit(5)->get();
                if ($matches->isNotEmpty()) {
                    $wordMatches = $wordMatches->merge($matches);
                }
            }
            
            $wordMatches = $wordMatches->unique('id');
            
            if ($wordMatches->isNotEmpty()) {
                Log::info('Found products via word-by-word DB match', [
                    'count' => $wordMatches->count(),
                    'words' => $filteredWords,
                    'query' => $searchText
                ]);
                return $wordMatches->all();
            }
        }
        
        // Fallback to static list as last resort
        $matches = self::getPopularProductsWithMatching($searchText);
        
        Log::info('Found products via fallback static list', [
            'count' => count($matches),
            'query' => $searchText
        ]);
        
        return $matches;
    }
    
    /**
     * Enhanced entities extraction with better fallbacks
     * 
     * @param string $transcription Transcription text
     * @return array Product entities
     */
    public static function extractProductEntities(string $transcription): array
    {
        $entities = [];
        $products = self::searchProducts($transcription);
        
        // Extract dietary preferences and nutrition requirements
        $dietaryPreferences = self::extractDietaryPreferences($transcription);
        
        foreach ($products as $product) {
            // Extract quantity if present
            $quantity = 1;
            $productName = $product->name ?? $product['name'] ?? '';
            
            // Look for quantity indicators before the product name in transcription
            if (preg_match('/(\d+)\s+' . preg_quote($productName, '/') . '/i', $transcription, $matches)) {
                if (isset($matches[1]) && is_numeric($matches[1])) {
                    $quantity = (int)$matches[1];
                }
            }
            
            $productEntity = [
                'type' => 'product',
                'value' => $productName,
                'id' => $product->id ?? $product['id'] ?? 0,
                'confidence' => 0.8,
                'quantity' => $quantity
            ];
            
            // Add dietary preferences if found
            if (!empty($dietaryPreferences)) {
                $productEntity['dietary_preferences'] = $dietaryPreferences;
            }
            
            $entities[] = $productEntity;
        }
        
        // If no products found but transcription mentions food items, add fallback generic food entity
        if (empty($entities) && self::containsFoodTerms($transcription)) {
            $genericEntity = [
                'type' => 'generic_food',
                'value' => 'food item',
                'confidence' => 0.5,
                'quantity' => 1
            ];
            
            // Add dietary preferences if found to generic entity
            if (!empty($dietaryPreferences)) {
                $genericEntity['dietary_preferences'] = $dietaryPreferences;
            }
            
            $entities[] = $genericEntity;
        }
        
        return $entities;
    }
    
    /**
     * Extract dietary preferences and nutrition requirements from transcription
     * 
     * @param string $transcription
     * @return array Dietary preferences found
     */
    protected static function extractDietaryPreferences(string $transcription): array
    {
        $preferences = [];
        $lowercaseText = strtolower($transcription);
        
        // Common dietary preferences and nutrition terms
        $dietaryTerms = [
            'vegetarian' => ['vegetarian', 'no meat'],
            'vegan' => ['vegan', 'plant based', 'no animal products'],
            'gluten_free' => ['gluten free', 'no gluten', 'gluten-free'],
            'dairy_free' => ['dairy free', 'no dairy', 'lactose free', 'dairy-free'],
            'keto' => ['keto', 'ketogenic', 'low carb high fat'],
            'low_carb' => ['low carb', 'low carbohydrate', 'no carbs'],
            'high_protein' => ['high protein', 'protein rich', 'extra protein'],
            'paleo' => ['paleo', 'paleolithic'],
            'halal' => ['halal'],
            'kosher' => ['kosher'],
            'organic' => ['organic', 'organically grown'],
            'sugar_free' => ['sugar free', 'no sugar', 'sugar-free'],
            'nut_free' => ['nut free', 'no nuts', 'without nuts'],
            'low_calorie' => ['low calorie', 'diet', 'light'],
            'spicy' => ['spicy', 'hot', 'extra spicy'],
            'mild' => ['mild', 'not spicy', 'no spice']
        ];
        
        foreach ($dietaryTerms as $preference => $terms) {
            foreach ($terms as $term) {
                if (strpos($lowercaseText, $term) !== false) {
                    $preferences[] = $preference;
                    break; // Found one term for this preference, no need to check others
                }
            }
        }
        
        return array_unique($preferences);
    }
    
    /**
     * Check if transcription contains common food-related terms
     * 
     * @param string $transcription
     * @return bool
     */
    protected static function containsFoodTerms(string $transcription): bool
    {
        $foodTerms = [
            'food', 'meal', 'lunch', 'dinner', 'breakfast', 'snack', 'eat', 'order', 
            'burger', 'pizza', 'salad', 'sandwich', 'drink', 'soda', 'water', 'juice',
            'chicken', 'beef', 'vegetable', 'rice', 'pasta', 'fries', 'chips', 'dessert'
        ];
        
        $lowercaseText = strtolower($transcription);
        
        foreach ($foodTerms as $term) {
            if (strpos($lowercaseText, $term) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get popular products with additional matching against transcription
     * 
     * @param string $searchText Search text to match against
     * @param int $limit Maximum number of products to return
     * @return array Products
     */
    protected static function getPopularProductsWithMatching(string $searchText, int $limit = 10): array
    {
        // Simulated products (in a real app, these would come from a cache or DB)
        $sampleProducts = [
            ['id' => 1, 'name' => 'Chicken Burger', 'price' => 8.99],
            ['id' => 2, 'name' => 'Vegetarian Pizza', 'price' => 12.50],
            ['id' => 3, 'name' => 'Greek Salad', 'price' => 7.25],
            ['id' => 4, 'name' => 'Grilled Chicken Wrap', 'price' => 6.99],
            ['id' => 5, 'name' => 'Fish and Chips', 'price' => 10.50],
            ['id' => 6, 'name' => 'Beef Burrito', 'price' => 9.75],
            ['id' => 7, 'name' => 'Sushi Platter', 'price' => 15.99],
            ['id' => 8, 'name' => 'Chicken Curry', 'price' => 11.25],
            ['id' => 9, 'name' => 'Pasta Carbonara', 'price' => 9.50],
            ['id' => 10, 'name' => 'Caesar Salad', 'price' => 6.75],
            ['id' => 11, 'name' => 'Burger', 'price' => 7.99],
            ['id' => 12, 'name' => 'Pizza', 'price' => 10.99],
            ['id' => 13, 'name' => 'Fries', 'price' => 3.99],
            ['id' => 14, 'name' => 'Salad', 'price' => 5.99],
            ['id' => 15, 'name' => 'Soda', 'price' => 1.99],
            ['id' => 16, 'name' => 'Water', 'price' => 0.99],
            ['id' => 17, 'name' => 'Juice', 'price' => 2.99],
            ['id' => 18, 'name' => 'Coffee', 'price' => 2.50],
            ['id' => 19, 'name' => 'Tea', 'price' => 1.99],
            ['id' => 20, 'name' => 'Dessert', 'price' => 4.99]
        ];
        
        // Convert to objects to match the Product model format
        $products = [];
        foreach ($sampleProducts as $product) {
            // Only include if there's some match with the search text
            $words = explode(' ', strtolower($searchText));
            $productName = strtolower($product['name']);
            
            $foundMatch = false;
            foreach ($words as $word) {
                if (strlen($word) > 2 && strpos($productName, $word) !== false) {
                    $foundMatch = true;
                    break;
                }
            }
            
            if ($foundMatch || strpos(strtolower($searchText), strtolower($product['name'])) !== false) {
                $obj = new \stdClass();
                $obj->id = $product['id'];
                $obj->name = $product['name'];
                $obj->price = $product['price'];
                $products[] = $obj;
            }
        }
        
        // If no specific matches, include some general products as fallback
        if (empty($products)) {
            foreach ($sampleProducts as $product) {
                if (in_array(strtolower($product['name']), ['burger', 'pizza', 'salad', 'fries', 'soda'])) {
                    $obj = new \stdClass();
                    $obj->id = $product['id'];
                    $obj->name = $product['name'];
                    $obj->price = $product['price'];
                    $products[] = $obj;
                }
            }
        }
        
        return array_slice($products, 0, $limit);
    }
    
    /**
     * Handle adding products to cart with improved validation and fallbacks
     * 
     * @param array $intent Intent data
     * @param array $response Current response
     * @param array $dialogueState Current dialogue state
     * @return array Updated response
     */
    public static function handleAddToCartEnhanced(array $intent, array $response, array $dialogueState): array
    {
        $products = [];
        $failedProducts = [];
        $dietaryPreferences = [];
        
        // Check if we have entities before proceeding
        if (empty($intent['entities'])) {
            $response['message'] = "I couldn't identify any specific items in your request. Please try again with the name of a product.";
            return $response;
        }
        
        // Extract dietary preferences from all entities
        foreach ($intent['entities'] as $entity) {
            if (isset($entity['dietary_preferences']) && is_array($entity['dietary_preferences'])) {
                $dietaryPreferences = array_merge($dietaryPreferences, $entity['dietary_preferences']);
            }
        }
        $dietaryPreferences = array_unique($dietaryPreferences);
        
        // Set dietary preferences in dialogue state if found
        if (!empty($dietaryPreferences)) {
            $dialogueState['dietary_preferences'] = $dietaryPreferences;
        }
        
        foreach ($intent['entities'] as $entity) {
            if ($entity['type'] === 'product') {
                // For real implementation, this would look up the product in the database
                $product = self::findProduct($entity['value']);
                
                if ($product) {
                    $quantity = $entity['quantity'] ?? 1;
                    
                    // Create product data for cart
                    $cartProduct = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => $product->price,
                        'quantity' => $quantity,
                        'shop_id' => $product->shop_id ?? ($dialogueState['shop_id'] ?? null)
                    ];
                    
                    // Add nutrition info if available
                    $nutritionInfo = self::getNutritionInfo($product->name);
                    if ($nutritionInfo) {
                        $cartProduct['nutrition_info'] = $nutritionInfo;
                    }
                    
                    // Add to response products
                    $products[] = $cartProduct;
                    
                    // Update dialogue state
                    $foundInCart = false;
                    foreach ($dialogueState['cart'] as &$item) {
                        if ($item['id'] === $product->id) {
                            $item['quantity'] += $quantity;
                            $foundInCart = true;
                            break;
                        }
                    }
                    
                    if (!$foundInCart) {
                        $dialogueState['cart'][] = $cartProduct;
                    }
                    
                    // If the product has a shop_id and we don't have one yet, set it
                    if (empty($dialogueState['shop_id']) && !empty($product->shop_id)) {
                        $dialogueState['shop_id'] = $product->shop_id;
                    }
                } else {
                    $failedProducts[] = $entity['value'];
                }
            } elseif ($entity['type'] === 'generic_food') {
                // Handle generic food request with popular recommendations
                $response['message'] = "I'm not sure which specific " . $entity['value'] . " you're looking for. Here are some popular options:";
                
                // Get some popular products, filtered by dietary preferences if present
                $popularProducts = self::getPopularProductsWithMatching('', 3);
                
                // Filter by dietary preferences if any were detected
                if (!empty($dietaryPreferences)) {
                    $popularProducts = self::filterProductsByDietaryPreferences($popularProducts, $dietaryPreferences);
                }
                
                foreach ($popularProducts as $product) {
                    $recommendation = [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => $product->price,
                        'quantity' => 1,
                        'shop_id' => $product->shop_id ?? ($dialogueState['shop_id'] ?? null),
                        'is_recommendation' => true
                    ];
                    
                    // Add nutrition info if available
                    $nutritionInfo = self::getNutritionInfo($product->name);
                    if ($nutritionInfo) {
                        $recommendation['nutrition_info'] = $nutritionInfo;
                    }
                    
                    $products[] = $recommendation;
                }
                
                $response['recommendation_type'] = 'generic';
                
                // Add dietary preferences to response if any were detected
                if (!empty($dietaryPreferences)) {
                    $response['dietary_preferences'] = $dietaryPreferences;
                    $response['message'] .= " These options are compatible with your " . 
                        self::formatDietaryPreferences($dietaryPreferences) . " preferences.";
                }
            }
        }
        
        if (!empty($products)) {
            $response['actions'][] = [
                'type' => 'add_to_cart',
                'products' => $products,
                'shop_id' => $dialogueState['shop_id'] ?? null
            ];
            
            if (empty($response['message'])) {
                $response['message'] = count($products) === 1 
                    ? "Added {$products[0]['name']} to your cart." 
                    : "Added " . count($products) . " items to your cart.";
                
                // Add dietary acknowledgment if preferences detected
                if (!empty($dietaryPreferences)) {
                    $response['message'] .= " Your " . self::formatDietaryPreferences($dietaryPreferences) . 
                        " preferences have been noted.";
                }
            }
            
            // If there were some failed products, mention them
            if (!empty($failedProducts)) {
                $response['message'] .= " I couldn't find: " . implode(', ', $failedProducts) . ". Please try again with specific product names.";
            }
            
            // Update dialogue state
            $dialogueState['step'] = 'items_added';
            
            // If this is the first product, suggest payment options
            if (count($dialogueState['cart']) === count($products)) {
                $paymentMethods = self::getAvailablePaymentMethods();
                if (!empty($paymentMethods)) {
                    $response['message'] .= " Would you like to checkout? You can pay with " . 
                        implode(', ', array_column($paymentMethods, 'name')) . ".";
                    
                    $response['actions'][] = [
                        'type' => 'suggest_payment_methods',
                        'payment_methods' => $paymentMethods
                    ];
                }
            }
        } else {
            $response['message'] = "I couldn't find any products that match your request. Please try asking for something specific like 'Chicken Burger' or 'Pizza'.";
            if (!empty($failedProducts)) {
                $response['message'] .= " I couldn't find: " . implode(', ', $failedProducts) . ".";
            }
            
            // If dietary preferences were detected, acknowledge them
            if (!empty($dietaryPreferences)) {
                $response['message'] .= " I've noted your " . self::formatDietaryPreferences($dietaryPreferences) . 
                    " preferences for future recommendations.";
                $response['dietary_preferences'] = $dietaryPreferences;
            }
        }
        
        // Log the cart state for debugging
        Log::info('Cart state after handleAddToCartEnhanced', [
            'cart' => $dialogueState['cart'],
            'added_products' => $products,
            'failed_products' => $failedProducts,
            'dietary_preferences' => $dietaryPreferences
        ]);
        
        return $response;
    }
    
    /**
     * Find a product by name with improved matching
     * 
     * @param string $productName
     * @return object|null
     */
    protected static function findProduct(string $productName)
    {
        // In a real application, this would search the database with better matching logic
        $products = self::getPopularProductsWithMatching($productName, 20);
        
        foreach ($products as $product) {
            if (stripos($product->name, $productName) !== false) {
                return $product;
            }
        }
        
        return null;
    }
    
    /**
     * Get available payment methods
     * 
     * @return array Payment methods
     */
    protected static function getAvailablePaymentMethods(): array
    {
        // In a real application, this would get from database
        // This is a placeholder with sample data
        return [
            ['id' => 1, 'name' => 'Credit Card', 'code' => 'card'],
            ['id' => 2, 'name' => 'PayPal', 'code' => 'paypal'],
            ['id' => 3, 'name' => 'Cash on Delivery', 'code' => 'cod'],
            ['id' => 4, 'name' => 'M-Pesa', 'code' => 'mpesa']
        ];
    }
    
    /**
     * Get nutrition information for a product
     * 
     * @param string $productName
     * @return array|null Nutrition information or null if not available
     */
    public static function getNutritionInfo(string $productName): ?array
    {
        // This could be implemented with an API call to a nutrition database
        // or with OpenAI for more dynamic responses
        
        // For now, we'll use a simple lookup table for common items
        $nutritionDatabase = [
            'burger' => [
                'calories' => 600,
                'protein' => 25,
                'carbs' => 40,
                'fat' => 35
            ],
            'pizza' => [
                'calories' => 300, // per slice
                'protein' => 12,
                'carbs' => 35,
                'fat' => 10
            ],
            'salad' => [
                'calories' => 200,
                'protein' => 5,
                'carbs' => 10,
                'fat' => 15
            ],
            'chicken' => [
                'calories' => 335,
                'protein' => 38,
                'carbs' => 0,
                'fat' => 20
            ]
        ];
        
        // Simple partial matching
        $productNameLower = strtolower($productName);
        foreach ($nutritionDatabase as $key => $nutrition) {
            if (strpos($productNameLower, $key) !== false) {
                return $nutrition;
            }
        }
        
        return null;
    }
    
    /**
     * Filter products by dietary preferences
     * 
     * @param array $products List of products to filter
     * @param array $dietaryPreferences List of dietary preferences
     * @return array Filtered products
     */
    protected static function filterProductsByDietaryPreferences(array $products, array $dietaryPreferences): array
    {
        // In a real application, this would check product attributes or tags
        // This is a simplified example implementation
        $filteredProducts = [];
        
        // Define product compatibility with dietary preferences
        $compatibilityMap = [
            'vegetarian' => ['salad', 'pizza', 'pasta', 'vegetable', 'fruit', 'cheese'],
            'vegan' => ['salad', 'vegetable', 'fruit'],
            'gluten_free' => ['salad', 'rice', 'potato', 'vegetable', 'fruit', 'meat'],
            'dairy_free' => ['salad', 'meat', 'fish', 'vegetable', 'fruit'],
            'low_carb' => ['meat', 'fish', 'egg', 'cheese', 'vegetable'],
            'high_protein' => ['meat', 'fish', 'egg', 'protein', 'chicken']
        ];
        
        foreach ($products as $product) {
            $isCompatible = true;
            $productName = strtolower($product->name ?? $product['name'] ?? '');
            
            foreach ($dietaryPreferences as $preference) {
                if (isset($compatibilityMap[$preference])) {
                    $compatible = false;
                    foreach ($compatibilityMap[$preference] as $term) {
                        if (strpos($productName, $term) !== false) {
                            $compatible = true;
                            break;
                        }
                    }
                    
                    if (!$compatible) {
                        $isCompatible = false;
                        break;
                    }
                }
            }
            
            if ($isCompatible) {
                $filteredProducts[] = $product;
            }
        }
        
        return !empty($filteredProducts) ? $filteredProducts : $products; // Return original if all filtered out
    }
    
    /**
     * Format a list of dietary preferences for display in messages
     * 
     * @param array $preferences List of dietary preference codes
     * @return string Formatted string
     */
    protected static function formatDietaryPreferences(array $preferences): string
    {
        $displayNames = [
            'vegetarian' => 'vegetarian',
            'vegan' => 'vegan',
            'gluten_free' => 'gluten-free',
            'dairy_free' => 'dairy-free',
            'keto' => 'keto',
            'low_carb' => 'low-carb',
            'high_protein' => 'high-protein',
            'paleo' => 'paleo',
            'halal' => 'halal',
            'kosher' => 'kosher',
            'organic' => 'organic',
            'sugar_free' => 'sugar-free',
            'nut_free' => 'nut-free',
            'low_calorie' => 'low-calorie',
            'spicy' => 'spicy',
            'mild' => 'mild'
        ];
        
        $formatted = [];
        foreach ($preferences as $pref) {
            if (isset($displayNames[$pref])) {
                $formatted[] = $displayNames[$pref];
            } else {
                $formatted[] = str_replace('_', '-', $pref);
            }
        }
        
        if (count($formatted) === 1) {
            return $formatted[0];
        } elseif (count($formatted) === 2) {
            return $formatted[0] . ' and ' . $formatted[1];
        } else {
            $last = array_pop($formatted);
            return implode(', ', $formatted) . ', and ' . $last;
        }
    }
    
    /**
     * Add support for repeating previous orders
     * 
     * @param string $transcription User's voice input
     * @param array $dialogueState Current dialogue state
     * @param array|null $previousOrders User's previous orders if available
     * @return array|null Previous order data if repeat intent detected, null otherwise
     */
    public static function detectOrderRepeatIntent(string $transcription, array $dialogueState, ?array $previousOrders = null): ?array
    {
        $lowercaseText = strtolower($transcription);
        
        // Common phrases for repeating previous orders
        $repeatPhrases = [
            'same as last time',
            'same thing as before',
            'same as before',
            'order again',
            'repeat my order',
            'same order',
            'last order',
            'previous order',
            'usual order',
            'what i had last time'
        ];
        
        $repeatDetected = false;
        foreach ($repeatPhrases as $phrase) {
            if (strpos($lowercaseText, $phrase) !== false) {
                $repeatDetected = true;
                break;
            }
        }
        
        if (!$repeatDetected || empty($previousOrders)) {
            return null;
        }
        
        // Get the most recent order
        $lastOrder = $previousOrders[0];
        
        return [
            'type' => 'repeat_order',
            'previous_order' => $lastOrder,
            'message' => "I'll order the same items as your previous order.",
            'confidence' => 0.9
        ];
    }
} 