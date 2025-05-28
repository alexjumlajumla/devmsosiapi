<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Helper class for handling voice confidence tiers and recommendations filtering
 */
class VoiceConfidenceHelper
{
    /**
     * Get the minimum confidence threshold that should be processed
     * 
     * @return float Minimum confidence threshold (0.0 to 1.0)
     */
    public static function getMinConfidenceThreshold(): float
    {
        return Cache::get('voice_order.min_confidence_threshold', 0.3);
    }
    
    /**
     * Check if a transcription confidence level is acceptable for processing
     * 
     * @param float $confidenceScore The confidence score (0.0 to 1.0)
     * @return bool True if the confidence is acceptable, false otherwise
     */
    public static function isAcceptableConfidence(float $confidenceScore): bool
    {
        return $confidenceScore >= self::getMinConfidenceThreshold();
    }
    
    /**
     * Get the confidence tier for a given confidence score
     * 
     * @param float $confidenceScore The confidence score (0.0 to 1.0)
     * @return string The confidence tier (very_low, low, medium, high)
     */
    public static function getConfidenceTier(float $confidenceScore): string
    {
        $tiers = Cache::get('voice_order.confidence_tiers', [
            'very_low' => 0.3,
            'low' => 0.5,
            'medium' => 0.7,
            'high' => 0.9
        ]);
        
        if ($confidenceScore < $tiers['very_low']) {
            return 'very_low';
        } elseif ($confidenceScore < $tiers['low']) {
            return 'low';
        } elseif ($confidenceScore < $tiers['medium']) {
            return 'medium';
        } elseif ($confidenceScore < $tiers['high']) {
            return 'high';
        } else {
            return 'very_high';
        }
    }
    
    /**
     * Should we show recommendations for this confidence level?
     * 
     * @param float $confidenceScore The confidence score (0.0 to 1.0)
     * @return bool True if recommendations should be shown
     */
    public static function shouldShowRecommendations(float $confidenceScore): bool
    {
        // Always show recommendations if process_low_confidence is true
        if (Cache::get('voice_order.process_low_confidence', true)) {
            return true;
        }
        
        // Otherwise only show if confidence is acceptable
        return self::isAcceptableConfidence($confidenceScore);
    }
    
    /**
     * Filter recommendations based on confidence score
     * 
     * @param Collection|array $recommendations The recommendations collection or array
     * @param float $confidenceScore The confidence score (0.0 to 1.0)
     * @return Collection|array Filtered recommendations
     */
    public static function filterRecommendationsByConfidence($recommendations, float $confidenceScore)
    {
        // If the confidence is too low and we're not showing recommendations for low confidence,
        // return an empty collection/array
        if (!self::shouldShowRecommendations($confidenceScore)) {
            return $recommendations instanceof Collection ? collect([]) : [];
        }
        
        // For medium confidence, limit the number of recommendations to avoid overwhelming
        if ($confidenceScore < 0.7 && count($recommendations) > 5) {
            if ($recommendations instanceof Collection) {
                return $recommendations->take(5);
            } else {
                return array_slice($recommendations, 0, 5);
            }
        }
        
        // For low confidence, limit even further
        if ($confidenceScore < 0.5 && count($recommendations) > 3) {
            if ($recommendations instanceof Collection) {
                return $recommendations->take(3);
            } else {
                return array_slice($recommendations, 0, 3);
            }
        }
        
        // For high confidence, return all recommendations
        return $recommendations;
    }
    
    /**
     * Get confidence guidance message based on confidence score
     * 
     * @param float $confidenceScore The confidence score (0.0 to 1.0)
     * @return string|null Guidance message or null if not needed
     */
    public static function getConfidenceGuidanceMessage(float $confidenceScore): ?string
    {
        $tier = self::getConfidenceTier($confidenceScore);
        
        switch ($tier) {
            case 'very_low':
                return "I'm not confident I understood your request correctly. Please try speaking more clearly or use one of the suggested options.";
                
            case 'low':
                return "I'm not entirely confident I understood correctly. These recommendations might not match what you're looking for.";
                
            case 'medium':
                return "I understood most of your request. Please confirm if these recommendations match what you wanted.";
                
            default:
                return null; // No guidance needed for high confidence
        }
    }
} 