<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

class VerifyClient
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            // Check if user is authenticated
            if (!auth('sanctum')->check()) {
                \Log::warning('Unauthenticated access attempt', [
                    'ip' => $request->ip(),
                    'url' => $request->fullUrl(),
                    'headers' => $request->headers->all()
                ]);
                
                throw new AuthenticationException('Authentication required');
            }

            // Get authenticated user
            $user = auth('sanctum')->user();
            
            if (!$user) {
                \Log::error('No user found despite valid authentication', [
                    'token_exists' => $request->bearerToken() ? 'yes' : 'no'
                ]);
                throw new AuthenticationException('Invalid authentication state');
            }

            // Add user info to log context
            \Log::withContext(['user_id' => $user->id]);

            return $next($request);

        } catch (AuthenticationException $e) {
            \Log::error('Authentication failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
            
        } catch (\Exception $e) {
            \Log::error('Verification error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            throw new AuthenticationException(
                'Authentication failed: ' . $e->getMessage()
            );
        }
    }
}
