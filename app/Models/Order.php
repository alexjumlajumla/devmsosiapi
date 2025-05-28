<?php

namespace App\Models;

use App\Events\OrderStatusUpdated;
use App\Helpers\Utility;
use App\Models\Booking\Table;
use App\Traits\Loadable;
use App\Traits\Payable;
use App\Traits\Reviewable;
use Database\Factories\OrderFactory;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Event;

/**
 * App\Models\Order
 *
 * @property int $id
 * @property int $user_id
 * @property string $delivery_type
 * @property int $rate_delivery_fee
 * @property int $rate_waiter_fee
 * @property double $total_price
 * @property int $currency_id
 * @property int $rate
 * @property string|null $note
 * @property string|null $image_after_delivered
 * @property int $shop_id
 * @property float $tax
 * @property float|null $commission_fee
 * @property float|null $service_fee
 * @property float|null $rate_commission_fee
 * @property float|null $rate_service_fee
 * @property string $status
 * @property array|null $location
 * @property string|null $address
 * @property float $delivery_fee
 * @property int|null $deliveryman
 * @property string|null $delivery_date
 * @property string|null $delivery_time
 * @property double|null $total_discount
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $username
 * @property string|null $img
 *
 * @property array|null $tip_for
 * @property double|null $tips
 * @property double|null $rate_tips
 * @property double|null $shop_tip
 * @property double|null $rate_shop_tip
 * @property double|null $driver_tip
 * @property double|null $rate_driver_tip
 * @property double|null $waiter_tip
 * @property double|null $rate_waiter_tip
 * @property double|null $system_tip
 * @property double|null $rate_system_tip
 * @property double|null $origin_price
 * @property double|null $seller_fee
 * @property double|null $coupon_price
 * @property double|null $rate_coupon_price
 *
 * @property double|null $coupon_sum_price
 * @property double|null $point_histories_sum_price
 * @property double|null $rate_coupon_sum_price
 * @property double|null $rate_point_histories_sum_price
 * @property boolean|null $current
 * @property float|null $waiter_fee
 * @property int|null $waiter_id
 * @property int|null $table_id
 * @property int|null $booking_id
 * @property int|null $otp
 * @property int|null $user_booking_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $rate_total_price
 * @property-read double $rate_total_discount
 * @property-read double $order_details_sum_total_price
 * @property-read double $order_details_sum_discount
 * @property-read int $rate_tax
 * @property-read Currency|null $currency
 * @property-read UserAddress|null $myAddress
 * @property-read OrderCoupon|null $coupon
 * @property-read Collection|OrderDetail[] $orderDetails
 * @property-read int|null $order_details_count
 * @property-read Collection|OrderDetail[] $orderRefunds
 * @property-read int|null $order_refunds_count
 * @property-read int|null $order_details_sum_quantity
 * @property-read PointHistory|null $pointHistory
 * @property-read PointHistory|null $pointHistories
 * @property-read Review|null $review
 * @property-read PaymentProcess|null $paymentProcess
 * @property-read Collection|PaymentProcess[] $paymentProcesses
 * @property-read PaymentToPartner|null $paymentToPartner
 * @property-read int $payment_process_count
 * @property-read User|null $user
 * @property-read Shop|null $shop
 * @property-read OrderRepeat|null $repeat
 * @property-read User|Builder|null $deliveryMan
 * @property-read User|null $waiter
 * @property-read Table|null $table
 * @property-read Collection|Gallery[] $galleries
 * @property-read int|null $galleries_count
 * @property-read Collection|ModelLog[] $logs
 * @property-read int|null $logs_count
 * @method static OrderFactory factory(...$parameters)
 * @method static Builder|self filter($array)
 * @method static Builder|self newModelQuery()
 * @method static Builder|self newQuery()
 * @method static Builder|self query()
 * @method static Builder|self whereCommissionFee($value)
 * @method static Builder|self whereCreatedAt($value)
 * @method static Builder|self whereCurrencyId($value)
 * @method static Builder|self whereAddressId($value)
 * @method static Builder|self whereDeliveryDate($value)
 * @method static Builder|self whereDeliveryFee($value)
 * @method static Builder|self whereDeliveryTime($value)
 * @method static Builder|self whereDeliveryman($value)
 * @method static Builder|self whereId($value)
 * @method static Builder|self whereNote($value)
 * @method static Builder|self whereTotalPrice($value)
 * @method static Builder|self whereRate($value)
 * @method static Builder|self whereShopId($value)
 * @method static Builder|self whereStatus($value)
 * @method static Builder|self whereTax($value)
 * @method static Builder|self whereTotalDiscount($value)
 * @method static Builder|self whereUpdatedAt($value)
 * @method static Builder|self whereUserId($value)
 * @mixin Eloquent
 */
