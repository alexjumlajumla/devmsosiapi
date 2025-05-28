<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Http\Requests\FilterParamsRequest;
use App\Http\Resources\WalletHistoryResource;
use App\Models\WalletHistory;
use App\Services\WalletHistoryService\WalletHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WalletController extends AdminBaseController
{
    private WalletHistoryService $service;

    public function __construct(WalletHistoryService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Display a listing of wallet histories
     */
    public function index(FilterParamsRequest $request): AnonymousResourceCollection
    {
        $histories = WalletHistory::with(['user', 'author', 'transaction'])
            ->when($request->input('user_id'), fn($q) => $q->where('user_id', $request->input('user_id')))
            ->when($request->input('type'), fn($q) => $q->where('type', $request->input('type')))
            ->when($request->input('status'), fn($q) => $q->where('status', $request->input('status')))
            ->orderBy($request->input('column', 'id'), $request->input('sort', 'desc'))
            ->paginate($request->input('perPage', 15));

        return WalletHistoryResource::collection($histories);
    }

    /**
     * Process bulk wallet transfers
     */
    public function bulkTransfer(Request $request): JsonResponse
    {
        $result = $this->service->bulkTransfer($request->all());

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
            data_get($result, 'results')
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
