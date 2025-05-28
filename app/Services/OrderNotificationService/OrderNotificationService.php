<?php

namespace App\Services;

use App\Models\Order;
use App\Mail\OrderNotificationMail;
use Illuminate\Support\Facades\Mail;
use App\Models\SmsGateway;

class OrderNotificationService
{
    public function __construct()
    {
        
    }

    /**
     * Send notifications via mail and SMS when an order is created or updated.
     *
     * @param Order $order
     * @param string $eventType
     */
    public function sendOrderNotification(Order $order, string $eventType)
    {
        $message = $this->buildMessage($order, $eventType);

        // Send email
        $this->sendEmail($order->user?->email, $message);

        // Send SMS
        $this->sendSMS($order->user?->phone, $message);
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
     */
    protected function sendEmail(string $toEmail, string $message)
    {
        try {
            Mail::to($toEmail)->send(new OrderNotificationMail($this->order, $message));
        } catch (\Exception $e) {
            \Log::error('Email sending failed: ' . $e->getMessage());
        }
    }

    /**
     * Send an SMS notification.
     *
     * @param string $phoneNumber
     * @param string $message
     */
    protected function sendSMS(string $phoneNumber, string $message)
    {
        try {
            // Get active SMS gateway setting
            $gateway = SmsGateway::where('active', 1)->first();
            
            if (!$gateway) {
                throw new \Exception('No active SMS gateway configured');
            }

            switch ($gateway->type) {
                case 'mobishastra':
                    $result = (new MobishastraService)->sendSMS($phoneNumber, $message);
                    break;
                // Add other SMS gateways here
                default:
                    throw new \Exception('Unsupported SMS gateway');
            }

            \Log::info('SMS sent successfully', [
                'phone' => $phoneNumber,
                'message' => $message,
                'response' => $result
            ]);

            return $result;

        } catch (\Exception $e) {
            \Log::error('SMS sending failed: ' . $e->getMessage());
            return false;
        }
    }
}
