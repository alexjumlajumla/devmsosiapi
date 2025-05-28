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
            if (!$this->isValidFcmToken($token)) {
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
                return $notifiable->getFcmTokens();
            }
            
            if (method_exists($notifiable, 'getFcmTokens')) {
                return $notifiable->getFcmTokens();
            }
            
            if (isset($notifiable->firebase_token)) {
                $tokens = $notifiable->firebase_token;
                
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
        $tokens = $user->firebase_token;
        
        if (empty($tokens)) {
            return [];
        }
        
        if (is_string($tokens)) {
            return [$tokens];
        }
        
        if (is_array($tokens)) {
            return array_values(array_filter($tokens, function($token) {
                return is_string($token) && $this->isValidFcmToken($token);
            }));
        }
        
        return [];
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
     * @param array $tokens
     * @param CloudMessage $message
     * @return array
     */
    public function sendToTokens(array $tokens, CloudMessage $message): array
    {
        if (empty($tokens)) {
            return [
                'success' => false,
                'message' => 'No tokens provided',
                'sent' => 0,
                'failed' => 0,
                'invalid_tokens' => [],
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
            return false;
        }

        // Basic validation for FCM token format
        // FCM tokens are typically 152-163 characters long and contain alphanumeric characters and some special characters
        return (bool) preg_match('/^[a-zA-Z0-9_\-:]+$/', $token);
    }
    
    /**
     * Check if a token is valid (alias for isValidFcmToken for backward compatibility)
     * 
     * @param string $token
     * @return bool
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
