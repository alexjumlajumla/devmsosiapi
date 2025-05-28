<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Http\Controllers\API\v1\Dashboard\Admin\AdminBaseController;
use App\Http\Requests\BroadcastSendRequest;
use App\Services\BroadcastService\BroadcastService;
use Illuminate\Http\JsonResponse;

class BroadcastController extends AdminBaseController
{
    public function __construct(private BroadcastService $service)
    {
        parent::__construct();
    }

    /**
     * List broadcasts (simple pagination).
     */
    public function index(): JsonResponse
    {
        $broadcasts = \App\Models\Broadcast::latest()->paginate(20);

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            $broadcasts
        );
    }

    /**
     * Send new broadcast.
     */
    public function send(BroadcastSendRequest $request): JsonResponse
    {
        $stats = $this->service->send($request->validated());

        return $this->successResponse(
            __('errors.' . ResponseError::SUCCESS, locale: $this->language),
            $stats
        );
    }

    /**
     * Resend broadcast.
     */
    public function resend(int $id): JsonResponse
    {
        $stats = $this->service->resend($id);

        return $this->successResponse('Resent', $stats);
    }
} 