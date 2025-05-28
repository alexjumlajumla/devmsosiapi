<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifySelcomWebhook
{
    public function handle(Request $request, Closure $next)
    {
        // Verify Selcom webhook signature
        $signature = $request->header('X-Selcom-Signature');
        
        if (!$signature) {
            return response()->json(['error' => 'Missing signature'], 400);
        }

        // Get configured secret
        $secret = config('services.selcom.webhook_secret');
        
        // Calculate expected signature
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if ($signature !== $expectedSignature) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        return $next($request);
    }
}
