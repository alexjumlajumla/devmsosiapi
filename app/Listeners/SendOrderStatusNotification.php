<?php

namespace App\Listeners;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendOrderStatusNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The name of the queue the job should be sent to.
     *
     * @var string
     */
    public $queue = 'notifications';

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 60;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  OrderStatusUpdated  $event
     * @return void
     */
    public function handle(OrderStatusUpdated $event)
    {
        $order = $event->order;
        $newStatus = $event->newStatus;
        $oldStatus = $event->oldStatus ?? null;
        $reason = $event->reason;

        // Log the start of the handler
        $logContext = [
            'order_id' => $order->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
            'queue' => $this->queue,
            'attempts' => $this->attempts(),
            'job_id' => $this->job ? $this->job->getJobId() : 'sync',
            'connection' => $this->connection ?? config('queue.default'),
            'user_id' => $order->user_id,
            'shop_id' => $order->shop_id,
        ];

        Log::info('Processing order status update notification', $logContext);

        try {
            // Get the user who placed the order
            $user = $order->user;
            
            if (!$user) {
                Log::warning('User not found for order', ['order_id' => $order->id]);
                return;
            }

            // Send notification to the customer
            $user->notify(new OrderStatusNotification($order, $newStatus));
            Log::info('Sent order status notification to customer', [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'status' => $newStatus,
            ]);

            // If this is a new order, notify the shop owner and admins
            if ($newStatus === Order::STATUS_NEW) {
                $this->notifyShopAndAdmins($order, $newStatus);
            }

            // If this is a delivery status update, notify the delivery person if applicable
            if (in_array($newStatus, [
                Order::STATUS_ON_A_WAY, 
                Order::STATUS_READY, 
                Order::STATUS_DELIVERED
            ])) {
                $this->notifyDeliveryPerson($order, $newStatus);
            }

        } catch (\Exception $e) {
            $errorContext = [
                'order_id' => $order->id,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
                'trace' => $e->getTraceAsString()
            ];
            
            Log::error('Failed to process order status notification', $errorContext);
            
            // Re-throw the exception to allow for retries
            throw $e;
        }
    }

    /**
     * Notify shop owner and admins about the order
     *
     * @param Order $order
     * @param string $status
     * @return void
     */
    protected function notifyShopAndAdmins(Order $order, string $status): void
    {
        try {
            $shop = $order->shop;
            
            if ($shop && $shop->user) {
                // Notify shop owner
                $shop->user->notify(new OrderStatusNotification(
                    $order, 
                    $status,
                    "New Order #{$order->id}",
                    "You have a new order #{$order->id} with total {$order->total_price}"
                ));
                
                Log::info('Notified shop owner about new order', [
                    'order_id' => $order->id,
                    'shop_id' => $shop->id,
                    'shop_owner_id' => $shop->user_id,
                ]);
            }
            
            // Notify admins
            $admins = User::role('admin')->get();
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new OrderStatusNotification(
                    $order,
                    $status,
                    "New Order #{$order->id}",
                    "New order #{$order->id} received with total {$order->total_price}"
                ));
                
                Log::info('Notified admins about new order', [
                    'order_id' => $order->id,
                    'admin_count' => $admins->count(),
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to notify shop owner or admins', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
    
    /**
     * Notify delivery person about order status update
     * 
     * @param Order $order
     * @param string $status
     * @return void
     */
    protected function notifyDeliveryPerson(Order $order, string $status): void
    {
        try {
            $deliveryMan = $order->deliveryMan;
            
            if ($deliveryMan) {
                $title = match($status) {
                    Order::STATUS_ON_A_WAY => "Order #{$order->id} is on the way",
                    Order::STATUS_READY => "Order #{$order->id} is ready for pickup",
                    Order::STATUS_DELIVERED => "Order #{$order->id} has been delivered",
                    default => "Order #{$order->id} status updated"
                };
                
                $body = match($status) {
                    Order::STATUS_ON_A_WAY => "You are on the way to deliver order #{$order->id}",
                    Order::STATUS_READY => "Order #{$order->id} is ready for pickup at the shop",
                    Order::STATUS_DELIVERED => "You have successfully delivered order #{$order->id}",
                    default => "Status of order #{$order->id} has been updated"
                };
                
                $deliveryMan->notify(new OrderStatusNotification($order, $status, $title, $body));
                
                Log::info('Notified delivery person about order status', [
                    'order_id' => $order->id,
                    'delivery_man_id' => $deliveryMan->id,
                    'status' => $status,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to notify delivery person', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
    
    /**
     * Handle a job failure.
     *
     * @param  OrderStatusUpdated  $event
     * @param  \Throwable  $exception
     * @return void
     */
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
            'error' => [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ],
            'trace' => $exception->getTraceAsString(),
            'attempts' => $this->attempts(),
            'max_attempts' => $this->tries
        ]);
    }
}
