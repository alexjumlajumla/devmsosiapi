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
        Log::info('SendOrderStatusNotification: Handling order status update', [
            'order_id' => $event->order->id,
            'old_status' => $event->oldStatus,
            'new_status' => $event->newStatus,
            'reason' => $event->reason,
            'queue' => $this->queue,
            'attempts' => $this->attempts()
        ]);

        try {
            $this->notificationService->sendOrderStatusUpdate(
                $event->order,
                $event->newStatus,
                $event->reason
            );
            
            Log::info('SendOrderStatusNotification: Successfully processed notification', [
                'order_id' => $event->order->id,
                'status' => $event->newStatus
            ]);
            
        } catch (\Exception $e) {
            Log::error('SendOrderStatusNotification: Failed to send order status notification', [
                'order_id' => $event->order->id,
                'status' => $event->newStatus,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'attempts' => $this->attempts()
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
