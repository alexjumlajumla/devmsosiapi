<?php

declare(strict_types=1);

namespace App\Services\OrderNotificationService;

use App\Mail\OrderNotificationMail;
use App\Models\Order;
use App\Models\SmsGateway;
use App\Traits\Notification;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderNotificationService
{
    use Notification;

    public function __construct()
    {
        //
    }

    /**
     * Send notifications via mail and SMS when an order is created or updated.
     *
     * @param Order $order
     * @param string $eventType
     * @return void
     */
    public function sendOrderNotification(Order $order, string $eventType): void
    {
        $message = $this->buildMessage($order, $eventType);

        // Send email
        if ($order->user?->email) {
            $this->sendEmail($order->user->email, $message);
        }

        // Send SMS
        if ($order->user?->phone) {
            $this->sendSMS($order->user->phone, $message);
        }
        
        // Send push notification
        $this->sendPushNotification($order, $eventType);
    }

    /**
     * Build notification message based on order event type.
     *
     * @param Order $order
     * @param string $eventType
     * @return string
     */
    protected function buildMessage(Order $order, string $eventType): string 
    {
        switch ($eventType) {
            case 'payment_accepted':
                return "Your payment for order #{$order->id} has been confirmed. Thank you for your purchase!";
            case 'created':
                return "Your order #{$order->id} has been successfully placed!";
            default:
                return "Your order #{$order->id} status has been updated to: {$order->status}";
        }
    }

    /**
     * Send an email notification.
     *
     * @param string $toEmail
     * @param string $message
     * @return void
     */
    protected function sendEmail(string $toEmail, string $message): void
    {
        try {
            // Pass null as the order since we don't have the order object in this context
            // The message already contains the order details
            Mail::to($toEmail)->send(new OrderNotificationMail(null, $message));
        } catch (Exception $e) {
            Log::error('Email sending failed: ' . $e->getMessage());
        }
    }

    /**
     * Send an SMS notification.
     *
     * @param string $phoneNumber
     * @param string $message
     * @return bool|mixed
     */
    protected function sendSMS(string $phoneNumber, string $message): mixed
    {
        try {
            // Get active SMS gateway setting
            $gateway = SmsGateway::where('active', 1)->first();
            
            if (!$gateway) {
                throw new Exception('No active SMS gateway configured');
            }

            switch ($gateway->type) {
                case 'mobishastra':
                    $result = (new MobishastraService())->sendSMS($phoneNumber, $message);
                    break;
                // Add other SMS gateways here
                default:
                    throw new Exception('Unsupported SMS gateway');
            }

            Log::info('SMS sent successfully', [
                'phone' => $phoneNumber,
                'message' => $message,
                'response' => $result,
            ]);

            return $result;
        } catch (Exception $e) {
            Log::error('SMS sending failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send push notification to customer.
     *
     * @param Order $order
     * @param string $eventType
     * @return void
     * @throws \Exception
     */
    /**
     * Send push notifications to all relevant parties (customer, admin, seller)
     */
    protected function sendPushNotification(Order $order, string $eventType): void
    {
        try {
            $title = 'Order Update';
            $message = $this->buildPushMessage($order, $eventType);
            
            // 1. Send to customer (order owner)
            if ($order->user) {
                $this->sendToUser($order->user, $title, $message, [
                    'id' => (string) $order->id,
                    'order_id' => (string) $order->id,
                    'status' => $order->status,
                    'type' => 'order_update',
                    'event_type' => $eventType,
                ]);
            }
            
            // 2. Send to shop owner/seller
            if ($order->shop && $order->shop->user) {
                $this->sendToUser($order->shop->user, "New Order #{$order->id}", 
                    "New order #{$order->id} has been placed in your shop", [
                        'id' => (string) $order->id,
                        'order_id' => (string) $order->id,
                        'status' => $order->status,
                        'type' => 'new_order',
                        'event_type' => 'new_order',
                    ]);
            }
            
            // 3. Send to admins
            $admins = \App\Models\User::role('admin')->get();
            foreach ($admins as $admin) {
                $this->sendToUser($admin, "New Order #{$order->id}", 
                    "New order #{$order->id} has been placed", [
                        'id' => (string) $order->id,
                        'order_id' => (string) $order->id,
                        'status' => $order->status,
                        'type' => 'new_order',
                        'event_type' => 'new_order',
                    ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Error in sendPushNotification: ' . $e->getMessage(), [
                'order_id' => $order->id,
                'exception' => $e
            ]);
        }
    }
    
    /**
     * Send notification to a specific user with proper token handling
     */
    /**
     * Send notification to a specific user with proper token handling
     * 
     * @param \App\Models\User $user
     * @param string $title
     * @param string $message
     * @param array $data
     * @return bool
     */
    /**
     * Send notification to a specific user with proper token handling
     * 
     * @param \App\Models\User $user
     * @param string $title
     * @param string $message
     * @param array $data
     * @return bool
     */
    protected function sendToUser($user, $title, $message, $data = [])
    {
        try {
            if (!$user) {
                Log::warning('Cannot send notification: User is null');
                return false;
            }

            $isTestEnv = app()->environment('local', 'staging', 'development');
            
            // Get user's FCM tokens
            $tokens = $user->getFcmTokens();
            
            Log::debug('FCM tokens for user', [
                'user_id' => $user->id,
                'tokens_count' => count($tokens),
                'environment' => app()->environment(),
                'is_test_env' => $isTestEnv
            ]);
            
            // Handle test environment
            if ($isTestEnv) {
                // In test environment, we can use test tokens
                if (empty($tokens)) {
                    $testToken = 'test_fcm_token_' . ($user->hasRole('admin') ? 'admin_' : 'user_') . $user->id;
                    Log::info('Using test FCM token in test environment', [
                        'user_id' => $user->id,
                        'test_token' => $testToken
                    ]);
                    $tokens = [$testToken];
                }
            } else {
                // In production, filter out test tokens
                $tokens = array_filter($tokens, function($token) {
                    return !str_starts_with($token, 'test_fcm_token_');
                });
                
                if (empty($tokens)) {
                    Log::warning('No valid FCM tokens found for user in production', [
                        'user_id' => $user->id,
                        'environment' => app()->environment()
                    ]);
                    return false;
                }
            }
            
            // Prepare notification data
            $notificationData = array_merge([
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'notification_created_at' => now()->toDateTimeString(),
                'sound' => 'default',
                'channelId' => 'high_importance_channel',
                'priority' => 'high',
                'visibility' => 'public',
                'vibrate' => '300',
                'badge' => 1,
            ], $data);
            
            Log::debug('Sending push notification', [
                'user_id' => $user->id,
                'title' => $title,
                'message' => $message,
                'data' => $notificationData,
                'token_count' => count($tokens),
                'token_sample' => !empty($tokens) ? substr(implode(',', $tokens), 0, 50) . '...' : 'none',
            ]);
            
            // Send notification using the notification service
            try {
                $this->notification->sendPushNotification(
                    $tokens,
                    $title,
                    $message,
                    $notificationData
                );
                
                Log::info('Push notification sent successfully', [
                    'user_id' => $user->id,
                    'title' => $title,
                    'message' => $message
                ]);
                
                return true;
            } catch (\Exception $e) {
                Log::error('Failed to send push notification', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return false;
            }
            
            
        } catch (\Exception $e) {
            Log::error('Error in sendToUser: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'exception' => $e
            ]);
        }
    }
    
    /**
     * Build push notification message based on order event type.
     *
     * @param Order $order
     * @param string $eventType
     * @return string
     */
    protected function buildPushMessage(Order $order, string $eventType): string
    {
        switch ($eventType) {
            case 'created':
                return "Your order #{$order->id} has been placed successfully!";
            case 'payment_accepted':
                return "Payment for order #{$order->id} has been confirmed. We're preparing your order!";
            case 'on_the_way':
                return "Order #{$order->id} is on its way to you!";
            case 'delivered':
                return "Your order #{$order->id} has been delivered. Enjoy!";
            case 'cancelled':
                return "Your order #{$order->id} has been cancelled.";
            default:
                return "Update for your order #{$order->id}: " . ucfirst(str_replace('_', ' ', (string) $order->status));
        }
    }
}
