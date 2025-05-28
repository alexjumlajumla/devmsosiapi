<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Http\Requests\FilterParamsRequest;
use App\Repositories\LoanRepository\LoanAnalyticsRepository;
use Illuminate\Http\JsonResponse;
use Throwable;

class LoanAnalyticsController extends AdminBaseController
{
    private LoanAnalyticsRepository $repository;

    public function __construct(LoanAnalyticsRepository $repository)
    {
        parent::__construct();
        $this->repository = $repository;
    }

    /**
     * Get loan statistics for dashboard
     */
    public function getStatistics(FilterParamsRequest $request): JsonResponse
    {
        try {
            $result = $this->repository->getStatistics($request->validated());

            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                $result
            );
        } catch (Throwable $e) {
            $this->error($e);
            return $this->errorResponse(
                ResponseError::ERROR_400,
                __('errors.' . ResponseError::ERROR_400, locale: $this->language)
            );
        }
    }

    /**
     * Get loan disbursement chart data
     */
    public function getDisbursementChart(FilterParamsRequest $request): JsonResponse
    {
        try {
            $result = $this->repository->getDisbursementChart($request->validated());

            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                $result
            );
        } catch (Throwable $e) {
            $this->error($e);
            return $this->errorResponse(
                ResponseError::ERROR_400,
                __('errors.' . ResponseError::ERROR_400, locale: $this->language)
            );
        }
    }

    /**
     * Get loan repayment chart data
     */
    public function getRepaymentChart(FilterParamsRequest $request): JsonResponse
    {
        try {
            $result = $this->repository->getRepaymentChart($request->validated());

            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                $result
            );
        } catch (Throwable $e) {
            $this->error($e);
            return $this->errorResponse(
                ResponseError::ERROR_400,
                __('errors.' . ResponseError::ERROR_400, locale: $this->language)
            );
        }
    }

    /**
     * Get loan status distribution
     */
    public function getStatusDistribution(): JsonResponse
    {
        try {
            $result = $this->repository->getStatusDistribution();

            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                $result
            );
        } catch (Throwable $e) {
            $this->error($e);
            return $this->errorResponse(
                ResponseError::ERROR_400,
                __('errors.' . ResponseError::ERROR_400, locale: $this->language)
            );
        }
    }

    /**
     * Get payment method distribution
     */
    public function getPaymentMethodDistribution(): JsonResponse
    {
        try {
            $result = $this->repository->getPaymentMethodDistribution();

            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                $result
            );
        } catch (Throwable $e) {
            $this->error($e);
            return $this->errorResponse(
                ResponseError::ERROR_400,
                __('errors.' . ResponseError::ERROR_400, locale: $this->language)
            );
        }
    }
} 