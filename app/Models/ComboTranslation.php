<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * App\Models\ComboTranslation
 *
 * @property int $id
 * @property int $combo_id
 * @property string $locale
 * @property string $title
 * @property string|null $description
 * @method static Builder|self newModelQuery()
 * @method static Builder|self newQuery()
 * @method static Builder|self query()
 * @method static Builder|self whereComboId($value)
 * @method static Builder|self whereDescription($value)
 * @method static Builder|self whereId($value)
 * @method static Builder|self whereLocale($value)
 * @method static Builder|self whereTitle($value)
 * @mixin Eloquent
 */
class ComboTranslation extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;
}
