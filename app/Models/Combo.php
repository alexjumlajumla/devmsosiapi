<?php

namespace App\Models;

use App\Traits\Loadable;
use Eloquent;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * App\Models\Combo
 *
 * @property int $id
 * @property int $shop_id
 * @property string $img
 * @property int $active
 * @property int $expired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop|null $shop
 * @property-read Collection|HasManyThrough|ComboStock[] $stocks
 * @property-read int $stocks_count
 * @property-read ComboTranslation|null $translation
 * @property-read Collection|ComboTranslation $translations
 * @property-read int $translations_count
 * @method static Builder|self newModelQuery()
 * @method static Builder|self newQuery()
 * @method static Builder|self query()
 * @method static Builder|self whereCreatedAt($value)
 * @method static Builder|self whereUpdatedAt($value)
 * @method static Builder|self whereId($value)
 * @method static Builder|self filter(array $filter)
 * @mixin Eloquent
 */
class Combo extends Model
{
	use Loadable;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * @return HasManyThrough
	 */
    public function stocks(): HasManyThrough
	{
        return $this->hasManyThrough(Stock::class, ComboStock::class);
    }

	public function scopeFilter($query, array $filter = []) {

		$query
			->when(data_get($filter, 'search'), function ($q, $search) {
				$q
					->where('id', 'LIKE', "%$search%")
					->orWhereHas('translations', function ($q) use ($search) {
						$q->where('title', 'LIKE', "%$search%");
					});
			})
			->when(data_get($filter, 'shop_id'), fn($q, $shopId) => $q->where('shop_id', $shopId));
	}

}
