<?php

namespace App\Services\FCM;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\Notification as FcmMessageNotification;

class FcmTokenService
{
    /**
     * The log prefix for FCM related logs
     */
    protected const LOG_PREFIX = '[FCM]';

    /**
     * Add an FCM token to a user's list of tokens
     * 
     * @param User $user
     * @param string $token
     * @return bool
     */
    public function addToken(User $user, string $token): bool
    {
        try {
            if (!$this->validateAndFilterTokens([$token])) {
                $this->log('warning', 'Invalid FCM token format', ['token' => $token]);
                return false;
            }

            return DB::transaction(function () use ($user, $token) {
                $tokens = $this->getUserTokens($user);
                
                // Add token if not already present
                if (!in_array($token, $tokens, true)) {
                    $tokens[] = $token;
                    $user->firebase_token = array_values(array_unique($tokens));
                    $user->save();
                    
                    $this->log('info', 'Added new FCM token for user', [
                        'user_id' => $user->id,
                        'token_count' => count($tokens),
                        'token_prefix' => substr($token, 0, 10) . '...' . substr($token, -5)
                    ]);
                    
                    return true;
                }
                
                $this->log('debug', 'FCM token already exists for user', [
                    'user_id' => $user->id,
                    'token_prefix' => substr($token, 0, 10) . '...' . substr($token, -5)
                ]);
                
                return true;
            });
            
        } catch (\Exception $e) {
            $this->log('error', 'Failed to add FCM token', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return false;
        }
    }
    
    /**
     * Remove an FCM token from a user's list of tokens
     * 
     * @param User $user
     * @param string $token
     * @return bool
     */
    public function removeToken(User $user, string $token): bool
    {
        try {
            return DB::transaction(function () use ($user, $token) {
                $tokens = $this->getUserTokens($user);
                $initialCount = count($tokens);
                
                $tokens = array_values(array_filter($tokens, function($t) use ($token) {
                    return $t !== $token;
                }));
                
                if (count($tokens) !== $initialCount) {
                    $user->firebase_token = $tokens;
                    $user->save();
                    
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
     * Clear all FCM tokens for a user
                'message' => 'Failed to clear FCM tokens',
                'code' => 500
            ];
        }
    }

    /**
     * Clean up invalid FCM tokens from database
     * 
     * @param array $invalidTokens Array of invalid tokens with error info
     * @return int Number of tokens cleaned up
     */
    public function cleanupInvalidTokens(array $invalidTokens): int
    {
        if (empty($invalidTokens)) {
            return 0;
        }

        $tokensToRemove = array_column($invalidTokens, 'token');
        
        // Find users with these tokens and remove them
        $users = User::whereJsonContains('firebase_token', $tokensToRemove)->get();
        
        $totalRemoved = 0;
        
        foreach ($users as $user) {
            $tokens = $user->firebase_token;
            
            if (!is_array($tokens)) {
                $tokens = $tokens ? [$tokens] : [];
            }
            
            $originalCount = count($tokens);
            $tokens = array_values(array_diff($tokens, $tokensToRemove));
            $removedCount = $originalCount - count($tokens);
            
            if ($removedCount > 0) {
                $user->firebase_token = $tokens;
                $user->save();
                $totalRemoved += $removedCount;
                
                Log::info('Cleaned up invalid FCM tokens', [
                    'user_id' => $user->id,
                    'tokens_removed' => $removedCount,
                    'remaining_tokens' => count($tokens)
                ]);
            }
        }
        
        return $totalRemoved;
    }
    
    /**
     * Get FCM tokens for a notifiable entity
     * 
     * @param mixed $notifiable
     * @return array
     */
    public function getTokensForNotifiable($notifiable): array
    {
        try {
            if ($notifiable instanceof User) {
                return $this->getUserTokens($notifiable);
            }
            
            // For other notifiable entities, try to get tokens using the routeNotificationForFcm method
            if (method_exists($notifiable, 'routeNotificationForFcm')) {
                $tokens = $notifiable->routeNotificationForFcm();
                
                if (is_string($tokens)) {
                    return $this->isValidFcmToken($tokens) ? [$tokens] : [];
                }
                
                if (is_array($tokens)) {
                    return array_values(array_filter($tokens, function($token) {
                        return is_string($token) && $this->isValidFcmToken($token);
                    }));
                }
            }
            
            return [];
        } catch (\Exception $e) {
            \Log::error('Error getting FCM tokens for notifiable', [
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    
    /**
     * Get user's FCM tokens
     * 
     * @param User $user
     * @return array
     */
    protected function getUserTokens(User $user): array
    {
        try {
            // Log the raw firebase_token value for debugging
            \Log::debug('Raw firebase_token value', [
                'user_id' => $user->id,
                'firebase_token' => $user->firebase_token,
                'firebase_token_type' => gettype($user->firebase_token)
            ]);
            
            // Use the User model's getFcmTokens method if available
            if (method_exists($user, 'getFcmTokens')) {
                $tokens = $user->getFcmTokens();
                \Log::debug('Tokens from getFcmTokens()', [
                    'user_id' => $user->id,
                    'tokens' => $tokens,
                    'tokens_count' => count($tokens)
                ]);
                return $tokens;
            }
            
            // Fallback to direct property access for backward compatibility
            if (isset($user->firebase_token)) {
                $tokens = $user->firebase_token;
                
                if (empty($tokens)) {
                    \Log::debug('Empty firebase_token', ['user_id' => $user->id]);
                    return [];
                }
                
                // Handle JSON string
                if (is_string($tokens) && $this->isJson($tokens)) {
                    $decoded = json_decode($tokens, true);
                    \Log::debug('Decoded JSON tokens', [
                        'user_id' => $user->id,
                        'decoded' => $decoded
                    ]);
                    $tokens = $decoded;
                }
                
                // Handle string token
                if (is_string($tokens) && $this->isValidFcmToken($tokens)) {
                    \Log::debug('Valid string token found', ['user_id' => $user->id]);
                    return [$tokens];
                }
                
                // Handle array of tokens
                if (is_array($tokens)) {
                    $validTokens = array_values(array_filter($tokens, function($token) {
                        return is_string($token) && $this->isValidFcmToken($token);
                    }));
                    
                    \Log::debug('Processed array tokens', [
                        'user_id' => $user->id,
                        'valid_tokens' => $validTokens,
                        'valid_count' => count($validTokens)
                    ]);
                    
                    return $validTokens;
                }
                
                \Log::warning('Unhandled token format', [
                    'user_id' => $user->id,
                    'token_type' => gettype($tokens),
                    'token_value' => $tokens
                ]);
            }
            
            return [];
        } catch (\Exception $e) {
            \Log::error('Error in getUserTokens', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
    
    /**
     * Check if a string is valid JSON
     * 
     * @param string $string
     * @return bool
     */
    protected function isJson($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
    
    /**
     * Remove invalid FCM tokens for specific users
     * 
     * @param array $userIds
     * @param array $invalidTokens
     * @return int Number of tokens removed
     */
    public function removeInvalidTokens(array $userIds, array $invalidTokens): int
    {
        if (empty($userIds) || empty($invalidTokens)) {
            return 0;
        }
        
        $users = User::whereIn('id', $userIds)
            ->whereNotNull('firebase_token')
            ->get();
            
        $totalRemoved = 0;
        
        foreach ($users as $user) {
            $tokens = $user->getFcmTokens();
            $originalCount = count($tokens);
            
            $tokens = array_values(array_diff($tokens, $invalidTokens));
            $removed = $originalCount - count($tokens);
            
            if ($removed > 0) {
                $user->firebase_token = $tokens;
                $user->save();
                $totalRemoved += $removed;
                
                $this->log('info', 'Removed invalid FCM tokens from user', [
                    'user_id' => $user->id,
                    'tokens_removed' => $removed,
                    'remaining_tokens' => count($tokens)
                ]);
            }
        }
        return $totalRemoved;
    }
    
    /**
     * Send a message to specific FCM tokens
     * 
     * @param array $tokens Array of FCM tokens
     * @param \Kreait\Firebase\Messaging\CloudMessage $message The message to send
     * @return array
     */
    public function sendToTokens(array $tokens, $message): array
    {
        if (empty($tokens)) {
            return [
                'success' => false,
                'message' => 'No tokens provided',
                'sent' => 0,
                'failed' => 0,
                'invalid_tokens' => []
            ];
        }

        // Check for test tokens and environment settings
        $isTestEnv = app()->environment('local', 'staging', 'development');
        $allowTestTokens = filter_var(env('FIREBASE_ALLOW_TEST_TOKENS', 'true'), FILTER_VALIDATE_BOOLEAN);
        
        // Filter out test tokens if not allowed
        $testTokens = [];
        $validTokens = [];
        
        foreach ($tokens as $token) {
            if (is_string($token) && (str_starts_with($token, 'test_fcm_token_') || str_starts_with($token, 'test_'))) {
                $testTokens[] = $token;
            } else if (!empty($token)) {
                $validTokens[] = $token;
            }
        }
        
        // Handle test tokens in test environments
        if ($isTestEnv && $allowTestTokens && !empty($testTokens)) {
            $this->log('info', 'Processing test tokens in test environment', [
                'test_token_count' => count($testTokens),
                'valid_token_count' => count($validTokens),
                'test_token_sample' => !empty($testTokens) ? substr($testTokens[0], 0, 15) . '...' : 'none',
            ]);
            
            // Simulate success for test tokens
            $testResponses = array_map(function($token) {
                return [
                    'success' => true,
                    'message' => 'Test token processed (not sent to FCM)',
                    'token' => $token,
                    'message_id' => 'test:' . uniqid(),
                    'is_test' => true
                ];
            }, $testTokens);
            
            // If we only have test tokens, return early with simulated success
            if (empty($validTokens)) {
                return [
                    'success' => true,
                    'message' => 'Test tokens processed (not sent to FCM)',
                    'sent' => count($testTokens),
                    'failed' => 0,
                    'invalid_tokens' => [],
                    'is_test' => true,
                    'responses' => $testResponses
                ];
            }
            
            // Process real tokens and combine with test responses
            $realResponse = $this->sendToFcm($validTokens, $message);
            
            return [
                'success' => $realResponse['success'],
                'message' => 'Mixed tokens processed',
                'sent' => $realResponse['sent'] + count($testTokens),
                'failed' => $realResponse['failed'],
                'invalid_tokens' => $realResponse['invalid_tokens'],
                'responses' => array_merge($testResponses, $realResponse['responses'] ?? []),
                'is_mixed' => true
            ];
        }
        
        // If we reach here, either test tokens are not allowed or there are no test tokens
        if (!empty($testTokens) && !$allowTestTokens) {
            $this->log('warning', 'Test tokens found but not allowed', [
                'test_token_count' => count($testTokens),
                'test_token_sample' => substr($testTokens[0] ?? '', 0, 15) . '...',
                'environment' => app()->environment(),
                'FIREBASE_ALLOW_TEST_TOKENS' => env('FIREBASE_ALLOW_TEST_TOKENS')
            ]);
        }
        
        // Only process valid tokens
        return $this->sendToFcm($validTokens, $message);
    }
    
    /**
     * Send messages to FCM tokens
     * 
     * @param array $tokens Array of FCM tokens
     * @param \Kreait\Firebase\Messaging\CloudMessage $message The message to send
     * @return array
     */
    protected function sendToFcm(array $tokens, $message): array
    {
        if (empty($tokens)) {
            return [
                'success' => true,
                'message' => 'No valid tokens to process',
                'sent' => 0,
                'failed' => 0,
                'invalid_tokens' => []
            ];
        }
        
        $messaging = app('firebase.messaging');
        $responses = [];
        $invalidTokens = [];
        $sentCount = 0;
        $failedCount = 0;
        
        // Process in chunks to avoid hitting FCM limits
        $chunks = array_chunk($tokens, 500);
        
        foreach ($chunks as $chunk) {
            try {
                $report = $messaging->sendMulticast($message, $chunk);
                
                // Process the report to find invalid tokens
                foreach ($report->getItems() as $index => $result) {
                    $token = $chunk[$index] ?? null;
                    
                    if ($result->isSuccess()) {
                        $sentCount++;
                    } else {
                        $failedCount++;
                        
                        if ($token) {
                            $error = $result->error();
                            $invalidTokens[] = [
                                'token' => $token,
                                'error' => $error ? $error->getMessage() : 'Unknown error'
                            ];
                            
                            $this->log('warning', 'FCM send failed for token', [
                                'token_prefix' => substr($token, 0, 10) . '...',
                                'error' => $error ? $error->getMessage() : 'Unknown error',
                                'code' => $error ? $error->code() : 0,
                            ]);
                        }
                    }
                }
                
                $responses[] = [
                    'status' => 'success',
                    'sent' => $report->successes()->count(),
                    'failed' => $report->failures()->count(),
                    'invalid_tokens' => array_map(function($failure) {
                        return [
                            'token' => $failure->target()->value(),
                            'error' => $failure->error()->getMessage(),
                            'code' => $failure->error()->code(),
                        ];
                    }, iterator_to_array($report->failures()->getItems())),
                ];
                
            } catch (\Throwable $e) {
                $failedCount += count($chunk);
                
                $this->log('error', 'Failed to send FCM message', [
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'chunk_size' => count($chunk),
                ]);
                
                $responses[] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'sent' => 0,
                    'failed' => count($chunk),
                    'invalid_tokens' => array_map(function($token) use ($e) {
                        return [
                            'token' => $token,
                            'error' => $e->getMessage(),
                            'code' => $e->getCode(),
                        ];
                    }, $chunk),
                ];
            }
        }
        
        // Remove invalid tokens if any
        if (!empty($invalidTokens)) {
            $this->cleanupInvalidTokens($invalidTokens);
        }
        
        return [
            'success' => $failedCount === 0,
            'message' => $failedCount === 0 ? 'All messages sent successfully' : 'Some messages failed to send',
            'sent' => $sentCount,
            'failed' => $failedCount,
            'invalid_tokens' => $invalidTokens,
            'responses' => $responses,
        ];
    }
    
    /**
     * Check if a token is a valid FCM token format
     * 
     * @param string $token
     * @return bool
     */
    public function isValidFcmToken(string $token): bool
    {
        if (!is_string($token) || empty($token)) {
            \Log::debug('Invalid FCM token: not a string or empty', [
                'token' => $token,
                'type' => gettype($token)
            ]);
            return false;
        }

        // Check for test tokens
        if (str_starts_with($token, 'test_fcm_token_') || str_starts_with($token, 'test_')) {
            $allowTestTokens = filter_var(env('FIREBASE_ALLOW_TEST_TOKENS', 'true'), FILTER_VALIDATE_BOOLEAN);
            $isTestEnv = app()->environment('local', 'staging', 'development');
            
            if ($allowTestTokens || $isTestEnv) {
                // For test tokens, we'll accept any format as long as it's not empty
                $isValid = strlen($token) > 0;
                
                if ($isValid) {
                    \Log::debug('Accepted test FCM token', [
                        'token_prefix' => substr($token, 0, 15) . '...',
                        'length' => strlen($token),
                        'user_id' => str_replace('test_fcm_token_', '', $token),
                        'FIREBASE_ALLOW_TEST_TOKENS' => $allowTestTokens ? 'true' : 'false',
                        'environment' => app()->environment(),
                        'is_test_token' => true
                    ]);
                    return true;
                }
            }
            
            \Log::debug('Rejected test FCM token (not allowed in this environment)', [
                'token_prefix' => substr($token, 0, 15) . '...',
                'length' => strlen($token),
                'user_id' => str_replace('test_fcm_token_', '', $token),
                'FIREBASE_ALLOW_TEST_TOKENS' => $allowTestTokens ? 'true' : 'false',
                'environment' => app()->environment(),
                'reason' => 'Test tokens not allowed in this environment'
            ]);
            return false;
        }

        // Basic format check for real FCM tokens
        // FCM tokens are typically 152-163 characters long and contain alphanumeric characters and some special characters
        $isValid = (bool) preg_match('/^[a-zA-Z0-9_\-:]+$/', $token);
        
        if (!$isValid) {
            \Log::debug('Invalid FCM token format', [
                'token_prefix' => substr($token, 0, 15) . '...',
                'length' => strlen($token),
                'first_10_chars' => substr($token, 0, 10) . '...',
                'regex_match' => preg_match('/^[a-zA-Z0-9_\-:]+$/', $token),
                'is_test_token' => false
            ]);
        } else {
            \Log::debug('Valid FCM token', [
                'token_prefix' => substr($token, 0, 10) . '...',
                'length' => strlen($token),
                'is_test_token' => false
            ]);
        }
        
        return $isValid;
    }
    
    /**
     * Check if a token is valid (alias for isValidFcmToken for backward compatibility)
     * 
     * @param string $token The FCM token to validate
     * @return bool Returns true if the token is valid, false otherwise
     */
    public function isValidToken(string $token): bool
    {
        return $this->isValidFcmToken($token);
    }
    
    /**
     * Get users with FCM tokens
     * 
     * @param array $userIds
     * @return Collection
     */
    public function getUsersWithTokens(array $userIds = []): Collection
    {
        $query = User::whereNotNull('firebase_token')
            ->where('firebase_token', '!=', '[]');
            
        if (!empty($userIds)) {
            $query->whereIn('id', $userIds);
        }
        
        return $query->get();
    }
}
