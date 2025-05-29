<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

Route::get('/debug/fcm-token/{userId}', function ($userId) {
    $user = User::find($userId);
    
    if (!$user) {
        return response()->json(['error' => 'User not found'], 404);
    }
    
    // Get raw token data
    $rawTokenData = $user->firebase_token;
    $tokenType = gettype($rawTokenData);
    
    // Get processed tokens
    $processedTokens = $user->getFcmTokens();
    
    // Log the data
    $logData = [
        'user_id' => $user->id,
        'email' => $user->email,
        'raw_token_type' => $tokenType,
        'raw_token_sample' => is_string($rawTokenData) ? 
            (strlen($rawTokenData) > 20 ? substr($rawTokenData, 0, 20) . '...' : $rawTokenData) : 
            (is_array($rawTokenData) ? json_encode(array_slice($rawTokenData, 0, 3)) : 'N/A'),
        'processed_tokens_count' => count($processedTokens),
        'processed_tokens_sample' => array_slice($processedTokens, 0, 3),
        'is_json' => $tokenType === 'string' && json_decode($rawTokenData) !== null,
    ];
    
    Log::info('FCM Token Debug', $logData);
    
    return response()->json([
        'user' => [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
        ],
        'token_info' => [
            'raw_type' => $tokenType,
            'raw_sample' => $rawTokenData ? substr(json_encode($rawTokenData), 0, 200) . (strlen(json_encode($rawTokenData)) > 200 ? '...' : '') : null,
            'processed_tokens' => $processedTokens,
            'processed_count' => count($processedTokens),
        ],
        'debug' => [
            'is_array' => is_array($rawTokenData),
            'is_string' => is_string($rawTokenData),
            'is_null' => is_null($rawTokenData),
            'is_object' => is_object($rawTokenData),
            'count' => is_countable($rawTokenData) ? count($rawTokenData) : 0,
        ]
    ]);
});
