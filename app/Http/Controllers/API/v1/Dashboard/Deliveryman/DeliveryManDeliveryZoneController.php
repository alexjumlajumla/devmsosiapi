<?php

namespace App\Http\Controllers\API\v1\Dashboard\Deliveryman;

use App\Helpers\ResponseError;
use App\Http\Requests\DeliveryManDeliveryZone\StoreRequest;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\DeliveryManDeliveryZoneResource;
use App\Services\DeliveryManDeliveryZoneService\DeliveryManDeliveryZoneService;
use App\Repositories\DeliveryManDeliveryZoneRepository\DeliveryManDeliveryZoneRepository;

class DeliveryManDeliveryZoneController extends DeliverymanBaseController
{
    public function __construct(
		private DeliveryManDeliveryZoneService $service,
		private DeliveryManDeliveryZoneRepository $repository
	)
    {
        parent::__construct();
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
		$validated['user_id'] = auth('sanctum')->id();

        $result = $this->service->create($validated);

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language)
        );
    }

	/**
	 * Display the specified resource.
	 *
	 * @return JsonResponse
	 */
    public function show(): JsonResponse
    {
        $result = $this->repository->show(auth('sanctum')->id());

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            DeliveryManDeliveryZoneResource::make($result['data'])
        );
    }

}
