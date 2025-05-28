<?php

namespace App\Services\Notification;

use App\Models\PushNotification;
use App\Models\User;
use App\Traits\Notification as NotificationTrait;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    use NotificationTrait;

    protected $firebaseTokenService;

    public function __construct(FirebaseTokenService $firebaseTokenService)
    {
        $this->firebaseTokenService = $firebaseTokenService;
    }

    /**
     * Send notification to a single user
     */
    public function sendToUser(User $user, string $title, string $message, string $type = 'info', array $data = []): ?PushNotification
    {
        DB::beginTransaction();

        try {
            // Check if user has FCM tokens
            $tokens = $this->firebaseTokenService->getUserTokens($user->id);
            if (empty($tokens)) {
                $this->logNoTokens($user);
                return null;
            }

            // Create notification record
            $notification = PushNotification::create([
                'type' => $type,
                'title' => $title,
                'body' => $message,
                'data' => $data,
                'user_id' => $user->id,
                'status' => PushNotification::STATUS_PENDING,
            ]);

            if (!$notification) {
                throw new \RuntimeException('Failed to create notification record');
            }

            try {
                // Prepare notification data
                $notificationData = array_merge($data, [
                    'title' => $title,
                    'body' => $message,
                    'type' => $type,
                    'id' => (string)$notification->id,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    'sound' => 'default',
                    'priority' => 'high',
                ]);

                // Send notification
                $this->sendNotification(
                    $tokens,
                    $notificationData,
                    [$user->id],
                    $title,
                    $data,
                    $title // firebaseTitle
                );

                // Update notification status to sent
                $notification->update([
                    'status' => PushNotification::STATUS_SENT,
                    'sent_at' => now(),
                ]);


                // Log success
                Log::info('Notification sent successfully', [
                    'notification_id' => $notification->id,
                    'user_id' => $user->id,
                    'type' => $type
                ]);

                DB::commit();
                return $notification;

            } catch (\Throwable $e) {
                Log::error('Failed to send FCM notification', [
                    'user_id' => $user->id,
                    'notification_id' => $notification->id ?? null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                if ($notification) {
                    $notification->update([
                        'status' => PushNotification::STATUS_FAILED,
                        'error_message' => substr($e->getMessage(), 0, 255), // Ensure it fits in the column
                    ]);
                }
                
                throw $e;
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('NotificationService error', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Mark notification as delivered
     */
    public function markAsDelivered(int $notificationId, ?int $userId = null): bool
    {
        $query = PushNotification::where('id', $notificationId);
        
        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->update([
            'status' => PushNotification::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]) > 0;
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, ?int $userId = null): bool
    {
        $query = PushNotification::where('id', $notificationId);
        
        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->update([
            'status' => PushNotification::STATUS_READ,
            'read_at' => now(),
        ]) > 0;
    }

    /**
     * Get user's unread notifications count
     */
    public function getUnreadCount(int $userId): int
    {
        return PushNotification::where('user_id', $userId)
            ->where('status', '!=', PushNotification::STATUS_READ)
            ->count();
    }

    /**
     * Send order status update notification
     * 
     * @param \App\Models\Order $order
     * @param string $status The new status (new, accepted, ready, on_the_way, delivered, cancelled)
     * @param string|null $reason Optional reason for status change (e.g., cancellation reason)
     * @return PushNotification|null
     */
    public function sendOrderStatusUpdate($order, string $status, ?string $reason = null): ?PushNotification
    {
        $user = $order->user;
        
        if (!$user) {
            Log::warning('Cannot send order status update: order has no user', [
                'order_id' => $order->id
            ]);
            return null;
        }

        $statusTitles = [
            'new' => 'New Order #' . $order->id,
            'accepted' => 'Order #' . $order->id . ' Accepted',
            'ready' => 'Order #' . $order->id . ' is Ready',
            'on_the_way' => 'Order #' . $order->id . ' is on the way',
            'delivered' => 'Order #' . $order->id . ' has been Delivered',
            'cancelled' => 'Order #' . $order->id . ' has been Cancelled',
        ];

        $statusMessages = [
            'new' => 'Your order has been received and is being processed.',
            'accepted' => 'Restaurant has accepted your order and started preparing it.',
            'ready' => 'Your order is ready for ' . ($order->delivery_type === 'delivery' ? 'delivery' : 'pickup') . '.',
            'on_the_way' => 'Your order is on the way to your location.',
            'delivered' => 'Your order has been delivered. ' . ($order->delivery_rating_enabled ? 'Please rate your experience!' : 'Enjoy your meal!'),
            'cancelled' => $reason ? "Your order has been cancelled. Reason: $reason" 
                                : 'Your order has been cancelled. Contact support for details.'
        ];

        $title = $statusTitles[$status] ?? 'Order #' . $order->id . ' Update';
        $message = $statusMessages[$status] ?? 'Your order status has been updated to: ' . ucfirst(str_replace('_', ' ', $status));

        $data = [
            'order_id' => $order->id,
            'status' => $status,
            'type' => 'order_status_update',
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            'sound' => 'default',
            'delivery_type' => $order->delivery_type ?? 'delivery',
        ];

        // Add additional data for specific statuses
        if ($status === 'cancelled' && $reason) {
            $data['cancellation_reason'] = $reason;
        }

        if ($status === 'delivered' && $order->delivery_rating_enabled) {
            $data['rating_enabled'] = true;
        }

        return $this->sendToUser(
            $user,
            $title,
            $message,
            'order_status_update',
            $data
        );
    }
}
