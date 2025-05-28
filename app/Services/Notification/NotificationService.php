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
    /**
     * Send a notification to a single user
     * 
     * @param User $user The user to send the notification to
     * @param string $title The notification title
     * @param string $message The notification message
     * @param string $type The notification type (default: 'info')
     * @param array $data Additional data to include with the notification
     * @return PushNotification|null The created notification or null if failed
     */
    public function sendToUser(User $user, string $title, string $message, string $type = 'info', array $data = []): ?PushNotification
    {
        $logContext = [
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'has_data' => !empty($data)
        ];
        
        Log::info('NotificationService: Starting to send notification to user', $logContext);
        
        DB::beginTransaction();

        try {
            // Check if user has FCM tokens
            Log::debug('NotificationService: Getting FCM tokens for user', ['user_id' => $user->id]);
            
            $tokens = $this->firebaseTokenService->getUserTokens($user->id);
            
            Log::debug('NotificationService: Retrieved FCM tokens', [
                'user_id' => $user->id,
                'token_count' => is_array($tokens) ? count($tokens) : 0,
                'tokens' => $tokens ? array_map(function($t) { 
                    return substr($t, 0, 10) . '...' . substr($t, -5); 
                }, $tokens) : []
            ]);
            
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
                    [
                        'title' => $title,
                        'body' => $message,
                    ],
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
    /**
     * Get count of unread notifications for a user
     * 
     * @param int $userId The user ID
     * @return int Number of unread notifications
     */
    public function getUnreadCount(int $userId): int
    {
        return PushNotification::where('user_id', $userId)
            ->where('status', '!=', PushNotification::STATUS_READ)
            ->count();
    }
    
    /**
     * Log when no FCM tokens are found for a user
     * 
     * @param User $user The user with no FCM tokens
     * @return void
     */
    protected function logNoTokens(User $user): void
    {
        $logContext = [
            'user_id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
            'has_firebase_token' => !empty($user->firebase_token),
            'firebase_token_type' => $user->firebase_token ? gettype($user->firebase_token) : null,
        ];
        
        if (is_array($user->firebase_token)) {
            $logContext['token_count'] = count($user->firebase_token);
            $logContext['token_sample'] = !empty($user->firebase_token) 
                ? substr(json_encode($user->firebase_token[0] ?? ''), 0, 50) . '...' 
                : null;
        } elseif (is_string($user->firebase_token)) {
            $logContext['token_length'] = strlen($user->firebase_token);
            $logContext['token_prefix'] = substr($user->firebase_token, 0, 10) . '...';
        }
        
        Log::warning('No valid FCM tokens found for user', $logContext);
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
        Log::info('NotificationService: Starting order status update', [
            'order_id' => $order->id,
            'status' => $status,
            'reason' => $reason,
            'order_class' => get_class($order),
            'order_data' => [
                'user_id' => $order->user_id,
                'status' => $order->status,
                'delivery_type' => $order->delivery_type ?? 'unknown',
            ]
        ]);
        
        // Load user relationship if not already loaded
        if (!$order->relationLoaded('user')) {
            $order->load('user');
        }
        
        $user = $order->user;
        
        if (!$user) {
            $errorMessage = 'Cannot send order status update: order has no user';
            Log::error($errorMessage, [
                'order_id' => $order->id,
                'status' => $status,
                'order_user_id' => $order->user_id
            ]);
            return null;
        }
        
        Log::debug('NotificationService: Found user for order', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'user_phone' => $user->phone
        ]);

        $statusTitles = [
            'new' => 'New Order #' . $order->id,
            'accepted' => 'Order #' . $order->id . ' Accepted',
            'processing' => 'Order #' . $order->id . ' is Being Prepared',
            'cooking' => 'Order #' . $order->id . ' is Being Cooked',
            'ready' => 'Order #' . $order->id . ' is Ready',
            'shipped' => 'Order #' . $order->id . ' Has Shipped',
            'on_a_way' => 'Order #' . $order->id . ' is on the way',
            'delivered' => 'Order #' . $order->id . ' has been Delivered',
            'canceled' => 'Order #' . $order->id . ' has been Canceled',
        ];

        $statusMessages = [
            'new' => 'Your order has been received and is being processed.',
            'accepted' => 'Restaurant has accepted your order and started preparing it.',
            'processing' => 'Your order is being prepared by our kitchen staff.',
            'cooking' => 'Your food is being cooked with care.',
            'ready' => 'Your order is ready for ' . ($order->delivery_type === 'delivery' ? 'delivery' : 'pickup') . '.',
            'shipped' => 'Your order has been shipped and is on its way to you.',
            'on_a_way' => 'Your order is on the way to your location.',
            'delivered' => 'Your order has been delivered. ' . ($order->delivery_rating_enabled ? 'Please rate your experience!' : 'Enjoy your meal!'),
            'canceled' => $reason ? "Your order has been canceled. Reason: $reason" 
                                : 'Your order has been canceled. Contact support for details.'
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

        Log::info('NotificationService: Sending notification to user', [
            'order_id' => $order->id,
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'status' => $status,
            'data' => $data
        ]);

        try {
            $notification = $this->sendToUser(
                $user,
                $title,
                $message,
                'order_' . $status,
                $data
            );

            if ($notification) {
                Log::info('NotificationService: Notification sent successfully', [
                    'notification_id' => $notification->id ?? 'unknown',
                    'order_id' => $order->id,
                    'status' => $status
                ]);
            } else {
                Log::error('NotificationService: Failed to send notification', [
                    'order_id' => $order->id,
                    'status' => $status,
                    'user_id' => $user->id
                ]);
            }

            return $notification;
        } catch (\Exception $e) {
            Log::error('NotificationService: Exception while sending notification', [
                'order_id' => $order->id,
                'status' => $status,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
