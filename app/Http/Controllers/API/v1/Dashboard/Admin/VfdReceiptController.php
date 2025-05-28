<?php

namespace App\Http\Controllers\API\v1\Dashboard\Admin;

use App\Helpers\ResponseError;
use App\Http\Resources\VfdReceiptResource;
use App\Models\VfdReceipt;
use App\Services\VfdService\VfdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VfdReceiptController extends AdminBaseController
{
    public function __construct(private VfdService $service)
    {
        parent::__construct();
    }

    /**
     * Generate a VFD receipt for delivery or subscription
     */
    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|in:delivery,subscription',
            'model_id' => 'required|integer',
            'model_type' => 'required|string',
            'amount' => 'required|numeric',
            'payment_method' => 'required|string',
            'customer_name' => 'nullable|string',
            'customer_phone' => 'nullable|string',
            'customer_email' => 'nullable|email'
        ]);

        $result = $this->service->generateReceipt($data['type'], $data);

        if (!$result['status']) {
            return $this->onErrorResponse([
                'code' => ResponseError::ERROR_400,
                'message' => $result['message']
            ]);
        }

        return $this->successResponse(
            __('web.record_was_successfully_created'),
            VfdReceiptResource::make($result['data'])
        );
    }

    /**
     * Get receipt details
     */
    public function show(VfdReceipt $receipt): JsonResponse
    {
        return $this->successResponse(
            __('web.record_has_been_successfully_found'),
            VfdReceiptResource::make($receipt)
        );
    }

    /**
     * List all receipts with filters
     */
    public function index(Request $request): JsonResponse
    {
        $receipts = VfdReceipt::query()
            ->when($request->type, fn($q) => $q->where('receipt_type', $request->type))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->date_from, fn($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->latest()
            ->paginate($request->input('per_page', 15));

        return $this->successResponse(
            __('web.list_of_records_found'),
            VfdReceiptResource::collection($receipts)
        );
    }

    /**
     * Search VFD receipts with advanced filters
     */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'receipt_number' => 'nullable|string|max:50',
            'customer' => 'nullable|string|max:255',
            'amount_from' => 'nullable|numeric|min:0',
            'amount_to' => 'nullable|numeric|min:0|gt:amount_from',
            'type' => 'nullable|in:' . implode(',', [VfdReceipt::TYPE_DELIVERY, VfdReceipt::TYPE_SUBSCRIPTION]),
            'status' => 'nullable|in:' . implode(',', [VfdReceipt::STATUS_PENDING, VfdReceipt::STATUS_GENERATED, VfdReceipt::STATUS_FAILED]),
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = VfdReceipt::query();

        if ($request->filled('receipt_number')) {
            $query->where('receipt_number', 'like', "%{$data['receipt_number']}%");
        }

        if ($request->filled('customer')) {
            $searchTerm = $data['customer'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('customer_name', 'like', "%{$searchTerm}%")
                    ->orWhere('customer_phone', 'like', "%{$searchTerm}%")
                    ->orWhere('customer_email', 'like', "%{$searchTerm}%");
            });
        }

        if ($request->filled('amount_from')) {
            $query->where('amount', '>=', $data['amount_from']);
        }

        if ($request->filled('amount_to')) {
            $query->where('amount', '<=', $data['amount_to']);
        }

        $query->when($data['type'] ?? null, fn($q) => $q->where('receipt_type', $data['type']))
            ->when($data['status'] ?? null, fn($q) => $q->where('status', $data['status']))
            ->when($data['date_from'] ?? null, fn($q) => $q->whereDate('created_at', '>=', $data['date_from']))
            ->when($data['date_to'] ?? null, fn($q) => $q->whereDate('created_at', '<=', $data['date_to']));

        $receipts = $query->latest()->paginate($data['per_page'] ?? 15);

        return $this->successResponse(
            __('web.search_results_found'),
            VfdReceiptResource::collection($receipts)
        );
    }

    /**
     * Delete a VFD receipt
     */
    public function destroy(VfdReceipt $receipt): JsonResponse
    {
        try {
            // Check if receipt can be deleted (not in generated state)
            if ($receipt->status === VfdReceipt::STATUS_GENERATED) {
                return $this->onErrorResponse([
                    'code' => ResponseError::ERROR_400,
                    'message' => __('web.record_cannot_be_deleted')
                ]);
            }

            $receipt->delete();
            
            return $this->successResponse(
                __('web.record_was_successfully_deleted'),
                VfdReceiptResource::make($receipt)
            );
        } catch (\Exception $e) {
            return $this->onErrorResponse([
                'code' => ResponseError::ERROR_400,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Export VFD receipts based on filters
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $data = $request->validate([
                'type' => 'nullable|in:' . implode(',', [VfdReceipt::TYPE_DELIVERY, VfdReceipt::TYPE_SUBSCRIPTION]),
                'status' => 'nullable|in:' . implode(',', [VfdReceipt::STATUS_PENDING, VfdReceipt::STATUS_GENERATED, VfdReceipt::STATUS_FAILED]),
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
            ]);

            $query = VfdReceipt::query()
                ->when($data['type'] ?? null, fn($q) => $q->where('receipt_type', $data['type']))
                ->when($data['status'] ?? null, fn($q) => $q->where('status', $data['status']))
                ->when($data['date_from'] ?? null, fn($q) => $q->whereDate('created_at', '>=', $data['date_from']))
                ->when($data['date_to'] ?? null, fn($q) => $q->whereDate('created_at', '<=', $data['date_to']))
                ->latest();

            $receipts = $query->get();

            // Transform receipts for export
            $exportData = $receipts->map(function ($receipt) {
                return [
                    'receipt_number' => $receipt->receipt_number,
                    'type' => $receipt->receipt_type,
                    'amount' => $receipt->amount,
                    'status' => $receipt->status,
                    'customer_name' => $receipt->customer_name,
                    'customer_phone' => $receipt->customer_phone,
                    'customer_email' => $receipt->customer_email,
                    'payment_method' => $receipt->payment_method,
                    'created_at' => $receipt->created_at->format('Y-m-d H:i:s'),
                    'vfd_response' => $receipt->vfd_response,
                    'error_message' => $receipt->error_message,
                ];
            });

            return $this->successResponse(
                __('web.export_was_successful'),
                $exportData
            );
        } catch (\Exception $e) {
            return $this->onErrorResponse([
                'code' => ResponseError::ERROR_400,
                'message' => $e->getMessage()
            ]);
        }
    }
} 