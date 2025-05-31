<?php

namespace App\Services\Order;

use App\Jobs\GenerateVfdReceipt;
use App\Models\Order;
use App\Models\VfdReceipt;
use App\Services\CoreService;
use App\Services\Notification\VfdReceiptNotificationService;
use App\Services\VfdService\VfdArchiveService;
use App\Services\VfdService\VfdService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VfdReceiptService
{
    protected $vfdService;
    protected $notificationService;
    protected $archiveService;

    public function __construct(
        VfdService $vfdService,
        VfdReceiptNotificationService $notificationService,
        VfdArchiveService $archiveService = null
    ) {
        $this->vfdService = $vfdService;
        $this->notificationService = $notificationService;
        $this->archiveService = $archiveService;
    }

    /**
     * Generate a VFD receipt for an order
     *
     * @param Order $order
     * @param string $paymentMethod
     * @return array
     */
    public function generateForOrder(Order $order, string $paymentMethod): array
    {
        try {
            // Check if receipt already exists for this order
            $existingReceipt = VfdReceipt::where('model_id', $order->id)
                ->where('model_type', Order::class)
                ->where('receipt_type', VfdReceipt::TYPE_DELIVERY)
                ->first();

            if ($existingReceipt) {
                return [
                    'status' => true,
                    'message' => 'Receipt already generated',
                    'data' => $existingReceipt
                ];
            }

            // Prepare receipt data
            $receiptData = [
                'type' => VfdReceipt::TYPE_DELIVERY,
                'model_id' => $order->id,
                'model_type' => Order::class,
                'amount' => $order->delivery_fee,
                'payment_method' => $paymentMethod,
                'customer_name' => $order->username ?? 'Customer',
                'customer_phone' => $order->phone,
                'customer_email' => $order->email
            ];

            // Dispatch job to generate receipt asynchronously
            GenerateVfdReceipt::dispatch($receiptData);

            // Send notification that receipt is being processed
            if ($order->user) {
                // You might want to send a push notification here
                // $this->sendPushNotification($order->user, 'Your receipt is being generated', 'We\'re preparing your fiscal receipt. You\'ll receive it shortly.');
            }

            return [
                'status' => true,
                'message' => 'Receipt generation queued',
                'data' => $receiptData
            ];

        } catch (\Exception $e) {
            Log::error('Error generating VFD receipt for order: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => false,
                'message' => 'Failed to generate receipt: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get receipt for an order
     *
     * @param Order $order
     * @return VfdReceipt|null
     */
    public function getForOrder(Order $order): ?VfdReceipt
    {
        return VfdReceipt::where('model_id', $order->id)
            ->where('model_type', Order::class)
            ->where('receipt_type', VfdReceipt::TYPE_DELIVERY)
            ->first();
    }

    /**
     * Check if an order has a receipt
     *
     * @param Order $order
     * @return bool
     */
    public function hasReceipt(Order $order): bool
    {
        return VfdReceipt::where('model_id', $order->id)
            ->where('model_type', Order::class)
            ->where('receipt_type', VfdReceipt::TYPE_DELIVERY)
            ->exists();
    }

    /**
     * Get receipt URL for an order
     *
     * @param mixed $order
     * @return string|null
     */
    public function getReceiptUrl($order): ?string
    {
        $receipt = $this->getForOrder($order);
        return $receipt ? $receipt->receipt_url : null;
    }

    /**
     * Send receipt via SMS
     *
     * @param VfdReceipt $receipt
     * @return bool
     */
    public function sendReceiptSms(VfdReceipt $receipt): bool
    {
        return $this->notificationService->sendReceiptSms($receipt);
    }

    /**
     * Get the notification service instance
     *
     * @return VfdReceiptNotificationService
     */
    public function getNotificationService(): VfdReceiptNotificationService
    {
        return $this->notificationService;
    }
    
    /**
     * Get the archive service instance
     * 
     * @return VfdArchiveService|null
     */
    public function getArchiveService(): ?VfdArchiveService
    {
        return $this->archiveService;
    }
    
    /**
     * Sync a receipt to the archive
     * 
     * @param VfdReceipt $receipt
     * @return array
     */
    public function syncToArchive(VfdReceipt $receipt): array
    {
        if (!$this->archiveService) {
            return [
                'success' => false,
                'message' => 'Archive service not available',
            ];
        }
        
        return $this->archiveService->syncReceipt($receipt);
    }
    
    /**
     * Test the archive service connection
     * 
     * @return array
     */
    public function testArchiveConnection(): array
    {
        if (!$this->archiveService) {
            return [
                'success' => false,
                'message' => 'Archive service not available',
            ];
        }
        
        return $this->archiveService->testConnection();
    }
}
