<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * App\Models\Gallery
 *
 * @property int $id
 * @property string $title
 * @property string $loadable_type
 * @property int $loadable_id
 * @property string|null $type
 * @property string|null $path
 * @property string|null $mime
 * @property string|null $size
 * @property-read Model|Eloquent $loadable
 * @method static Builder|self newModelQuery()
 * @method static Builder|self newQuery()
 * @method static Builder|self query()
 * @method static Builder|self whereId($value)
 * @method static Builder|self whereLoadableId($value)
 * @method static Builder|self whereLoadableType($value)
 * @method static Builder|self whereMime($value)
 * @method static Builder|self wherePath($value)
 * @method static Builder|self whereSize($value)
 * @method static Builder|self whereTitle($value)
 * @method static Builder|self whereType($value)
 * @mixin Eloquent
 */
class Gallery extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public $timestamps = false;

    const TYPES = [
        'restaurant/logo', 'restaurant/background', 'shops/logo', 'shops/background', 'deliveryman/settings',
        'deliveryman', 'shops', 'restaurant', 'banners', 'brands', 'blogs', 'categories', 'coupons', 'discounts',
        'extras', 'reviews', 'order_refunds', 'users', 'products', 'languages', 'referral', 'shop-tags', 'shop-documents',
		'receipts', 'shop-galleries', 'landing-pages', 'combos', 'parcel-order-setting', 'parcel-orders', 'stocks',
		'orders/after-delivered'
    ];

    public function loadable(): MorphTo
    {
        return $this->morphTo('loadable');
    }
}
