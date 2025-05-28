<?php

namespace App\Services\OrderService;

use App\Models\Order;
use App\Models\Settings;
use App\Services\SMSGatewayService\MobishastraService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class OrderSmsService
{
    protected static function sendSms($phone, $message)
    {
        //return $phone;
        return (new MobishastraService)->sendOtp($phone, $message);
    }

    public static function newOrder(Order $order)
    {
        $message = "Dear {$order->user->firstname}, your order #{$order->id} has been placed successfully. We will notify you once it is processed.";
        
        return self::sendSms($order->user->phone, $message);
    }

    public static function orderProcessing(Order $order)
    {
        $message = "Your order #{$order->id} is now being processed. We will update you once it is ready for shipping.";
        return self::sendSms($order->user->phone, $message);
    }

    public static function orderShipped(Order $order)
    {
        $message = "Good news! Your order #{$order->id} has been shipped. Track your order for more details.";
        return self::sendSms($order->user->phone, $message);
    }

    public static function orderOutForDelivery(Order $order)
    {
        $message = "Your order #{$order->id} is out for delivery. Please ensure someone is available to receive it.";
        return self::sendSms($order->user->phone, $message);
    }

    public static function orderDelivered(Order $order)
    {
        $message = "Your order #{$order->id} has been delivered successfully. Thank you for shopping with us!";
        return self::sendSms($order->user->phone, $message);
    }

    public static function orderCancelled(Order $order)
    {
        $message = "We regret to inform you that your order #{$order->id} has been cancelled. Please contact support for further assistance.";
        return self::sendSms($order->user->phone, $message);
    }

    public static function orderRefunded(Order $order)
    {
        $message = "Your refund for order #{$order->id} has been processed. The amount will be credited to your account shortly.";
        return self::sendSms($order->user->phone, $message);
    }
}
