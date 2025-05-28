<?php

namespace App\Http\Middleware;

use App\Helpers\ResponseError;
use App\Traits\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class SanctumCheck
{
    use ApiResponse;

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // For debugging purposes, log the authentication headers
        Log::info('Auth headers in SanctumCheck:', [
            'authorization' => $request->header('Authorization'),
            'route' => $request->path(),
            'method' => $request->method()
        ]);

        // Check for public routes that should bypass authentication
        $publicRoutes = [
            'voice-order/transcribe',
            'voice-order/test-transcribe'
        ];
        
        // Extract the path from the full URL for comparison
        $path = trim($request->path(), '/');
        $path = preg_replace('#^api/v1/rest/#', '', $path); // Remove API prefix if present
        
        // Allow public routes to bypass authentication
        if (in_array($path, $publicRoutes)) {
            Log::info('Public voice API route accessed: ' . $path);
            return $next($request);
        }

        // For standard routes, require authentication
        if (auth('sanctum')->check()) {
            return $next($request);
        }

        // Log the unauthorized access attempt
        Log::warning('Unauthorized access attempt', [
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user-agent' => $request->userAgent()
        ]);

        return $this->errorResponse(
            'ERROR_100',  
            __('errors.' . ResponseError::ERROR_100, [], request('lang', 'en')), 
            Response::HTTP_UNAUTHORIZED
        );
    }
}
