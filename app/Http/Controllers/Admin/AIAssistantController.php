<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AIAssistantLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AIAssistantController extends Controller
{
    /**
     * Get AI Assistant statistics for the dashboard
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStatistics()
    {
        try {
            // Total requests
            $totalRequests = AIAssistantLog::count();
            $successfulRequests = AIAssistantLog::where('successful', true)->count();
            $failedRequests = $totalRequests - $successfulRequests;
            
            // Average processing time
            $avgProcessingTime = AIAssistantLog::avg('processing_time_ms');
            
            // Active users (users who have used the AI assistant in the last 30 days)
            $activeUsers = AIAssistantLog::where('created_at', '>=', Carbon::now()->subDays(30))
                ->distinct('user_id')
                ->count('user_id');
                
            // Request types distribution
            $requestTypes = AIAssistantLog::select('request_type', DB::raw('count(*) as value'))
                ->groupBy('request_type')
                ->get()
                ->map(function ($item) {
                    return [
                        'type' => $item->request_type,
                        'value' => $item->value
                    ];
                });
                
            // Usage by date (last 14 days)
            $usageByDate = [];
            $startDate = Carbon::now()->subDays(14);
            $endDate = Carbon::now();
            
            for ($date = $startDate; $date->lte($endDate); $date->addDay()) {
                $dayRequests = AIAssistantLog::whereDate('created_at', $date->format('Y-m-d'))->count();
                $successfulDayRequests = AIAssistantLog::whereDate('created_at', $date->format('Y-m-d'))
                    ->where('successful', true)
                    ->count();
                
                $usageByDate[] = [
                    'date' => $date->format('Y-m-d'),
                    'count' => $dayRequests,
                    'type' => 'Total'
                ];
                
                $usageByDate[] = [
                    'date' => $date->format('Y-m-d'),
                    'count' => $successfulDayRequests,
                    'type' => 'Successful'
                ];
            }
            
            // Count by type
            $voiceOrderCount = AIAssistantLog::where('request_type', 'voice_order')->count();
            $textChatCount = AIAssistantLog::where('request_type', 'text')->count();
            
            // Today stats
            $todayRequests = AIAssistantLog::whereDate('created_at', Carbon::today())->count();
            $yesterdayRequests = AIAssistantLog::whereDate('created_at', Carbon::yesterday())->count();
            
            $todayVsYesterday = 0;
            if ($yesterdayRequests > 0) {
                $todayVsYesterday = round((($todayRequests - $yesterdayRequests) / $yesterdayRequests) * 100);
            }
            
            $todayAvgTime = AIAssistantLog::whereDate('created_at', Carbon::today())->avg('processing_time_ms');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total_requests' => $totalRequests,
                    'successful_requests' => $successfulRequests,
                    'failed_requests' => $failedRequests,
                    'avg_processing_time' => round($avgProcessingTime),
                    'active_users' => $activeUsers,
                    'request_types' => $requestTypes,
                    'usage_by_date' => $usageByDate,
                    'voice_order_count' => $voiceOrderCount,
                    'text_chat_count' => $textChatCount,
                    'today_requests' => $todayRequests,
                    'today_vs_yesterday' => $todayVsYesterday,
                    'today_avg_time' => round($todayAvgTime),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting AI Assistant statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve AI Assistant statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get AI Assistant logs with pagination and filters
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLogs(Request $request)
    {
        try {
            $query = AIAssistantLog::with('user');
            
            // Apply filters
            if ($request->has('request_type') && $request->request_type) {
                $query->where('request_type', $request->request_type);
            }
            
            if ($request->has('successful') && $request->successful !== '') {
                $query->where('successful', $request->successful == 1);
            }
            
            if ($request->has('date_from') && $request->date_from) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            
            if ($request->has('date_to') && $request->date_to) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }
            
            // Order by latest first
            $query->orderBy('created_at', 'desc');
            
            // Paginate results
            $perPage = $request->input('per_page', 15);
            $logs = $query->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $logs->items(),
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting AI Assistant logs: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve AI Assistant logs',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get top food filters used in AI Assistant
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTopFilters()
    {
        try {
            // This is a simplified example - in a real app, you would extract this from AI logs
            // For example, from metadata fields that contain filter information
            $filters = [];
            $total = 0;
            
            // Get logs with filters_detected data
            $logsWithFilters = AIAssistantLog::whereNotNull('filters_detected')
                ->where('filters_detected', '!=', '[]')
                ->get();
                
            // Count filter occurrences
            $filterCounts = [];
            
            foreach ($logsWithFilters as $log) {
                if (empty($log->filters_detected)) continue;
                
                $filtersData = is_string($log->filters_detected) 
                    ? json_decode($log->filters_detected, true) 
                    : $log->filters_detected;
                
                if (!is_array($filtersData)) continue;
                
                foreach ($filtersData as $filter) {
                    if (!isset($filter['name'])) continue;
                    
                    $filterName = $filter['name'];
                    if (!isset($filterCounts[$filterName])) {
                        $filterCounts[$filterName] = 0;
                    }
                    
                    $filterCounts[$filterName]++;
                    $total++;
                }
            }
            
            // Convert to output format
            foreach ($filterCounts as $name => $count) {
                $filters[] = [
                    'filter' => $name,
                    'count' => $count,
                    'percentage' => $total > 0 ? round(($count / $total) * 100) : 0
                ];
            }
            
            // Sort by count (descending)
            usort($filters, function($a, $b) {
                return $b['count'] - $a['count'];
            });
            
            // Take top 10
            $filters = array_slice($filters, 0, 10);
            
            return response()->json([
                'success' => true,
                'data' => $filters
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting top filters: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve top filters',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get top food exclusions used in AI Assistant
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTopExclusions()
    {
        try {
            // Similar approach to getTopFilters, but for exclusions
            // In a real app, you would extract from your AI logs based on your data structure
            $exclusions = [];
            $total = 0;
            
            // Get logs with metadata containing exclusions
            $logsWithExclusions = AIAssistantLog::whereNotNull('metadata')
                ->where('metadata', 'like', '%exclusions%')
                ->get();
                
            // Count exclusion occurrences
            $exclusionCounts = [];
            
            foreach ($logsWithExclusions as $log) {
                if (empty($log->metadata)) continue;
                
                $metadata = is_string($log->metadata) 
                    ? json_decode($log->metadata, true) 
                    : $log->metadata;
                
                if (!is_array($metadata) || !isset($metadata['exclusions'])) continue;
                
                foreach ($metadata['exclusions'] as $exclusion) {
                    if (!isset($exclusionCounts[$exclusion])) {
                        $exclusionCounts[$exclusion] = 0;
                    }
                    
                    $exclusionCounts[$exclusion]++;
                    $total++;
                }
            }
            
            // Convert to output format
            foreach ($exclusionCounts as $name => $count) {
                $exclusions[] = [
                    'exclusion' => $name,
                    'count' => $count,
                    'percentage' => $total > 0 ? round(($count / $total) * 100) : 0
                ];
            }
            
            // Sort by count (descending)
            usort($exclusions, function($a, $b) {
                return $b['count'] - $a['count'];
            });
            
            // Take top 10
            $exclusions = array_slice($exclusions, 0, 10);
            
            return response()->json([
                'success' => true,
                'data' => $exclusions
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting top exclusions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve top exclusions',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get detailed information for a specific log
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLog($id)
    {
        try {
            $log = AIAssistantLog::with('user')->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'log' => $log,
                'debug_info' => [
                    'metadata' => $log->metadata,
                    'filters_detected' => $log->filters_detected,
                    'product_ids' => $log->product_ids,
                    'session_id' => $log->session_id,
                    'processing_details' => [
                        'processing_time_ms' => $log->processing_time_ms,
                        'created_at' => $log->created_at,
                        'updated_at' => $log->updated_at,
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting AI Assistant log: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve log details',
                'error' => $e->getMessage()
            ], 404);
        }
    }
} 