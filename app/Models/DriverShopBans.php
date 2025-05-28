<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Schema;

/**
 * App\Models\DriverShopBans
 *
 * @property int $id
 * @property int $user_id
 * @property int $shop_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Shop|null $shop
 * @method static Builder|self filter($filter)
 * @method static Builder|self newModelQuery()
 * @method static Builder|self newQuery()
 * @method static Builder|self onlyTrashed()
 * @method static Builder|self query()
 * @mixin Eloquent
 */
class DriverShopBans extends Model
{
    protected $guarded = ['id'];

    public function user(): BelongsTo
	{
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
	{
        return $this->belongsTo(Shop::class);
    }

    public function scopeFilter($query, $filter)
    {
		$sort   = data_get($filter, 'sort', 'desc');
		$column = data_get($filter,'column');

		if (!Schema::hasColumn('driver_shop_bans', $column)) {
			$column = 'id';
		}

        $query
			->when(data_get($filter, 'user_id'), fn($q, $id) => $q->where('user_id', $id))
			->when(data_get($filter, 'shop_id'), fn($q, $id) => $q->where('shop_id', $id))
			->when($column, 						 fn($q, $column) => $q->orderBy($column, $sort));
    }
}
