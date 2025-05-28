<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Services\Notification\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderStatusNotification implements ShouldQueue
{
    use InteractsWithQueue;

    protected $notificationService;

    /**
     * Create the event listener.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the event.
     *
     * @param  OrderStatusUpdated  $event
     * @return void
     */
    public function handle(OrderStatusUpdated $event)
    {
        try {
            $this->notificationService->sendOrderStatusUpdate(
                $event->order,
                $event->newStatus,
                $event->reason
            );
        } catch (\Exception $e) {
            Log::error('Failed to send order status notification', [
                'order_id' => $event->order->id,
                'status' => $event->newStatus,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Retry the job later (Laravel will handle the retry logic)
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  OrderStatusUpdated  $event
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(OrderStatusUpdated $event, $exception)
    {
        // Log the final failure after all retries
        Log::critical('Failed to send order status notification after retries', [
            'order_id' => $event->order->id,
            'status' => $event->newStatus,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
