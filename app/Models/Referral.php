<?php

namespace App\Models;

use App\Traits\Loadable;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * App\Models\Referral
 *
 * @property int $id
 * @property double $price_from
 * @property double $price_to
 * @property Carbon|null $expired_at
 * @property string $img
 * @property Translation|null $translation
 * @property Collection|Translation[] $translations
 * @property int $translations_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @method static Builder|Referral newModelQuery()
 * @method static Builder|Referral newQuery()
 * @method static Builder|Referral query()
 * @method static Builder|Referral whereCreatedAt($value)
 * @method static Builder|Referral whereId($value)
 * @method static Builder|Referral whereUpdatedAt($value)
 * @method static Builder|Referral whereDeletedAt($value)
 * @mixin Eloquent
 */
class Referral extends Model
{
    use SoftDeletes, Loadable;

    protected $guarded = ['id'];

    protected $casts = [
        'price_from' => 'double',
        'price_to' => 'double',
        'expired_at' => 'datetime',
        'reward_conditions' => 'json',
        'reward_tiers' => 'json'
    ];

    const DEFAULT_CONDITION = 'first_order';
    const CONDITIONS = [
        'first_order',     // Reward on first order
        'order_count',     // Reward after X orders
        'order_amount',    // Reward when order total reaches X
        'registration'     // Reward on registration
    ];

    // Translations
    public function translations(): HasMany
    {
        return $this->hasMany(ReferralTranslation::class);
    }

    public function translation(): HasOne
    {
        return $this->hasOne(ReferralTranslation::class);
    }

    /**
     * Calculate reward based on conditions and tiers
     * @param User $user
     * @param string $type from|to
     * @param float|null $amount
     * @return float
     */
    public function calculateReward(User $user, string $type = 'from', ?float $amount = null): float
    {
        $baseReward = $type === 'from' ? $this->price_from : $this->price_to;
        $tiers = $this->reward_tiers ?? [];
        
        // If no tiers, return base reward
        if (empty($tiers)) {
            return $baseReward;
        }

        // Get user's referral count
        $referralCount = $user->referredUsers()->count();
        
        // Find applicable tier
        foreach ($tiers as $tier) {
            if ($referralCount >= $tier['min_referrals'] && 
                (!isset($tier['max_referrals']) || $referralCount <= $tier['max_referrals'])) {
                return $baseReward * ($tier['multiplier'] ?? 1);
            }
        }

        return $baseReward;
    }

    /**
     * Check if reward conditions are met
     * @param User $user
     * @param Order|null $order
     * @return bool
     */
    public function checkConditions(User $user, ?Order $order = null): bool
    {
        $conditions = $this->reward_conditions ?? ['type' => self::DEFAULT_CONDITION];
        
        switch ($conditions['type']) {
            case 'first_order':
                return $order && $user->orders()->where('status', Order::STATUS_DELIVERED)->count() === 1;
                
            case 'order_count':
                return $order && $user->orders()->where('status', Order::STATUS_DELIVERED)->count() >= ($conditions['min_orders'] ?? 1);
                
            case 'order_amount':
                return $order && $order->total_price >= ($conditions['min_amount'] ?? 0);
                
            case 'registration':
                return true;
                
            default:
                return false;
        }
    }

    /**
     * Get users referred by a user
     * @param User $user
     * @return Builder
     */
    public function referredUsers(User $user): Builder
    {
        return User::where('referral', $user->my_referral);
    }
}
