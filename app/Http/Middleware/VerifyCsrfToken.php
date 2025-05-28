<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/*',
        'order-stripe-success',
        'order-paytabs-success',
        'order-stripe-success',
        'order-paypal-success',
        'order-razorpay-success',
        'order-paystack-success',
        'order-mercado-pago-success',
        'order-moya-sar-success',
        'parcel-order-stripe-success',
        'subscription-stripe-success',
    ];
}
