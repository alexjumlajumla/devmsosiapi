<?php

namespace App\Services;

use App\Models\User;
use App\Models\VoiceOrder;
use App\Models\VoiceOrderSubscription;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VoiceOrderSubscriptionService
{
    /**
     * Maximum number of free voice orders allowed
     */
    const MAX_FREE_ORDERS = 5;
    
    /**
     * Check if a user has an active voice ordering subscription
     *
     * @param User|null $user
     * @return bool
     */
    public function hasActiveSubscription(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        
        // Admin users always have access
        if ($user->hasRole(['admin', 'manager'])) {
            return true;
        }
        
        // Check if user has an active subscription
        $subscription = $this->getUserSubscription($user);
        
        if ($subscription && $subscription->is_active && $subscription->expires_at > now()) {
            return true;
        }
        
        // Check for admin override
        if ($subscription && $subscription->admin_override) {
            return true;
        }
        
        // Check if user still has free orders available
        return $this->getFreeOrdersRemaining($user) > 0;
    }
    
    /**
     * Get the number of free voice orders remaining for a user
     *
     * @param User $user
     * @return int
     */
    public function getFreeOrdersRemaining(User $user): int
    {
        $ordersUsed = $this->getVoiceOrdersCount($user);
        $remaining = self::MAX_FREE_ORDERS - $ordersUsed;
        
        return max(0, $remaining);
    }
    
    /**
     * Get the number of voice orders a user has made
     *
     * @param User $user
     * @return int
     */
    public function getVoiceOrdersCount(User $user): int
    {
        return Cache::remember('voice_orders_count_'.$user->id, 60, function() use ($user) {
            return VoiceOrder::where('user_id', $user->id)->count();
        });
    }
    
    /**
     * Get a user's voice order subscription
     *
     * @param User $user
     * @return VoiceOrderSubscription|null
     */
    public function getUserSubscription(User $user): ?VoiceOrderSubscription
    {
        return VoiceOrderSubscription::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->first();
    }
    
    /**
     * Set admin override for a user's voice subscription
     *
     * @param User $user
     * @param bool $override
     * @return VoiceOrderSubscription
     */
    public function setAdminOverride(User $user, bool $override): VoiceOrderSubscription
    {
        $subscription = $this->getUserSubscription($user);
        
        if (!$subscription) {
            $subscription = new VoiceOrderSubscription();
            $subscription->user_id = $user->id;
            $subscription->is_active = $override;
            $subscription->expires_at = now()->addYear();
        }
        
        $subscription->admin_override = $override;
        $subscription->save();
        
        return $subscription;
    }
    
    /**
     * Create or update a subscription for a user
     *
     * @param User $user
     * @param int $durationDays
     * @return VoiceOrderSubscription
     */
    public function createOrUpdateSubscription(User $user, int $durationDays): VoiceOrderSubscription
    {
        $subscription = $this->getUserSubscription($user);
        
        if (!$subscription) {
            $subscription = new VoiceOrderSubscription();
            $subscription->user_id = $user->id;
        }
        
        $subscription->is_active = true;
        
        // If subscription is expired, set new expiration date from now
        if (!$subscription->expires_at || $subscription->expires_at < now()) {
            $subscription->expires_at = now()->addDays($durationDays);
        } else {
            // If subscription is still active, add days to the current expiration date
            $subscription->expires_at = $subscription->expires_at->addDays($durationDays);
        }
        
        $subscription->save();
        
        // Clear the order count cache for this user
        Cache::forget('voice_orders_count_'.$user->id);
        
        return $subscription;
    }
    
    /**
     * Check if we should track this voice order for subscription purposes
     * 
     * @param User|null $user
     * @return bool
     */
    public function shouldTrackVoiceOrder(?User $user): bool
    {
        if (!$user) {
            return false;
        }
        
        // Don't track orders for admin/manager roles
        if ($user->hasRole(['admin', 'manager'])) {
            return false;
        }
        
        // Don't track if user has admin override
        $subscription = $this->getUserSubscription($user);
        if ($subscription && $subscription->admin_override) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Record a voice order for subscription tracking purposes
     * 
     * @param User $user
     * @return void
     */
    public function recordVoiceOrder(User $user): void
    {
        if (!$this->shouldTrackVoiceOrder($user)) {
            return;
        }
        
        // Clear the order count cache for this user
        Cache::forget('voice_orders_count_'.$user->id);
    }
} 