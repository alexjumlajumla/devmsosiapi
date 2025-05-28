<?php

namespace App\Services\ShopServices;

use App\Helpers\FileHelper;
use App\Helpers\ResponseError;
use App\Models\Shop;
use App\Services\CoreService;
use App\Services\Interfaces\ShopServiceInterface;
use App\Services\ShopCategoryService\ShopCategoryService;
use App\Traits\SetTranslations;
use DB;
use Exception;
use Illuminate\Support\Facades\Cache;
use Throwable;
use App\Models\Settings;

class ShopService extends CoreService implements ShopServiceInterface
{
	use SetTranslations;

    protected function getModelClass(): string
    {
        return Shop::class;
    }

    /**
     * Create a new Shop model.
     * @param array $data
     * @return array
     */
    public function create(array $data): array
    {
        try {
            $shopId = DB::transaction(function () use($data) {

                /** @var Shop $shop */
                $shop = $this->model()->query()->create($this->setShopParams($data));

                $this->setTranslations($shop, $data, true, true);

                if (data_get($data, 'images.0')) {
                    $shop->update([
                        'logo_img'       => data_get($data, 'images.0'),
                        'background_img' => data_get($data, 'images.1'),
                    ]);
                    $shop->uploads(data_get($data, 'images'));
                }

                if (data_get($data, 'documents.0')) {
                    $shop->uploads(data_get($data, 'documents'), 'shop-documents');
                }

                (new ShopCategoryService)->update($data, $shop);

                if (data_get($data, 'tags.0')) {
                    $shop->tags()->sync(data_get($data, 'tags', []));
                }

                try {
                    Cache::forget('shops-location');
                } catch (Throwable) {}

                return $shop->id;
            });

            return [
                'status' => true,
                'code' => ResponseError::NO_ERROR,
                'data' => Shop::with([
                    'translation' 			 => fn($q) => $q->where('locale', $this->language),
					'subscription' 			 => fn($q) => $q->where('expired_at', '>=', now())->where('active', true),
                    'categories.translation' => fn($q) => $q->where('locale', $this->language),
                    'tags.translation' 		 => fn($q) => $q->where('locale', $this->language),
                    'seller' 				 => fn($q) => $q->select('id', 'firstname', 'lastname', 'uuid'),
					'subscription.subscription',
					'seller.roles',
				])->find($shopId)
            ];
        } catch (Throwable $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_501,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Update specified Shop model.
     * @param string $uuid
     * @param array $data
     * @return array
     */
    public function update(string $uuid, array $data): array
    {
        try {
            /** @var Shop $shop */
            $shop = $this->model();

            $shop = $shop->when(data_get($data, 'user_id'), fn($q, $userId) => $q->where('user_id', $userId))
                ->where('uuid', $uuid)
                ->first();

            if (empty($shop)) {
                return ['status' => false, 'code' => ResponseError::ERROR_404];
            }

            $shop->update($this->setShopParams($data, $shop));

            if(data_get($data, 'categories.*', [])) {
                (new ShopCategoryService)->update($data, $shop);
            }

            $this->setTranslations($shop, $data, true, true);

            if (data_get($data, 'images.0')) {
                $shop->galleries()->where('type', '!=', 'shop-documents')->delete();
                $shop->update([
                    'logo_img'       => data_get($data, 'images.0'),
                    'background_img' => data_get($data, 'images.1'),
                ]);
                $shop->uploads(data_get($data, 'images'));
            }

			if (data_get($data, 'documents.0')) {
				$shop->uploads(data_get($data, 'documents'));
			}

            if (data_get($data, 'tags.0')) {
                $shop->tags()->sync(data_get($data, 'tags', []));
            }

            return [
                'status' => true,
                'code' => ResponseError::NO_ERROR,
                'data' => Shop::with([
					'translation' 			 => fn($q) => $q->where('locale', $this->language),
					'subscription' 			 => fn($q) => $q->where('expired_at', '>=', now())->where('active', true),
                    'categories.translation' => fn($q) => $q->where('locale', $this->language),
                    'tags.translation'  	 => fn($q) => $q->where('locale', $this->language),
                    'seller' 				 => fn($q) => $q->select('id', 'firstname', 'lastname', 'uuid'),
					'subscription.subscription',
					'seller.roles',
                    'workingDays',
                    'closedDates',
                ])->find($shop->id)
            ];
        } catch (Exception $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

    /**
     * Delete Shop model.
     * @param array|null $ids
     * @return array
     */
    public function delete(?array $ids = []): array
    {
        foreach (Shop::with(['seller'])->whereIn('id', is_array($ids) ? $ids : [])->get() as $shop) {

            /** @var Shop $shop */
            FileHelper::deleteFile($shop->logo_img);
            FileHelper::deleteFile($shop->background_img);

            if (!is_null($shop->seller) && !$shop->seller->hasRole('admin')) {
                $shop->seller->syncRoles('user');
            }

            $shop->delete();
        }

        try {
            Cache::forget('shops-location');
            Cache::forget('delivery-zone-list');
        } catch (Exception) {}

        return ['status' => true, 'code' => ResponseError::NO_ERROR];
    }

    /**
     * Set params for Shop to update or create model.
     * @param array $data
     * @param Shop|null $shop
     * @return array
     */
    private function setShopParams(array $data, ?Shop $shop = null): array
    {
        $location       = data_get($data, 'location', $shop?->location);
        $deliveryTime   = [
            'from'	=> data_get($data, 'delivery_time_from', data_get($shop?->delivery_time, 'from', '0')),
            'to'  	=> data_get($data, 'delivery_time_to',   data_get($shop?->delivery_time, 'to', '0')),
            'type'	=> data_get($data, 'delivery_time_type', data_get($shop?->delivery_time, 'type', Shop::DELIVERY_TIME_MINUTE)),
        ];

        // Get default delivery settings from admin
        $defaultDeliverySettings = Settings::whereIn('key', [
            'delivery_price',
            'delivery_price_per_km',
            'delivery_time_from',
            'delivery_time_to',
            'delivery_time_type'
        ])->get()->pluck('value', 'key');

        // Use admin settings for delivery if user is not admin
        $isAdmin = auth('sanctum')->user()?->hasRole('admin');
        
        $price = $isAdmin ? 
            data_get($data, 'price', $shop?->price ?? 0) : 
            (float)data_get($defaultDeliverySettings, 'delivery_price', 0);

        $pricePerKm = $isAdmin ? 
            data_get($data, 'price_per_km', $shop?->price_per_km ?? 0) : 
            (float)data_get($defaultDeliverySettings, 'delivery_price_per_km', 0);

        if (!$isAdmin) {
            $deliveryTime = [
                'from' => data_get($defaultDeliverySettings, 'delivery_time_from', '0'),
                'to'   => data_get($defaultDeliverySettings, 'delivery_time_to', '0'),
                'type' => data_get($defaultDeliverySettings, 'delivery_time_type', Shop::DELIVERY_TIME_MINUTE),
            ];
        }

        return [
            'user_id'        => data_get($data, 'user_id', !auth('sanctum')->user()->hasRole('admin') ? auth('sanctum')->id() : null),
            'tax'            => data_get($data, 'tax', $shop?->tax),
			'email_statuses' => data_get($data, 'email_statuses'),
			'percentage'     => data_get($data, 'percentage', $shop?->percentage ?? 0),
            'min_amount'     => data_get($data, 'min_amount', $shop?->min_amount ?? 0),
            'phone'          => data_get($data, 'phone'),
            'order_payment'  => data_get($data, 'order_payment', Shop::ORDER_PAYMENT_BEFORE),
            'new_order_after_payment'  => data_get($data, 'new_order_after_payment', 0),
            'open'           => data_get($data, 'open', $shop?->open ?? 0),
            'delivery_time'  => $deliveryTime,
            'show_type'      => data_get($data, 'show_type', $shop?->show_type ?? 1),
            'visibility'     => !!$shop?->visibility,
            'status_note'    => data_get($data, 'status_note', $shop?->status_note ?? ''),
            'price'          => $price,
            'price_per_km'   => $pricePerKm,
            'verify'         => data_get($data, 'verify', $shop?->verify ?? 0),
            'wifi_password'  => data_get($data, 'wifi_password'),
            'wifi_name'      => data_get($data, 'wifi_name'),
            'location'       => [
                'latitude'   => data_get($location, 'latitude', data_get($shop?->location, 'latitude', 0)),
                'longitude'  => data_get($location, 'longitude', data_get($shop?->location, 'longitude', 0)),
            ],
        ];
    }

    /**
     * @param string $uuid
     * @param array $data
     * @return array
     */
    public function imageDelete(string $uuid, array $data): array
    {
        /** @var Shop|null $shop */
        $shop = Shop::where('uuid', $uuid)->first();

        if (empty($shop)) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
            ];
        }

        $shop->galleries()
            ->where('path', data_get($data, 'tag') === 'background' ? $shop->background_img : $shop->logo_img)
            ->delete();

        $shop->update([data_get($data, 'tag') . '_img' => null]);

        return [
            'status' => true,
            'code'   => ResponseError::NO_ERROR,
            'data'   => $shop->refresh(),
        ];
    }

	/**
	 * @param int|string $uuid
	 * @return array
	 */
	public function updateVerify(int|string $uuid): array
	{
		$shop = Shop::where('uuid', $uuid)->first();

		if (empty($shop) || $shop->uuid !== $uuid) {
			$shop = Shop::where('id', (int)$uuid)->first();
		}

		if (empty($shop)) {
			return [
				'status'	=> false,
				'code'      => ResponseError::ERROR_404,
				'message'   => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
			];
		}

		$shop->update(['verify' => !$shop->verify]);

		return [
			'status' => true,
			'data'	 => $shop
		];
	}

}
