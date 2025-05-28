<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Http\Requests\FilterParamsRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\LoanService\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LoanController extends AdminBaseController
{
    private LoanService $service;

    public function __construct(LoanService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    /**
     * Display a listing of loans
     */
    public function index(FilterParamsRequest $request): AnonymousResourceCollection
    {
        $loans = Loan::with(['user', 'disbursedBy', 'repayments'])
            ->when($request->input('user_id'), fn($q) => $q->where('user_id', $request->input('user_id')))
            ->when($request->input('status'), fn($q) => $q->where('status', $request->input('status')))
            ->orderBy($request->input('column', 'id'), $request->input('sort', 'desc'))
            ->paginate($request->input('perPage', 15));

        return LoanResource::collection($loans);
    }

    /**
     * Disburse a new loan
     */
    public function store(Request $request): JsonResponse
    {
        $result = $this->service->disburseLoan($request->all());

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
            LoanResource::make(data_get($result, 'data'))
        );
    }

    /**
     * Display loan details
     */
    public function show(int $id): JsonResponse
    {
        $loan = Loan::with(['user', 'disbursedBy', 'repayments'])->find($id);

        if (!$loan) {
            return $this->onErrorResponse([
                'code' => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
            ]);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            LoanResource::make($loan)
        );
    }

    /**
     * Record a loan repayment
     */
    public function recordRepayment(Request $request): JsonResponse
    {
        $result = $this->service->recordRepayment($request->all());

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
            data_get($result, 'data')
        );
    }

    /**
     * Get loan statistics for a user
     */
    public function userStats(int $userId): JsonResponse
    {
        $stats = $this->service->getUserLoanStats($userId);

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            $stats
        );
    }
}
