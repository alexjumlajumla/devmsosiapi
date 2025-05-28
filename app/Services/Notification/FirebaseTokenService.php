<?php

namespace App\Services\Notification;

use App\Models\User;
use Kreait\Firebase\Contract\Auth;
use Illuminate\Support\Facades\Log;

class FirebaseTokenService
{
    protected $auth;

    public function __construct(Auth $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Verify and validate Firebase token
     */
    public function verifyToken(string $token): ?string
    {
        try {
            $verifiedToken = $this->auth->verifyIdToken($token);
            return $verifiedToken->claims()->get('sub'); // Returns Firebase UID
        } catch (\Throwable $e) {
            Log::error('Firebase token verification failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get all valid FCM tokens for a user
     *
     * @param int $userId
     * @return array
     */
    public function getUserTokens(int $userId): array
    {
        $user = User::find($userId);
        
        if (!$user || empty($user->firebase_token)) {
            Log::info('No FCM tokens found for user', ['user_id' => $userId]);
            return [];
        }
        
        $tokens = is_array($user->firebase_token) 
            ? $user->firebase_token 
            : [$user->firebase_token];
            
        $validTokens = [];
        
        foreach ($tokens as $token) {
            if ($this->verifyToken($token) !== null) {
                $validTokens[] = $token;
            } else {
                Log::info('Invalid FCM token found and removed', [
                    'user_id' => $userId,
                    'token' => substr($token, 0, 10) . '...' // Log partial token for security
                ]);
            }
        }
        
        // Update user's tokens to remove any invalid ones
        if (count($validTokens) !== count($tokens)) {
            $user->update([
                'firebase_token' => array_unique($validTokens)
            ]);
        }
        
        Log::info('Retrieved valid FCM tokens for user', [
            'user_id' => $userId,
            'valid_token_count' => count($validTokens)
        ]);
        
        return $validTokens;
    }
    
    /**
     * Clean up invalid tokens for a user
     */
    public function cleanupInvalidTokens(User $user): void
    {
        if (empty($user->firebase_token)) {
            return;
        }

        $tokens = is_array($user->firebase_token) 
            ? $user->firebase_token 
            : [$user->firebase_token];

        $validTokens = [];
        
        foreach ($tokens as $token) {
            if ($this->verifyToken($token) !== null) {
                $validTokens[] = $token;
            }
        }

        if (count($validTokens) !== count($tokens)) {
            $user->update([
                'firebase_token' => array_unique($validTokens)
            ]);
            
            Log::info('Cleaned up invalid Firebase tokens for user: ' . $user->id);
        }
    }
}
