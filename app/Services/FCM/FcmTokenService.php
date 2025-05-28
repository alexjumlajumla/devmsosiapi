<?php

namespace App\Services\FCM;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FcmTokenService
{
    /**
     * Add an FCM token to a user
     *
     * @param User $user
     * @param string $token
     * @return array
     */
    public function addToken(User $user, string $token): array
    {
        try {
            DB::beginTransaction();

            if (!$user->isValidFcmToken($token)) {
                throw new \InvalidArgumentException('Invalid FCM token format');
            }

            $added = $user->addFcmToken($token);
            $user->save();

            DB::commit();

            Log::info('FCM token added', [
                'user_id' => $user->id,
                'token_prefix' => substr($token, 0, 10) . '...',
                'total_tokens' => count($user->getFcmTokens())
            ]);

            return [
                'status' => true,
                'message' => 'FCM token added successfully',
                'data' => [
                    'total_tokens' => count($user->getFcmTokens())
                ]
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to add FCM token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => false,
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: 500
            ];
        }
    }

    /**
     * Remove an FCM token from a user
     *
     * @param User $user
     * @param string $token
     * @return array
     */
    public function removeToken(User $user, string $token): array
    {
        try {
            DB::beginTransaction();

            $removed = $user->removeFcmToken($token);
            $user->save();

            DB::commit();

            if ($removed) {
                Log::info('FCM token removed', [
                    'user_id' => $user->id,
                    'token_prefix' => substr($token, 0, 10) . '...',
                    'remaining_tokens' => count($user->getFcmTokens())
                ]);

                return [
                    'status' => true,
                    'message' => 'FCM token removed successfully',
                    'data' => [
                        'remaining_tokens' => count($user->getFcmTokens())
                    ]
                ];
            }


            return [
                'status' => false,
                'message' => 'FCM token not found',
                'code' => 404
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to remove FCM token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => false,
                'message' => 'Failed to remove FCM token',
                'code' => 500
            ];
        }
    }

    /**
     * Clear all FCM tokens for a user
     *
     * @param User $user
     * @return array
     */
    public function clearTokens(User $user): array
    {
        try {
            DB::beginTransaction();

            $count = count($user->getFcmTokens());
            $user->clearFcmTokens();
            $user->save();

            DB::commit();

            Log::info('Cleared all FCM tokens', [
                'user_id' => $user->id,
                'tokens_cleared' => $count
            ]);

            return [
                'status' => true,
                'message' => 'All FCM tokens cleared successfully',
                'data' => [
                    'tokens_cleared' => $count
                ]
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to clear FCM tokens', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => false,
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
}