class Order extends Model
{
    use HasFactory, Payable, Reviewable, Loadable;

    protected $guarded = ['id'];

    protected $casts = [
        'location' => 'array',
        'address'  => 'array',
    ];
    
    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted()
    {
        static::updated(function ($order) {
            // Check if status was changed
            if ($order->isDirty('status')) {
                $oldStatus = $order->getOriginal('status');
                $newStatus = $order->status;
                
                \Log::info('Order status changed', [
                    'order_id' => $order->id,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'dirty' => $order->getDirty(),
                    'original' => $order->getOriginal()
                ]);
                
                // Only dispatch event if status actually changed
                if ($oldStatus !== $newStatus) {
                    // Get the reason if this is a cancellation
                    $reason = ($newStatus === self::STATUS_CANCELED) 
                        ? $order->cancel_reason ?? 'No reason provided' 
                        : null;
                        
                    // Log the event dispatching
                    \Log::info('Dispatching OrderStatusUpdated event', [
                        'order_id' => $order->id,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                        'reason' => $reason
                    ]);
                        
                    // Dispatch the event
                    Event::dispatch(new OrderStatusUpdated(
                        $order,
                        $oldStatus,
                        $newStatus,
                        $reason
                    ));
                }
            }
        });
    }

    const STATUS_NEW        = 'new';
    const STATUS_ACCEPTED   = 'accepted';
    const STATUS_PROCESSING = 'processing';   // For kitchen processing
    const STATUS_COOKING    = 'cooking';      // Alias for processing
    const STATUS_READY      = 'ready';        // Ready for pickup/delivery
    const STATUS_SHIPPED    = 'shipped';      // Order has been shipped
    const STATUS_ON_A_WAY   = 'on_a_way';    // For delivery in progress
    const STATUS_DELIVERED  = 'delivered';    // Final delivery status
    const STATUS_CANCELED   = 'canceled';

    const STATUSES = [
        self::STATUS_NEW        => self::STATUS_NEW,
        self::STATUS_ACCEPTED   => self::STATUS_ACCEPTED,
        self::STATUS_PROCESSING => self::STATUS_PROCESSING,
        self::STATUS_COOKING    => self::STATUS_COOKING,    
        self::STATUS_READY      => self::STATUS_READY,
        self::STATUS_SHIPPED    => self::STATUS_SHIPPED,
        self::STATUS_ON_A_WAY   => self::STATUS_ON_A_WAY,
        self::STATUS_DELIVERED  => self::STATUS_DELIVERED,
        self::STATUS_CANCELED   => self::STATUS_CANCELED,
    ];

    const COOKER_STATUSES = [
        self::STATUS_ACCEPTED   => self::STATUS_ACCEPTED,
        self::STATUS_PROCESSING => self::STATUS_PROCESSING,
        self::STATUS_COOKING    => self::STATUS_COOKING,
        self::STATUS_READY      => self::STATUS_READY,
        self::STATUS_CANCELED   => self::STATUS_CANCELED
    ];

    // Helper method to check if status is processing/cooking
    public function isProcessing(): bool 
    {
        return in_array($this->status, [
            self::STATUS_PROCESSING,
            self::STATUS_COOKING
        ]);
    }

    // Helper method to check if order is being delivered
    public function isDelivering(): bool
    {
        return $this->status === self::STATUS_ON_A_WAY;
    }

    // Helper method to check if order is shipped or in delivery
    public function isShippedOrDelivering(): bool
    {
        return in_array($this->status, [
            self::STATUS_SHIPPED,
            self::STATUS_ON_A_WAY
        ]);
    }

    const PICKUP    = 'pickup';
    const DELIVERY  = 'delivery';
    const POINT     = 'point';
    const DINE_IN   = 'dine_in';
    const KIOSK     = 'kiosk';

    const DELIVERY_TYPES = [
        self::PICKUP   => self::PICKUP,
        self::DELIVERY => self::DELIVERY,
        self::POINT    => self::POINT,
        self::DINE_IN  => self::DINE_IN,
        self::KIOSK    => self::KIOSK,
    ];

    const SHOP   = 'shop';
    const DRIVER = 'driver';
    const WAITER = 'waiter';
    const SYSTEM = 'system';

