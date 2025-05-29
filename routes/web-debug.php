<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

Route::get('/debug/fcm-token/{userId}', function ($userId) {
    $user = User::find($userId);
    
    if (!$user) {
        return "User not found";
    }
    
    // Get raw token data
    $rawTokenData = $user->firebase_token;
    $tokenType = gettype($rawTokenData);
    
    // Get processed tokens
    $processedTokens = $user->getFcmTokens();
    
    // Log the data
    Log::info('Web Debug - FCM Token', [
        'user_id' => $user->id,
        'email' => $user->email,
        'raw_token_type' => $tokenType,
        'raw_token_sample' => is_string($rawTokenData) ? 
            (strlen($rawTokenData) > 20 ? substr($rawTokenData, 0, 20) . '...' : $rawTokenData) : 
            (is_array($rawTokenData) ? json_encode(array_slice($rawTokenData, 0, 3)) : 'N/A'),
        'processed_tokens_count' => count($processedTokens),
        'is_json' => $tokenType === 'string' && json_decode($rawTokenData) !== null,
    ]);
    
    // Output the data
    echo "<h1>FCM Token Debug - User ID: {$user->id}</h1>";
    echo "<h2>User: {$user->email}</h2>";
    
    echo "<h3>Raw Token Data</h3>";
    echo "<pre>";
    echo "Type: " . $tokenType . "\n";
    echo "Value: " . htmlspecialchars(print_r($rawTokenData, true)) . "\n";
    echo "</pre>";
    
    echo "<h3>Processed Tokens</h3>";
    echo "<pre>";
    print_r($processedTokens);
    echo "</pre>";
    
    echo "<h3>Debug Info</h3>";
    echo "<pre>";
    echo "Is Array: " . (is_array($rawTokenData) ? 'Yes' : 'No') . "\n";
    echo "Is String: " . (is_string($rawTokenData) ? 'Yes' : 'No') . "\n";
    echo "Is Null: " . (is_null($rawTokenData) ? 'Yes' : 'No') . "\n";
    echo "Is Object: " . (is_object($rawTokenData) ? 'Yes' : 'No') . "\n";
    echo "Is Countable: " . (is_countable($rawTokenData) ? 'Yes' : 'No') . "\n";
    if (is_countable($rawTokenData)) {
        echo "Count: " . count($rawTokenData) . "\n";
    }
    echo "</pre>";
    
    die();
})->middleware('web');
