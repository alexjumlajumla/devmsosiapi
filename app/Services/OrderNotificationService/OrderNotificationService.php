<?php

declare(strict_types=1);

namespace App\Services;

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
    protected function sendPushNotification(Order $order, string $eventType): void
    {
        try {
            if (!$order->user) {
                Log::warning('Cannot send push notification: No user associated with order', [
                    'order_id' => $order->id,
                ]);
                return;
            }

            $title = 'Order Update';
            $message = $this->buildPushMessage($order, $eventType);
            
            // Get user's FCM tokens using the getFcmTokens method
            $tokens = $order->user->getFcmTokens();
            
            if (empty($tokens)) {
                Log::warning('No FCM tokens found for user', [
                    'user_id' => $order->user->id,
                    'order_id' => $order->id,
                    'user_has_tokens' => !empty($order->user->firebase_token),
                ]);
                return;
            }
            
            Log::debug('FCM tokens found for order notification', [
                'user_id' => $order->user->id,
                'order_id' => $order->id,
                'token_count' => count($tokens),
                'token_sample' => !empty($tokens) ? substr(
                    json_encode($tokens[0] ?? '', JSON_THROW_ON_ERROR), 
                    0, 
                    50
                ) . '...' : 'none',
            ]);
            
            // Prepare notification data
            $data = [
                'id' => (string) $order->id,
                'order_id' => (string) $order->id,
                'status' => $order->status,
                'type' => 'order_update',
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                'notification_created_at' => now()->toDateTimeString(),
                'sound' => 'default',
                'channelId' => 'high_importance_channel',
                'priority' => 'high',
                'visibility' => 'public',
                'vibrate' => '300',
                'badge' => 1,
                'event_type' => $eventType,
            ];
            
            Log::debug('Sending push notification with data', [
                'user_id' => $order->user->id,
                'order_id' => $order->id,
                'title' => $title,
                'message' => $message,
                'data' => $data,
                'token_count' => count($tokens),
            ]);
            
            // Send notification using the Notification trait
            $result = $this->sendNotification(
                $tokens,           // tokens
                $message,          // message
                $title,            // title
                $data,             // data
                [$order->user->id],// userIds
                'order_update',    // type
                true               // isChat
            );
            
            Log::debug('Push notification send result', [
                'order_id' => $order->id,
                'result' => $result,
                'success' => $result['status'] === 'success',
                'message' => $result['message'] ?? 'No message',
                'tokens_sent' => $result['sent'] ?? 0,
                'tokens_failed' => $result['failed'] ?? 0,
                'errors' => $result['errors'] ?? [],
            ]);
            
            Log::info('Push notification sent for order', [
                'order_id' => $order->id,
                'user_id' => $order->user->id,
                'event_type' => $eventType,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send push notification', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
