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
