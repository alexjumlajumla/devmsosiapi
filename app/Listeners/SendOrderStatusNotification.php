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
        // Log the start of the handler
        Log::info('SendOrderStatusNotification: Starting to handle order status update', [
            'order_id' => $event->order->id,
            'old_status' => $event->oldStatus,
            'new_status' => $event->newStatus,
            'reason' => $event->reason,
            'queue' => $this->queue,
            'attempts' => $this->attempts(),
            'job_id' => $this->job ? $this->job->getJobId() : 'sync',
            'connection' => $this->connection ?? config('queue.default')
        ]);

        try {
            // Log before calling the notification service
            Log::debug('SendOrderStatusNotification: Calling notification service', [
                'order_id' => $event->order->id,
                'service_class' => get_class($this->notificationService)
            ]);
            
            // Send the notification
            $result = $this->notificationService->sendOrderStatusUpdate(
                $event->order,
                $event->newStatus,
                $event->reason
            );
            
            // Log the result
            if ($result) {
                Log::info('SendOrderStatusNotification: Notification sent successfully', [
                    'order_id' => $event->order->id,
                    'status' => $event->newStatus,
                    'notification_id' => $result->id ?? 'unknown',
                    'notification_type' => get_class($result)
                ]);
            } else {
                Log::warning('SendOrderStatusNotification: Notification service returned null', [
                    'order_id' => $event->order->id,
                    'status' => $event->newStatus
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $errorContext = [
                'order_id' => $event->order->id,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                'trace' => $e->getTraceAsString()
            ];
            
            Log::error('SendOrderStatusNotification: Failed to send notification', $errorContext);
            
            // Re-throw to allow job retries if configured
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
