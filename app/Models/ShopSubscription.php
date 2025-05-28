<?php

namespace App\Models;

use App\Traits\Payable;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * App\Models\ShopSubscription
 *
 * @property int $id
 * @property int $shop_id
 * @property int $subscription_id
 * @property string|null $expired_at
 * @property float|null $price
 * @property string|null $type
 * @property int $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Shop $shop
 * @property-read Subscription|null $subscription
 * @property-read Transaction|null $transaction
 * @property-read Collection|Transaction[] $transactions
 * @property-read int|null $transactions_count
 * @method static Builder|self actualSubscription()
 * @method static Builder|self newModelQuery()
 * @method static Builder|self newQuery()
 * @method static Builder|self query()
 * @method static Builder|self whereActive($value)
 * @method static Builder|self whereCreatedAt($value)
 * @method static Builder|self whereExpiredAt($value)
 * @method static Builder|self whereId($value)
 * @method static Builder|self wherePrice($value)
 * @method static Builder|self whereShopId($value)
 * @method static Builder|self whereSubscriptionId($value)
 * @method static Builder|self whereType($value)
 * @method static Builder|self whereUpdatedAt($value)
 * @mixin Eloquent
 */
class ShopSubscription extends Model
{
    use Payable, SoftDeletes;

    protected $guarded = ['id'];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function transaction(): MorphOne
    {
        return $this->morphOne( Transaction::class,'payable');
    }

    public function scopeActualSubscription($query)
    {
        return $query->where('active', 1)
            ->where('expired_at', '>=', now());
    }
}
