<?php

namespace App\Models;

use Carbon\Carbon;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Schema;

/**
 * App\Models\StockInventoryItem
 *
 * @property int $id
 * @property int $inventory_item_id
 * @property int $stock_id
 * @property string $interval
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read InventoryItem $inventoryItem
 * @property-read Stock $stock
 * @method static Builder|self newModelQuery()
 * @method static Builder|self newQuery()
 * @method static Builder|self query()
 * @method static Builder|self filter(array $filter)
 * @method static Builder|self whereId($value)
 * @mixin Eloquent
 */
class StockInventoryItem extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

	public function inventoryItem(): BelongsTo
	{
		return $this->belongsTo(InventoryItem::class);
	}

	public function stock(): BelongsTo
	{
		return $this->belongsTo(Stock::class);
	}

	public function scopeFilter($value, $filter)
	{
		$column = data_get($filter,'column');

		if (!Schema::hasColumn('stock_inventory_items', $column)) {
			$column = 'id';
		}

		return $value
			->when(isset($filter['inventory_item_id']),	fn($q) => $q->where('inventory_item_id', $filter['inventory_item_id']))
			->when(isset($filter['stock_id']),	 		fn($q) => $q->where('stock_id', 	  	 $filter['stock_id']))
			->when(
				$column,
				fn($query, $column) => $query->orderBy($column, $filter['sort'] ?? 'desc'),
				fn($query) => $query->orderBy('id', 'desc')
			);
	}
}
