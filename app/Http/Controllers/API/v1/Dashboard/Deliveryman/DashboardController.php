<?php

namespace App\Http\Controllers\API\v1\Dashboard\Deliveryman;

use App\Helpers\ResponseError;
use App\Http\Requests\FilterParamsRequest;
use App\Models\DriverShopBans;
use App\Models\Language;
use App\Repositories\DashboardRepository\DashboardRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Throwable;

class DashboardController extends DeliverymanBaseController
{
	/**
	 * @param FilterParamsRequest $request
	 * @return JsonResponse
	 */
	public function countStatistics(FilterParamsRequest $request): JsonResponse
	{
		$filter = $request->merge(['deliveryman' => auth('sanctum')->id()])->all();

		return $this->successResponse(
			__('errors.' . ResponseError::SUCCESS, locale: $this->language),
			(new DashboardRepository)->orderByStatusStatistics($filter)
		);
	}

	/**
	 * @param FilterParamsRequest $request
	 * @return LengthAwarePaginator
	 */
	public function banList(FilterParamsRequest $request): LengthAwarePaginator
	{
		$locale = data_get(Language::languagesList()->where('default', 1)->first(), 'locale');

		return DriverShopBans::filter($request->merge(['user_id' => auth('sanctum')->id()])->all())
			->with([
				'user:id,uuid,firstname,lastname,email,phone,birthday,gender,active,img',
				'shop.translation' => fn($q) => $q->select('id', 'shop_id', 'locale', 'title')
					->where(fn($q) => $q->where('locale', $this->language)->orWhere('locale', $locale))
			])
			->paginate($request->input('perPage', 10));
	}

	/**
	 * @param FilterParamsRequest $request
	 * @return JsonResponse
	 */
	public function banStore(FilterParamsRequest $request): JsonResponse
	{
		$shopIds = (array)$request->input('shop_ids', []);

		DriverShopBans::where('user_id', auth('sanctum')->id())->delete();

		foreach ($shopIds as $shopId) {
			try {
				DriverShopBans::create([
					'user_id'    => auth('sanctum')->id(),
					'shop_id'    => $shopId,
					'created_at' => now(),
					'updated_at' => now(),
				]);
			} catch (Throwable) {}
		}

		return $this->successResponse(
			__('errors.' . ResponseError::SUCCESS, locale: $this->language),

		);
	}
}
