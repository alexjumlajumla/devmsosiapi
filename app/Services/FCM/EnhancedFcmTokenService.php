<?php

namespace App\Services\FCM;

use App\Services\FCM\FcmTokenServiceInterface;

use App\Models\User;
use App\Models\PushNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification as FcmMessageNotification;
use Throwable;

class EnhancedFcmTokenService implements FcmTokenServiceInterface
{
    /**
     * Cache key for FCM tokens
     */
    protected const CACHE_PREFIX = 'fcm_tokens:';
    
    /**
     * Cache TTL in seconds (1 week)
     */
    protected const CACHE_TTL = 604800;
    
    /**
     * Maximum number of tokens per user
     */
    protected const MAX_TOKENS_PER_USER = 10;
    
    /**
     * Maximum number of tokens to process in a single batch
     */
    protected const MAX_BATCH_SIZE = 500;

    /**
     * Add an FCM token for a user
     * 
     * @param User $user
     * @param string $token
     * @param string|null $deviceId Optional device identifier
     * @return bool
     */
    public function addToken(User $user, string $token, ?string $deviceId = null): bool
    {
        try {
            if (!$this->isValidFcmToken($token)) {
                $this->log('warning', 'Invalid FCM token format', [
                    'user_id' => $user->id,
                    'token' => $token,
                    'device_id' => $deviceId
                ]);
                return false;
            }

            return DB::transaction(function () use ($user, $token, $deviceId) {
                // Get current tokens with metadata
                $tokens = $this->getUserTokensWithMetadata($user);
                $now = now()->toDateTimeString();
                $tokenExists = false;
                
                // Check if token already exists
                foreach ($tokens as &$tokenData) {
                    if ($tokenData['token'] === $token) {
                        // Update last used timestamp
                        $tokenData['last_used_at'] = $now;
                        if ($deviceId) {
                            $tokenData['device_id'] = $deviceId;
                        }
                        $tokenExists = true;
                        break;
                    }
                }
                
                if (!$tokenExists) {
                    // Add new token
                    $newToken = [
                        'token' => $token,
                        'created_at' => $now,
                        'last_used_at' => $now,
                        'device_id' => $deviceId,
                        'platform' => $this->detectPlatform($token)
                    ];
                    
                    // Enforce maximum tokens per user
                    if (count($tokens) >= self::MAX_TOKENS_PER_USER) {
                        // Remove oldest used token
                        usort($tokens, function ($a, $b) {
                            return strtotime($a['last_used_at'] ?? '1970-01-01') <=> strtotime($b['last_used_at'] ?? '1970-01-01');
                        });
                        array_shift($tokens);
                    }
                    
                    $tokens[] = $newToken;
                }
                
                // Save tokens
                $user->firebase_token = array_values($tokens);
                $user->save();
                
                // Clear cache
                $this->clearUserTokensCache($user->id);
                
                $this->log('info', $tokenExists ? 'Updated FCM token' : 'Added new FCM token', [
                    'user_id' => $user->id,
                    'token_count' => count($tokens),
                    'token_prefix' => substr($token, 0, 10) . '...' . substr($token, -5),
                    'device_id' => $deviceId
                ]);
                
                return true;
            });
            
        } catch (\Exception $e) {
            $this->log('error', 'Failed to add/update FCM token', [
                'user_id' => $user->id ?? null,
                'token' => $token,
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }

    /**
     * Remove an FCM token from a user
     * 
     * @param User $user
     * @param string $token
     * @return bool True if token was found and removed, false otherwise
     */
    public function removeToken(User $user, string $token): bool
    {
        try {
            return DB::transaction(function () use ($user, $token) {
                $tokens = $this->getUserTokensWithMetadata($user);
                $initialCount = count($tokens);
                
                // Filter out the token to be removed
                $tokens = array_values(array_filter($tokens, function($t) use ($token) {
                    return $t['token'] !== $token;
                }));
                
                if (count($tokens) !== $initialCount) {
                    $user->firebase_token = $tokens;
                    $user->save();
                    
                    // Clear cache
                    $this->clearUserTokensCache($user->id);
                    
                    $this->log('info', 'Removed FCM token from user', [
                        'user_id' => $user->id,
                        'remaining_tokens' => count($tokens),
                        'token_prefix' => substr($token, 0, 10) . '...' . substr($token, -5)
                    ]);
                    
                    return true;
                }
                
                $this->log('debug', 'FCM token not found for user', [
                    'user_id' => $user->id,
                    'token_prefix' => substr($token, 0, 10) . '...' . substr($token, -5)
                ]);
                
                return false;
            });
            
        } catch (\Exception $e) {
            $this->log('error', 'Failed to remove FCM token', [
                'user_id' => $user->id ?? null,
                'token_prefix' => isset($token) ? (substr($token, 0, 10) . '...' . substr($token, -5)) : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }

    /**
     * Get user's FCM tokens with metadata
     * 
     * @param User $user
     * @return array Array of token data with metadata
     */
    public function getUserTokensWithMetadata(User $user): array
    {
        $cacheKey = $this->getUserTokensCacheKey($user->id);
        
        // Try to get from cache first
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            $tokens = [];
            $rawTokens = $user->firebase_token;
            
            if (empty($rawTokens)) {
                return [];
            }
            
            // Handle JSON string
            if (is_string($rawTokens)) {
                $decoded = json_decode($rawTokens, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $rawTokens = $decoded;
                } else if ($this->isValidFcmToken($rawTokens)) {
                    // Single token as string
                    return [[
                        'token' => $rawTokens,
                        'created_at' => now()->toDateTimeString(),
                        'last_used_at' => now()->toDateTimeString(),
                        'platform' => $this->detectPlatform($rawTokens)
                    ]];
                }
            }
            
            // Handle array of tokens (with or without metadata)
            if (is_array($rawTokens)) {
                foreach ($rawTokens as $tokenData) {
                    if (is_string($tokenData) && $this->isValidFcmToken($tokenData)) {
                        $tokens[] = [
                            'token' => $tokenData,
                            'created_at' => now()->toDateTimeString(),
                            'last_used_at' => now()->toDateTimeString(),
                            'platform' => $this->detectPlatform($tokenData)
                        ];
                    } elseif (is_array($tokenData) && !empty($tokenData['token']) && $this->isValidFcmToken($tokenData['token'])) {
                        // Ensure required fields exist
                        $tokenData['platform'] = $tokenData['platform'] ?? $this->detectPlatform($tokenData['token']);
                        $tokenData['created_at'] = $tokenData['created_at'] ?? now()->toDateTimeString();
                        $tokenData['last_used_at'] = $tokenData['last_used_at'] ?? now()->toDateTimeString();
                        
                        $tokens[] = $tokenData;
                    }
                }
            }
            
            return $tokens;
        });
    }
    
    /**
     * Get user's FCM tokens as a simple array
     * 
     * @param User $user
     * @return array Array of token strings
     */
    public function getUserTokens(User $user): array
    {
        $tokens = $this->getUserTokensWithMetadata($user);
        return array_column($tokens, 'token');
    }

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
        
        // Check for test tokens in non-production environments
        $isTestEnv = app()->environment('local', 'staging', 'development');
        $allowTestTokens = filter_var(env('FIREBASE_ALLOW_TEST_TOKENS', $isTestEnv), FILTER_VALIDATE_BOOLEAN);
        
        // Test token pattern (starts with 'test_' or 'test_fcm_token_')
        $isTestToken = str_starts_with($token, 'test_') || str_starts_with($token, 'test_fcm_token_');
        
        if ($isTestToken) {
            return $allowTestTokens;
        }
        
        // Standard FCM token validation
        // FCM tokens are typically 152-163 characters long
        $length = strlen($token);
        if ($length < 100 || $length > 200) {
            return false;
        }
        
        // Check token format (alphanumeric, plus some special chars)
        return (bool) preg_match('/^[a-zA-Z0-9\-_.~%]+$/', $token);
    }

    /**
     * Detect platform from token
     * 
     * @param string $token
     * @return string
     */
    protected function detectPlatform(string $token): string
    {
        // This is a simplified detection based on common patterns
        // You may need to adjust based on your app's token format
        
        if (str_contains($token, 'APA91')) {
            return 'android';
        }
        
        if (str_contains($token, 'APns_') || str_contains($token, 'apns-')) {
            return 'ios';
        }
        
        return 'web';
    }

    /**
     * Clear user's token cache
     * 
     * @param int $userId
     * @return void
     */
    protected function clearUserTokensCache(int $userId): void
    {
        Cache::forget($this->getUserTokensCacheKey($userId));
    }

    /**
     * Get cache key for user's tokens
     * 
     * @param int $userId
     * @return string
     */
    protected function getUserTokensCacheKey(int $userId): string
    {
        return self::CACHE_PREFIX . $userId;
    }

    /**
     * Log a message
     * 
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $message = '[FCM] ' . $message;
        
        // Mask sensitive data in context
        if (isset($context['token'])) {
            $context['token'] = substr($context['token'], 0, 10) . '...' . substr($context['token'], -5);
        }
        
        Log::log($level, $message, $context);
    }
}
