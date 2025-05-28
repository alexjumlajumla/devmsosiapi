<?php

namespace App\Library\Selcom\Http\Controllers;

use App\Library\Selcom\Events\CheckoutWebhookReceived;
use App\Library\Selcom\Facades\Selcom;
use Illuminate\Routing\Controller;

class CheckoutCallbackController extends Controller
{
    public function __invoke()
    {
        Selcom::processCheckoutWebhook();

        CheckoutWebhookReceived::dispatch(request('order_id'));
    }
}