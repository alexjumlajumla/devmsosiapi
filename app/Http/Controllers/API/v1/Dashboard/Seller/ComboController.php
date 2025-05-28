<?php
declare(strict_types=1);

namespace App\Http\Controllers\API\v1\Dashboard\Seller;

use App\Helpers\ResponseError;
use App\Http\Requests\Combo\StoreRequest;
use App\Http\Requests\FilterParamsRequest;
use App\Http\Resources\ComboResource;
use App\Models\Combo;
use App\Repositories\ComboRepository\ComboRepository;
use App\Services\ComboService\ComboService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ComboController extends SellerBaseController
{
    public function __construct(
        private ComboRepository $repository,
        private ComboService $service
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
		$filter = $request->merge(['shop_id' => $this->shop->id])->all();

        $models = $this->repository->paginate($filter);

        return ComboResource::collection($models);
    }

    /**
     * Display the specified resource.
     *
     * @param StoreRequest $request
     * @return JsonResponse
     */
    public function store(StoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
		$validated['shop_id'] = $this->shop->id;

        $result = $this->service->create($validated);

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(__('errors.' . ResponseError::NO_ERROR, locale: $this->language), []);
    }

	/**
	 * Display the specified resource.
	 *
	 * @param Combo $combo
	 * @return JsonResponse
	 */
    public function show(Combo $combo): JsonResponse
    {
		if ($combo->shop_id !== $this->shop->id) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_404,
				'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
			]);
		}

		$combo = $this->repository->show($combo);

        return $this->successResponse(
			__('errors.' . ResponseError::NO_ERROR, locale: $this->language),
			ComboResource::make($combo)
		);
    }

	/**
	 * Store a newly created resource in storage.
	 *
	 * @param Combo $combo
	 * @param StoreRequest $request
	 * @return JsonResponse
	 */
    public function update(Combo $combo, StoreRequest $request): JsonResponse
    {
		if ($combo->shop_id !== $this->shop->id) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_404,
				'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
			]);
		}

        $result = $this->service->update($combo, $request->validated());

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_UPDATED, locale: $this->language),
            []
        );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param FilterParamsRequest $request
     * @return JsonResponse
     */
    public function destroy(FilterParamsRequest $request): JsonResponse
    {
        $this->service->delete($request->input('ids', []), $this->shop->id);

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_DELETED, locale: $this->language),
            []
        );
    }

}
