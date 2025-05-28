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
     * 
     * @param string $token The Firebase ID token to verify
     * @return string|null The Firebase UID if valid, null otherwise
     */
    public function verifyToken(string $token): ?string
    {
        try {
            if (empty($token)) {
                Log::warning('Empty token provided for verification');
                return null;
            }
            
            $verifiedToken = $this->auth->verifyIdToken($token);
            $uid = $verifiedToken->claims()->get('sub');
            
            Log::debug('Successfully verified Firebase token', [
                'uid' => $uid,
                'token_prefix' => substr($token, 0, 10) . '...',
                'expires_at' => $verifiedToken->claims()->get('exp')
            ]);
            
            return $uid;
            
        } catch (\Throwable $e) {
            Log::error('Firebase token verification failed', [
                'error' => $e->getMessage(),
                'token_prefix' => !empty($token) ? substr($token, 0, 10) . '...' : 'empty',
                'exception' => get_class($e)
            ]);
            return null;
        }
    }
    
    /**
     * Verify if an FCM token is valid by checking its format and structure
     * 
     * @param string $token The FCM token to verify
     * @return bool True if the token is valid, false otherwise
     */
    public function isFcmTokenValid(string $token): bool
    {
        // Basic validation
        if (empty($token) || !is_string($token)) {
            return false;
        }
        
        // FCM tokens are typically 152-163 characters long
        $length = strlen($token);
        if ($length < 100 || $length > 200) {
            Log::debug('FCM token has invalid length', [
                'length' => $length,
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);
            return false;
        }
        
        // Check token format (alphanumeric, underscore, hyphen, colon)
        if (!preg_match('/^[a-zA-Z0-9_\-:]+$/', $token)) {
            Log::debug('FCM token has invalid format', [
                'token_prefix' => substr($token, 0, 10) . '...',
                'length' => $length
            ]);
            return false;
        }
        
        return true;
    }

    /**
     * Get all valid FCM tokens for a user
     *
     * @param int $userId The ID of the user to get tokens for
     * @return array Array of valid FCM tokens
     */
    public function getUserTokens(int $userId): array
    {
        $logContext = ['user_id' => $userId];
        Log::debug('Fetching FCM tokens for user', $logContext);
        
        $user = User::find($userId);
        
        if (!$user) {
            Log::warning('User not found when fetching FCM tokens', $logContext);
            return [];
        }
        
        if (empty($user->firebase_token)) {
            Log::info('No FCM tokens found for user', $logContext);
            return [];
        }
        
        // Convert to array if it's a single token
        $tokens = is_array($user->firebase_token) 
            ? $user->firebase_token 
            : [$user->firebase_token];
            
        $validTokens = [];
        $invalidTokens = [];
        
        foreach ($tokens as $token) {
            if ($this->isFcmTokenValid($token)) {
                $validTokens[] = $token;
            } else {
                $invalidTokens[] = $token;
                Log::info('Invalid FCM token found', [
                    'user_id' => $userId,
                    'token_prefix' => substr($token, 0, 10) . '...',
                    'token_length' => strlen($token)
                ]);
            }
        }
        
        // Remove duplicates
        $validTokens = array_values(array_unique($validTokens));
        
        // Update user's tokens to remove invalid ones if needed
        if (count($validTokens) !== count($tokens)) {
            try {
                $user->update([
                    'firebase_token' => !empty($validTokens) ? $validTokens : null
                ]);
                
                Log::info('Cleaned invalid FCM tokens for user', [
                    'user_id' => $userId,
                    'valid_tokens' => count($validTokens),
                    'invalid_tokens' => count($invalidTokens)
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to update user with cleaned FCM tokens', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
        
        Log::debug('Retrieved valid FCM tokens for user', [
            'user_id' => $userId,
            'valid_token_count' => count($validTokens),
            'invalid_token_count' => count($invalidTokens)
        ]);
        
        return $validTokens;
    }
    
    /**
     * Validate FCM token format
     * 
     * @param string $token
     * @return bool
     */
    /**
     * Check if an FCM token is valid
     * 
     * @param string $token
     * @return bool
     */
    public function isValidFcmToken(string $token): bool
    {
        // Basic validation
        if (empty($token) || !is_string($token)) {
            return false;
        }
        
        // FCM tokens are typically 152-163 characters long
        $length = strlen($token);
        if ($length < 100 || $length > 200) {
            Log::debug('FCM token has invalid length', [
                'length' => $length,
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);
            return false;
        }
        
        // Check token format (alphanumeric, underscore, hyphen, colon)
        if (!preg_match('/^[a-zA-Z0-9_\-:]+$/', $token)) {
            Log::debug('FCM token has invalid format', [
                'token_prefix' => substr($token, 0, 10) . '...',
                'length' => $length
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Add an FCM token for a user
     * 
     * @param int $userId
     * @param string $token
     * @return bool True if token was added, false if invalid or already exists
     */
    public function addToken(int $userId, string $token): bool
    {
        if (!$this->isValidFcmToken($token)) {
            Log::warning('Attempted to add invalid FCM token', [
                'user_id' => $userId,
                'token_prefix' => substr($token, 0, 10) . '...',
                'token_length' => strlen($token)
            ]);
            return false;
        }
        
        $user = User::findOrFail($userId);
        $tokens = [];
        
        if (!empty($user->firebase_token)) {
            $tokens = is_array($user->firebase_token) 
                ? $user->firebase_token 
                : [$user->firebase_token];
            
            // Check if token already exists
            if (in_array($token, $tokens, true)) {
                Log::debug('FCM token already exists for user', [
                    'user_id' => $userId,
                    'token_prefix' => substr($token, 0, 10) . '...'
                ]);
                return true;
            }
        }
        
        $tokens[] = $token;
        $user->firebase_token = array_unique($tokens);
        $saved = $user->save();
        
        if ($saved) {
            Log::info('Added FCM token for user', [
                'user_id' => $userId,
                'token_count' => count($tokens)
            ]);
        } else {
            Log::error('Failed to save FCM token for user', [
                'user_id' => $userId,
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);
        }
        
        return $saved;
    }
    
    /**
     * Remove an FCM token for a user
     * 
     * @param int $userId
     * @param string $token
     * @return bool True if token was removed, false if not found
     */
    public function removeToken(int $userId, string $token): bool
    {
        $user = User::findOrFail($userId);
        
        if (empty($user->firebase_token)) {
            return false;
        }
        
        $tokens = is_array($user->firebase_token) 
            ? $user->firebase_token 
            : [$user->firebase_token];
            
        $initialCount = count($tokens);
        $tokens = array_filter($tokens, function($t) use ($token) {
            return $t !== $token;
        });
        
        if (count($tokens) === $initialCount) {
            Log::debug('FCM token not found for user', [
                'user_id' => $userId,
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);
            return false;
        }
        
        $user->firebase_token = array_values($tokens); // Reset array keys
        $saved = $user->save();
        
        if ($saved) {
            Log::info('Removed FCM token for user', [
                'user_id' => $userId,
                'remaining_tokens' => count($tokens)
            ]);
        } else {
            Log::error('Failed to remove FCM token for user', [
                'user_id' => $userId,
                'token_prefix' => substr($token, 0, 10) . '...'
            ]);
        }
        
        return $saved;
    }
    
    /**
     * Clean up invalid tokens for a user
     * 
     * @param User $user The user to clean up tokens for
     * @return array Result of the cleanup operation
     */
    public function cleanupInvalidTokens(User $user): array
    {
        $logContext = ['user_id' => $user->id];
        Log::debug('Starting cleanup of invalid FCM tokens for user', $logContext);
        
        if (empty($user->firebase_token)) {
            Log::debug('No FCM tokens to clean up for user', $logContext);
            return [
                'user_id' => $user->id,
                'valid_tokens' => 0,
                'invalid_tokens' => 0,
                'status' => 'no_tokens'
            ];
        }

        // Convert to array if it's a single token
        $tokens = is_array($user->firebase_token) 
            ? $user->firebase_token 
            : [$user->firebase_token];

        $validTokens = [];
        $invalidTokens = [];
        
        foreach ($tokens as $token) {
            if ($this->isFcmTokenValid($token)) {
                $validTokens[] = $token;
            } else {
                $invalidTokens[] = $token;
                Log::info('Found invalid FCM token during cleanup', [
                    'user_id' => $user->id,
                    'token_prefix' => substr($token, 0, 10) . '...',
                    'token_length' => strlen($token)
                ]);
            }
        }
        
        // Remove duplicates
        $validTokens = array_values(array_unique($validTokens));
        
        $result = [
            'user_id' => $user->id,
            'total_tokens' => count($tokens),
            'valid_tokens' => count($validTokens),
            'invalid_tokens' => count($invalidTokens),
            'status' => 'success'
        ];

        // Only update if we found invalid tokens
        if (count($invalidTokens) > 0) {
            try {
                $user->update([
                    'firebase_token' => !empty($validTokens) ? $validTokens : null
                ]);
                
                Log::info('Successfully cleaned up invalid FCM tokens', array_merge($logContext, [
                    'removed_count' => count($invalidTokens),
                    'remaining_count' => count($validTokens)
                ]));
                
            } catch (\Exception $e) {
                Log::error('Failed to clean up invalid FCM tokens', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $result['status'] = 'error';
                $result['error'] = $e->getMessage();
            }
        } else {
            Log::debug('No invalid FCM tokens found during cleanup', $logContext);
        }
        
        return $result;
    }
}
