<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Http\Controllers\Controller;
use App\Models\AIAssistantLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AIAssistantController extends Controller
{
    /**
     * Get AI Assistant usage statistics
     */
    public function getStatistics(Request $request)
    {
        // Base query
        $query = AIAssistantLog::query();
        
        // Filter by date range if provided
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
        
        $stats = [
            'total_requests' => $query->count(),
            'successful_requests' => (clone $query)->where('successful', true)->count(),
            'failed_requests' => (clone $query)->where('successful', false)->count(),
            'unique_users' => (clone $query)->distinct('user_id')->count('user_id'),
            'avg_processing_time' => (clone $query)->avg('processing_time_ms') ?? 0,
            'total_premium_users' => User::where('is_premium', true)->count(),
        ];
        
        return response()->json(['data' => $stats]);
    }
    
    /**
     * Get AI Assistant request logs
     */
    public function getLogs(Request $request)
    {
        $query = AIAssistantLog::with('user:id,firstname,lastname,email')
            ->latest();
            
        // Filter by request type if provided
        if ($request->has('request_type')) {
            $query->where('request_type', $request->input('request_type'));
        }
        
        // Filter by success status if provided
        if ($request->has('successful')) {
            $query->where('successful', $request->boolean('successful'));
        }
        
        // Filter by date range if provided
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
        
        $logs = $query->paginate($request->input('per_page', 15));
        
        return response()->json($logs);
    }
    
    /**
     * Get top AI filters detected
     */
    public function getTopFilters(Request $request)
    {
        $query = AIAssistantLog::where('filters_detected', '!=', null);
        
        // Filter by date range if provided
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
        
        $logs = $query->get();
        
        $filters = [];
        foreach ($logs as $log) {
            if (!empty($log->filters_detected['filters'])) {
                foreach ($log->filters_detected['filters'] as $filter) {
                    if (!isset($filters[$filter])) {
                        $filters[$filter] = 0;
                    }
                    $filters[$filter]++;
                }
            }
        }
        
        // Sort by count descending
        arsort($filters);
        
        // Convert to array format for response
        $result = [];
        foreach ($filters as $filter => $count) {
            $result[] = [
                'filter' => $filter,
                'count' => $count
            ];
        }
        
        return response()->json(['data' => array_slice($result, 0, 20)]);  // Return top 20
    }
    
    /**
     * Get top exclude ingredients
     */
    public function getTopExclusions(Request $request)
    {
        $query = AIAssistantLog::where('filters_detected', '!=', null);
        
        // Filter by date range if provided
        if ($request->has('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        
        if ($request->has('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }
        
        $logs = $query->get();
        
        $exclusions = [];
        foreach ($logs as $log) {
            if (!empty($log->filters_detected['exclusions'])) {
                foreach ($log->filters_detected['exclusions'] as $exclusion) {
                    if (!isset($exclusions[$exclusion])) {
                        $exclusions[$exclusion] = 0;
                    }
                    $exclusions[$exclusion]++;
                }
            }
        }
        
        // Sort by count descending
        arsort($exclusions);
        
        // Convert to array format for response
        $result = [];
        foreach ($exclusions as $exclusion => $count) {
            $result[] = [
                'exclusion' => $exclusion,
                'count' => $count
            ];
        }
        
        return response()->json(['data' => array_slice($result, 0, 20)]);  // Return top 20
    }
    
    /**
     * Update product AI metadata (calories, ingredients, allergens)
     */
    public function updateProductMetadata(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        
        $validated = $request->validate([
            'calories' => 'nullable|integer|min:0',
            'ingredient_tags' => 'nullable|array',
            'allergen_flags' => 'nullable|array',
            'representative_image' => 'nullable|string',
        ]);
        
        $product->update($validated);
        
        return response()->json(['message' => 'Product metadata updated', 'data' => $product]);
    }
    
    /**
     * Update user AI credits
     */
    public function updateUserCredits(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'ai_order_credits' => 'required|integer|min:0',
            'is_premium' => 'nullable|boolean',
            'premium_expires_at' => 'nullable|date',
        ]);
        
        $user->update($validated);
        
        return response()->json(['message' => 'User AI credits updated', 'data' => $user]);
    }
    
    /**
     * Generate a representative image for a product using AI
     */
    public function generateProductImage(Request $request, $id)
    {
        // This would use DALL-E API to generate an image
        // Just a placeholder for now
        return response()->json([
            'message' => 'Image generation is not implemented yet',
            'product_id' => $id
        ]);
    }
} 