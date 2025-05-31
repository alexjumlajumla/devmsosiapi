<?php

namespace App\Http\Controllers\API\v1\User;

use App\Http\Controllers\API\v1\RestBaseController;
use App\Http\Resources\VfdReceiptResource;
use App\Models\Order;
use App\Services\Order\VfdReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VfdReceiptController extends RestBaseController
{
    protected $vfdReceiptService;

    public function __construct(VfdReceiptService $vfdReceiptService)
    {
        parent::__construct();
        $this->vfdReceiptService = $vfdReceiptService;
    }

    /**
     * Get receipt for an order
     *
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function getReceipt(Request $request, int $orderId): JsonResponse
    {
        $order = Order::find($orderId);

        if (!$order) {
            return $this->errorResponse(
                __('errors.order_not_found'),
                404
            );
        }

        // Check if the authenticated user owns this order
        if ($order->user_id !== auth('sanctum')->id()) {
            return $this->errorResponse(
                __('errors.unauthorized'),
                403
            );
        }

        $receipt = $this->vfdReceiptService->getForOrder($order);

        if (!$receipt) {
            return $this->errorResponse(
                __('errors.receipt_not_found'),
                404
            );
        }

        return $this->successResponse(
            __('web.record_has_been_successfully_found'),
            VfdReceiptResource::make($receipt)
        );
    }

    /**
     * Generate receipt for an order
     * 
     * @param Request $request
     * @param int $orderId
     * @return JsonResponse
     */
    public function generateReceipt(Request $request, int $orderId): JsonResponse
    {
        $order = Order::find($orderId);

        if (!$order) {
            return $this->errorResponse(
                __('errors.order_not_found'),
                404
            );
        }

        // Check if the authenticated user owns this order
        if ($order->user_id !== auth('sanctum')->id()) {
            return $this->errorResponse(
                __('errors.unauthorized'),
                403
            );
        }

        // Only allow generating receipts for delivered orders
        if ($order->status !== Order::STATUS_DELIVERED) {
            return $this->errorResponse(
                __('errors.receipt_can_only_be_generated_for_delivered_orders'),
                400
            );
        }

        // Only generate if there's a delivery fee
        if ($order->delivery_fee <= 0) {
            return $this->errorResponse(
                __('errors.no_delivery_fee_for_this_order'),
                400
            );
        }

        $result = $this->vfdReceiptService->generateForOrder(
            $order,
            $order->paymentProcess?->payment?->tag ?? 'cash'
        );

        if (!$result['status']) {
            return $this->errorResponse(
                $result['message'] ?? __('errors.failed_to_generate_receipt'),
                500
            );
        }

        return $this->successResponse(
            $result['message'],
            VfdReceiptResource::make($result['data'])
        );
    }
}
