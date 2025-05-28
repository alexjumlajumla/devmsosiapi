<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\ComboStock
 *
 * @property int $combo_id
 * @property int $stock_id
 * @property-read Combo|null $combo
 * @property-read Stock|null $stock
 * @method static Builder|self newModelQuery()
 * @method static Builder|self newQuery()
 * @method static Builder|self query()
 * @method static Builder|self whereCreatedAt($value)
 * @method static Builder|self whereUpdatedAt($value)
 * @method static Builder|self whereId($value)
 * @mixin Eloquent
 */
class ComboStock extends Model
{
    protected $guarded = ['id'];

    /**
     * @return BelongsTo
     */
    public function combo(): BelongsTo
    {
        return $this->belongsTo(Combo::class);
    }

    /**
     * @return BelongsTo
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

}