    const TIP_FOR = [
        self::SHOP   => self::SHOP,
        self::DRIVER => self::DRIVER,
        self::WAITER => self::WAITER,
        self::SYSTEM => self::SYSTEM,
    ];

    public function getTipForAttribute(?string $value): array
    {
        return $value ? explode(',', $value) : [];
    }

    public function getOriginPriceAttribute(): float|int
    {
        return $this->rate_total_price + $this->rate_total_discount - $this->rate_tax - $this->rate_delivery_fee - $this->rate_service_fee + $this->rate_coupon_price - $this->rate_tips;
    }

    public function getSellerFeeAttribute(): float|int
    {
        return $this->rate_total_price - $this->rate_delivery_fee - $this->rate_service_fee - $this->rate_commission_fee - $this->rate_coupon_price - $this->rate_point_histories_sum_price + $this->rate_shop_tip;
    }

    public function getCouponPriceAttribute(): float|int
    {
        $couponPrice = 0;

        if ($this->relationLoaded('coupon')) {
            $couponPrice = $this->coupon?->price ?? 0;
        }

        return $couponPrice;
    }

    public function getRateCouponPriceAttribute(): float|int
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->coupon_price * ($this->rate <= 0 ? 1 : $this->rate);
        }

        return $this->coupon_price;
    }

    public function getShopTipAttribute(): float|int
    {
        if ($this->rate_tips > 0 && in_array(self::SHOP, $this->tip_for)) {
            return $this->rate_tips / count($this->tip_for);
        }

        return 0;
    }

    public function getRateShopTipAttribute(): float|int
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->shop_tip * ($this->rate <= 0 ? 1 : $this->rate);
        }

        return $this->shop_tip;
    }

    public function getDriverTipAttribute(): float|int
    {
        if ($this->rate_tips > 0 && in_array(self::DRIVER, $this->tip_for)) {
            return $this->rate_tips / count($this->tip_for);
        }

        return 0;
    }

    public function getRateDriverTipAttribute(): float|int
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->driver_tip * ($this->rate <= 0 ? 1 : $this->rate);
        }

        return $this->driver_tip;
    }

    public function getWaiterTipAttribute(): float|int
    {
        if ($this->rate_tips > 0 && in_array(self::WAITER, $this->tip_for)) {
            return $this->rate_tips / count($this->tip_for);
        }

        return 0;
    }

    public function getRateWaiterTipAttribute(): float|int
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->waiter_tip * ($this->rate <= 0 ? 1 : $this->rate);
        }

        return $this->waiter_tip;
    }

    public function getSystemTipAttribute(): float|int
    {
        if ($this->rate_tips > 0 && in_array(self::SYSTEM, $this->tip_for)) {
            return $this->rate_tips / count($this->tip_for);
        }

        return 0;
    }

    public function getRateSystemTipAttribute(): float|int
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->system_tip * ($this->rate <= 0 ? 1 : $this->rate);
        }

        return $this->system_tip;
    }

    public function getRateTotalPriceAttribute(): ?float
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->total_price * ($this->rate <= 0 ? 1 : $this->rate);
        }

        return $this->total_price;
    }

    public function getRateTipsAttribute(): ?float
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->tips * ($this->rate <= 0 ? 1 : $this->rate);
        }

        return $this->tips;
    }

    public function getRateCouponSumPriceAttribute(): ?float
    {
        if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
            return $this->coupon_sum_price * ($this->rate <= 0 ? 1 : $this->rate);
        }

        return $this->coupon_sum_price;
    }

	public function getRatePointHistorySumPriceAttribute(): ?float
	{
		if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
			return $this->point_histories_sum_price * ($this->rate <= 0 ? 1 : $this->rate);
		}

		return $this->point_histories_sum_price;
	}

	public function getRateTotalDiscountAttribute(): ?float
	{
		if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
			return $this->total_discount * ($this->rate <= 0 ? 1 : $this->rate);
		}

		return $this->total_discount;
	}

	public function getRateDeliveryFeeAttribute(): ?float
	{
		if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
			return $this->delivery_fee * ($this->rate <= 0 ? 1 : $this->rate);
		}

		return $this->delivery_fee;
	}

	public function getRateWaiterFeeAttribute(): ?float
	{
		if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
			return $this->waiter_fee * ($this->rate <= 0 ? 1 : $this->rate);
		}

		return $this->waiter_fee;
	}

	public function getRateTaxAttribute(): ?float
	{
		if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
			return $this->tax * ($this->rate <= 0 ? 1 : $this->rate);
		}

		return $this->tax;
	}

	public function getRateCommissionFeeAttribute(): ?float
	{
		if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
			return $this->commission_fee * ($this->rate <= 0 ? 1 : $this->rate);
		}

		return $this->commission_fee;
	}

	public function getRateServiceFeeAttribute(): ?float
	{
		if (request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*')) {
			return $this->service_fee * ($this->rate <= 0 ? 1 : $this->rate);
		}

		return $this->service_fee;
	}

	public function currency(): BelongsTo
	{
		return $this->belongsTo(Currency::class)->withTrashed();
	}

	public function myAddress(): BelongsTo
	{
		return $this->belongsTo(UserAddress::class, 'address_id')->withTrashed();
	}

	public function shop(): BelongsTo
	{
		return $this->belongsTo(Shop::class)->withTrashed();
	}

	public function repeat(): HasOne
	{
		return $this->hasOne(OrderRepeat::class);
	}

	public function orderDetails(): HasMany
	{
		return $this->hasMany(OrderDetail::class);
	}

	public function coupon(): HasOne
	{
		return $this->hasOne(OrderCoupon::class, 'order_id');
	}

	public function deliveryMan(): BelongsTo
	{
		return $this->belongsTo(User::class, 'deliveryman')->withTrashed();
	}

	public function waiter(): BelongsTo
	{
		return $this->belongsTo(User::class, 'waiter_id')->withTrashed();
	}

	public function table(): BelongsTo
	{
		return $this->belongsTo(Table::class)->withTrashed();
	}

	public function user(): BelongsTo
	{
		return $this->belongsTo(User::class)->withTrashed();
	}

	public function pointHistory(): HasOne
	{
		return $this->hasOne(PointHistory::class, 'order_id')->latest();
	}

	public function paymentProcess(): MorphOne
	{
		return $this->morphOne(PaymentProcess::class, 'model');
	}

	public function paymentProcesses(): MorphMany
	{
		return $this->morphMany(PaymentProcess::class, 'model');
	}

	public function paymentToPartner(): HasOne
	{
		return $this->hasOne(PaymentToPartner::class);
	}

	public function pointHistories(): HasMany
	{
		return $this->hasMany(PointHistory::class);
	}

	public function orderRefunds(): HasMany
	{
		return $this->hasMany(OrderRefund::class);
	}

	public function logs(): MorphMany
	{
		return $this->morphMany(ModelLog::class, 'model');
	}

	/**
	 * @param $query
	 * @param $filter
	 * @return void
	 */
	public function scopeFilter($query, $filter): void
	{
		$orderIds = Utility::getDriverAccessibleOrderIds($filter);

		$orderByStatuses = [];

		if (is_array(data_get($filter, 'statuses'))) {

			$orderStatuses = OrderStatus::listNames();

			if (count($orderStatuses) === 0) {
				$orderStatuses = self::STATUSES;
			}

			$orderByStatuses = array_intersect($orderStatuses, data_get($filter, 'statuses'));
		}

		if (data_get($filter, 'debt')) {
			$filter['debt'] = Payment::where('tag', Payment::TAG_CASH)->first()?->id;
		}

		$isUser = request()->is('api/v1/dashboard/user/*') || request()->is('api/v1/rest/*');
		$column = data_get($filter, 'column', 'id');

		if (!Schema::hasColumn('orders', $column)) {
			$column = 'id';
		}

		$query
			->when(count($orderByStatuses) > 0, fn($q) => $q->whereIn('status', $orderByStatuses))
			->when(isset($filter['current']),   fn($q) => $q->where('current',  $filter['current']))
			->when(isset($filter['phone']),     fn($q) => $q->where('phone',    $filter['phone']))
			->when(isset($filter['email']),     fn($q) => $q->where('email',    $filter['email']))
			->when(isset($filter['tip_for']),   fn($q) => $q->where('tip_for',  'like', '%' . $filter['tip_for'] . '%'))

			->when(data_get($filter, 'empty-waiter'), 		fn($q) 		=> $q->whereNull('waiter_id'))
			->when(data_get($filter, 'isset-deliveryman'),	fn($q) 		=> $q->whereHas('deliveryMan'))
			->when(data_get($filter, 'isset-waiter'), 		fn($q) 		=> $q->whereNotNull('waiter_id'))
			->when(data_get($filter, 'status'), 	fn($q, $status) 	=> $q->where('status', $status))
			->when(data_get($filter, 'shop_id'), 	fn($q, $shopId)  	=> $q->where('shop_id', $shopId))
			->when(data_get($filter, 'shop_ids'),	fn($q, $shopIds) 	=> $q->whereIn('shop_id', (array)$shopIds))
			->when(data_get($filter, 'user_id'),  	fn($q, $userId)  	=> $q->where('user_id',   (int)$userId))
			->when(data_get($filter, 'table_id'), 	fn($q, $tableId) 	=> $q->where('table_id',  (int)$tableId))
			->when(data_get($filter, 'table_ids'), 	fn($q, $tableIds) 	=> $q->where('table_id',  $tableIds))
			->when(data_get($filter, 'waiter_id'),	fn($q, $waiterId)	=> $q->where('waiter_id', (int)$waiterId))
			->when(data_get($filter, 'status'),     fn($q, $status)     => $q->where('status', $status))
			->when(data_get($filter, 'delivery_type'),  fn ($q, $type)	=> $q->where('delivery_type', $type))
			->when(data_get($filter, 'order_statuses'), fn ($q) 		=> $q->orderBy('id', 'desc'))

			->when(data_get($filter, 'search'), function ($q, $search) {
				$q->where(function ($b) use ($search) {

					$b->where('id', 'LIKE', "%$search%")
						->orWhere('user_id', $search)
						->orWhere('phone', "%$search%")
						->orWhere('email', "%$search%")
						->orWhere('username', "%$search%")
						->orWhereHas('user', fn($q) => $q
							->where('firstname',  'LIKE', "%$search%")
							->orWhere('lastname', 'LIKE', "%$search%")
							->orWhere('email',    'LIKE', "%$search%")
							->orWhere('phone',    'LIKE', "%$search%")
						)
						->orWhere('note', 'LIKE', "%$search%");
				});
			})
			->when(data_get($filter, 'date_from'), function (Builder $query, $dateFrom) use ($filter) {

				$dateFrom = date('Y-m-d', strtotime($dateFrom));
				$dateTo = data_get($filter, 'date_to', date('Y-m-d'));

				$dateTo = date('Y-m-d', strtotime($dateTo));

				$query
					->whereDate('created_at', '>=', $dateFrom)
					->whereDate('created_at', '<=', $dateTo);
			})
			->when(data_get($filter, 'delivery_date_from'), function (Builder $query, $dateFrom) use ($filter) {

				$dateFrom = date('Y-m-d', strtotime($dateFrom));// . ' -1 day'

				$query->whereDate('delivery_date', '>=', $dateFrom);

				if (!empty(data_get($filter, 'delivery_date_to'))) {

					$dateTo = date('Y-m-d', strtotime(data_get($filter, 'delivery_date_to')));

					$query->whereDate('delivery_date', '<=', $dateTo);
				}

			})
			->when(data_get($filter, 'deliveryman'), fn(Builder $q, $deliveryman) =>
			$q->whereHas('deliveryMan', fn($q) => $q->where('id', $deliveryman))
			)
			->when(data_get($filter, 'empty-deliveryman'), fn($q) => $q
				->whereIn('id', $orderIds)
				->where(function ($q) {
					$q->whereNull('deliveryman')->orWhere('deliveryman', '=', null)->orWhere('deliveryman', '=', 0);
				})
			)
			->when(data_get($filter, 'request'), function ($q, $request) {
				$q->whereHas('transaction', function ($q) use ($request) {
					$q
						->where('request', $request)
						->whereHas('paymentSystem.payment', fn($q) => $q->where('tag', Payment::TAG_CASH));
				});
			})
			->when(data_get($filter, 'debt'), function ($q, $debt) use ($filter) {
				$q->whereHas('transaction', function ($q) use ($filter, $debt) {
					$q->where('payment_sys_id', $debt)->where('request', data_get($filter, 'request'));
				});
			})
			->when(!$isUser && isset($filter['waiting_order']), function ($q) {
				$q->whereHas('transactions', fn($q) => $q->where('status', Transaction::STATUS_PROGRESS));
			})
			->orderBy($column, data_get($filter, 'sort', 'desc'));
	}

	/**
	 * Scope a query to only include active orders.
	 *
	 * @param \Illuminate\Database\Eloquent\Builder $query
	 * @return \Illuminate\Database\Eloquent\Builder
	 */
	public function scopeActive($query)
	{
		return $query->whereNotIn('status', [
			self::STATUS_DELIVERED,
			self::STATUS_CANCELED
		])->where('current', 1);
	}

}
