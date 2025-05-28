<?php

namespace App\Http\Controllers\API\v1\Rest;

use App\Helpers\NotificationHelper;
use App\Helpers\ResponseError;
use App\Http\Requests\FilterParamsRequest;
use App\Http\Requests\Order\AddReviewRequest;
use App\Http\Requests\Order\RestStoreRequest;
use App\Http\Requests\Order\UpdateTipsRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\UserResource;
use App\Models\Order;
use App\Models\Settings;
use App\Repositories\OrderRepository\OrderRepository;
use App\Services\OrderService\OrderReviewService;
use App\Services\OrderService\OrderService;
use App\Services\OrderService\OrderStatusUpdateService;
use App\Traits\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends RestBaseController
{
	use Notification;

    public function __construct(
        private OrderRepository $orderRepository,
        private OrderService $orderService
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
		if (!$request->input('phone')) {
			return OrderResource::collection([]);
		}

		$filter = $request->merge(['phone' => $request->input('phone')])->all();

		$orders = $this->orderRepository->ordersPaginate($filter, isUser: true);

		return OrderResource::collection($orders);
	}

    /**
     * Store a newly created resource in storage.
     *
     * @param RestStoreRequest $request
     * @return JsonResponse
     */
    public function store(RestStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->orderService->create($validated);

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
            $this->orderRepository->reDataOrder(data_get($result, 'data'))
        );
    }

	/**
	 * Display the specified resource.
	 *
	 * @param int $id
	 * @param FilterParamsRequest $request
	 * @return JsonResponse
	 */
    public function show(int $id, FilterParamsRequest $request): JsonResponse
    {
		$phone = $request->input('phone');

        $order = $this->orderRepository->orderById($id, phone: $phone);

		if (!$phone && !$order->table_id) {
			return $this->onErrorResponse([
			 	'code'    => ResponseError::ERROR_404,
			 	'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
			]);
		}

        return $this->successResponse(ResponseError::NO_ERROR, $this->orderRepository->reDataOrder($order));
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @param UpdateTipsRequest $request
     * @return JsonResponse
     */
    public function updateTips(int $id, UpdateTipsRequest $request): JsonResponse
    {
        $order = $this->orderService->updateTips($id, $request->validated());

        return $this->successResponse(ResponseError::NO_ERROR, $this->orderRepository->reDataOrder($order));
    }

    /**
     * Add Review to Order.
     *
     * @param int $id
     * @param AddReviewRequest $request
     * @return JsonResponse
     */
    public function addOrderReview(int $id, AddReviewRequest $request): JsonResponse
    {
        /** @var Order $order */
        $order = Order::with(['review', 'reviews'])->find($id);

        $result = (new OrderReviewService)->addReview($order, $request->validated());

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            ResponseError::NO_ERROR,
            $this->orderRepository->reDataOrder(data_get($result, 'data'))
        );

    }

	/**
	 * @param int $id
	 * @param FilterParamsRequest $request
	 * @return JsonResponse
	 */
	public function orderStatusChange(int $id, FilterParamsRequest $request): JsonResponse
	{

		$phone = $request->input('phone');
		$email = $request->input('email');

		if (!$phone && !$email) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_404,
				'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
			]);
		}

		/** @var Order  $order */
		$order = Order::with([
			'shop.seller',
			'deliveryMan',
			'user.wallet',
		])->find($id);

		if (!$order) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_404,
				'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
			]);
		}

		if (!$request->input('status')) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_254,
				'message' => __('errors.' . ResponseError::EMPTY_STATUS, locale: $this->language)
			]);
		}

		if ($order->status !== Order::STATUS_NEW) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_254,
				'message' => __('errors.' . ResponseError::ERROR_254, locale: $this->language)
			]);
		}

		if ($phone && $order->phone !== $phone) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_254,
				'message' => __('errors.' . ResponseError::ERROR_254, locale: $this->language)
			]);
		}

		if ($email && $order->email !== $email) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_254,
				'message' => __('errors.' . ResponseError::ERROR_254, locale: $this->language)
			]);
		}

		$result = (new OrderStatusUpdateService)->statusUpdate($order, $request->input('status'));

		if (!data_get($result, 'status')) {
			return $this->onErrorResponse($result);
		}

		return $this->successResponse(
			ResponseError::NO_ERROR,
			$this->orderRepository->reDataOrder(data_get($result, 'data'))
		);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param int $id
	 * @return JsonResponse
	 */
	public function showByTableId(int $id): JsonResponse
	{
		$order = $this->orderRepository->orderByTableId($id);

		return $this->successResponse(ResponseError::NO_ERROR, $this->orderRepository->reDataOrder($order));
	}

	/**
	 * Display the specified resource.
	 *
	 * @param string $id
	 * @return JsonResponse
	 */
	public function clicked(string $id): JsonResponse
	{
		$result = $this->orderService->clicked($id);

		return $this->successResponse(ResponseError::NO_ERROR, $result);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param string $id
	 * @return JsonResponse
	 */
	public function callWaiter(string $id): JsonResponse
	{
		$this->orderService->callWaiter($id);

		return $this->successResponse(ResponseError::NO_ERROR, []);
	}

	/**
	 * Display the specified resource.
	 *
	 * @param int $id
	 * @return JsonResponse
	 */
	public function showDeliveryman(int $id): JsonResponse
	{
        $user = $this->orderRepository->showDeliveryman($id);

        if (empty($user)) {
            return $this->onErrorResponse([
                'code' => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
            ]);
        }

		return $this->successResponse(ResponseError::NO_ERROR, UserResource::make($user));
	}

	/**
	 * Display the specified resource.
	 *
	 * @param FilterParamsRequest $request
	 * @return JsonResponse
	 */
	public function deleteOrderDetail(FilterParamsRequest $request): JsonResponse
	{
		$this->orderService->deleteOrderDetail($request->input('ids', []));

		return $this->successResponse(ResponseError::NO_ERROR, []);
	}

}
