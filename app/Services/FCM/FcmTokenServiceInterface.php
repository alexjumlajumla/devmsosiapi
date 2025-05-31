<?php

namespace App\Services\FCM;

use App\Models\User;

interface FcmTokenServiceInterface
{
    /**
     * Add an FCM token for a user
     * 
     * @param User $user
     * @param string $token
     * @param string|null $deviceId
     * @return bool
     */
    public function addToken(User $user, string $token, ?string $deviceId = null): bool;
    
    /**
     * Remove an FCM token from a user
     * 
     * @param User $user
     * @param string $token
     * @return bool
     */
    public function removeToken(User $user, string $token): bool;
    
    /**
     * Get user's FCM tokens with metadata
     * 
     * @param User $user
     * @return array
     */
    public function getUserTokensWithMetadata(User $user): array;
    
    /**
     * Get user's FCM tokens as a simple array
     * 
     * @param User $user
     * @return array
     */
    public function getUserTokens(User $user): array;
    
    /**
     * Check if an FCM token is valid
     * 
     * @param string $token
     * @return bool
     */
    public function isValidFcmToken(string $token): bool;
}
