<?php

namespace App\Services\PaymentService;

use App\Models\Cart;
use App\Models\Order;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Payout;
use App\Models\Shop;
use App\Models\Subscription;
USE App\Library\Selcom\Selcom;
use Exception;
use Http;
use Illuminate\Database\Eloquent\Model;
use Str;
use Throwable;
use Log;


class SelcomService extends BaseService
{
    protected function getModelClass(): string
    {
        return Payout::class;
    }

    /**
     * @param array $data
     * @return PaymentProcess|Model
     * @throws Throwable
     */
    public function orderProcessTransaction(array $data): Model|PaymentProcess
    //public function orderProcessTransaction(array $data)
    {   
		
Log::info('Test log works! 333333333333333333333');



        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();
		
						Log::info('Selcom payment record', ['payment' => $payment?->id]);


        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

								Log::info('555555' . $payment);

        /**
         * Determine the primary model for which payment is being initiated.
         *  1. Parcel order  → ParcelOrder model
         *  2. Cart checkout → Cart model
         *  3. Wallet top-up → $order will be null (handled below)
         */
        /** @var Order|ParcelOrder|null $order */
        $order = data_get($data, 'parcel_id')
            ? ParcelOrder::find(data_get($data, 'parcel_id'))
            : Cart::find(data_get($data, 'cart_id'));

        [$key, $before] = $this->getPayload($data, $payload);
        $modelId     = data_get($before, 'model_id');
        $totalPrice  = data_get($before, 'total_price');
		
										Log::info('6666666' . $payment);


        $host   = request()->getSchemeAndHttpHost();

        // Transaction reference
        $trxRef = ($order?->id ?? $modelId) . '-' . time();

        // Validate required params for wallet top-up or other flow
        if (!$order && !data_get($data, 'wallet_id')) {
            throw new Exception('Invalid request parameters');
        }

        //return $trxRef;
        if ($order) {
            // Parcel | cart payment path
            $idParam = data_get($data, 'parcel_id') ? "parcel_id={$order->id}" : "cart_id={$order->id}";
        } else {
            // Wallet top-up path
            $idParam = "wallet_id=" . data_get($data, 'wallet_id');
        }

        $redirectUrl = "$host/selcom-result?status=success&trxRef=$trxRef&$idParam";
        $cancelUrl   = "$host/selcom-result?status=error&trxRef=$trxRef&$idParam";

        $authUser = auth('sanctum')->user();

        // Fix webhook URL format to properly handle payment callbacks
        $webhookUrl = "$host/api/v1/webhook/selcom/payment?trxRef=$trxRef";
        
        $api = new Selcom($payload, $redirectUrl, $cancelUrl, $webhookUrl);
        $response =  $api->cardCheckoutUrl([
            'name'  => $order?->username
                ?? ($authUser?->firstname . ' ' . $authUser?->lastname),
            'email' => $order?->user?->email ?? $authUser?->email,
            'phone' => $this->formatPhone($order?->phone ?? $authUser?->phone),
            'amount' => $totalPrice,
            'transaction_id' => $trxRef,
            'address' => 'Dar Es Salaam',
            'postcode' => '',
            'user_id' => auth('sanctum')->id(),
            'country_code' => $order?->user?->address?->country?->translation?->title,
            'state'        => $order?->user?->address?->region?->translation?->title,
            'city'         => $order?->user?->address?->city?->translation?->title,
            'billing_phone' => $this->formatPhone($order?->phone ?? $authUser?->phone),
            'currency' => data_get($payload, 'currency'),
            'items' => 1,
        ]);
        if ($response['result']=== 'FAIL') {
            throw new Exception(data_get($response, 'message'));
        }
        if(!isset($response['data'][0])){
            throw new Exception('Selcom URL not found');
        }

        $url = base64_decode(data_get($response['data'][0], 'payment_gateway_url'));
        \Log::info("Selcom url: $url");

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $modelId,
            'model_type' => data_get($before, 'model_type')
        ], [
            'id'    => $trxRef,
            'data'  => [
                'url'   	 => $url,
                'price' 	 => $totalPrice,
				'cart'		 => $data,
                'shop_id'    => data_get($data, 'shop_id'),
				'payment_id' => $payment?->id,
            ]
        ]);
    }

    /**
     * @param array $data
     * @param Shop $shop
     * @param $currency
     * @return Model|array|PaymentProcess
     * @throws Exception
     */
    public function subscriptionProcessTransaction(array $data, Shop $shop, $currency): Model|array|PaymentProcess
    {
        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();

        if (!$payment) {
            throw new Exception('Selcom payment method is not configured');
        }

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $host           = request()->getSchemeAndHttpHost();

        /** @var Subscription $subscription */
        $subscription   = Subscription::find(data_get($data, 'subscription_id'));
        
        [$key, $before] = $this->getPayload($data, $payload);
        $modelId 		= data_get($before, 'model_id');
        
        $trxRef = "$modelId-" . time();
        
        $redirectUrl  = "$host/subscription-selcom-success?subscription_id=$subscription->id&status=success&trxRef=$trxRef";

        $cancelUrl  = "$host/subscription-selcom-success?subscription_id=$subscription->id&status=error&trxRef=$trxRef";
        
        $webhookUrl = "$host/api/v1/webhook/selcom/payment&trxRef=$trxRef";
        
        $api = new Selcom($payload, $redirectUrl, $cancelUrl, $webhookUrl);
        $response =  $api->cardCheckoutUrl([
            'name' => "{$shop->seller?->firstname} {$shop->seller?->lastname}", 
            'email' => $shop->seller?->email,
            'phone' => $this->formatPhone($shop->seller?->phone),
            'amount' => $subscription->price,
            'transaction_id' => $trxRef,
            'address' => 'Dar Es Salaam',
            'postcode' => '',
            'user_id' => auth('sanctum')->id(),
            'country_code' => $shop->seller?->address?->country?->translation?->title,
            'state' => $shop->seller?->address?->region?->translation?->title,
            'city' => $shop->seller?->address?->city?->translation?->title,
            'billing_phone' => $this->formatPhone($shop->seller?->phone),
            'currency' => Str::lower(data_get($paymentPayload?->payload, 'currency', $currency)),
            'items' => 1,
        ]);
        if ($response['result']=== 'FAIL') {
            throw new Exception(data_get($response, 'message'));
        }
        if(!isset($response['data'][0])){
            throw new Exception('Selcom URL not found');
        }

        $url = base64_decode(data_get($response['data'][0], 'payment_gateway_url'));
        \Log::info("Selcom url: $url");

      
        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
			'model_id'   => $modelId,
			'model_type' => data_get($before, 'model_type'),
        ], [
            'id'    => $trxRef,
            'data'  => [
                'url'             => $url,
                'price'           => round($subscription->price, 2) * 100,
                'shop_id'         => $shop->id,
                'subscription_id' => $modelId,
				'payment_id' 	  => $payment?->id,
            ]
        ]);
    }

    public function formatPhone($phone){
        $phone = (substr($phone, 0, 1) == "+") ? str_replace("+", "", $phone) : $phone;
        $phone = (substr($phone, 0, 1) == "0") ? preg_replace("/^0/", "255", $phone) : $phone;
        $phone = (substr($phone, 0, 1) == "7") ? "255{$phone}" : $phone;

        return $phone;
    }

    public function resultTransaction($transID)
    { 
        $order = SelcomPayment::where('transid', $transID)->first();

        if (!$order) {
            return null;
        }

        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();
        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload = $paymentPayload?->payload;

        $api = new Selcom($payload);
        $response = $api->orderStatus($order->order_id);

        if ($response && $response['result'] == "SUCCESS" && $response['data'][0]['payment_status'] == "COMPLETED") {
            
            // Update order status
            $this->updateOrderStatus($order->order_id);

            return [
                'status' => data_get($response['data'][0], 'payment_status'),
                'token'  => data_get($response['data'][0], 'transid')
            ];
        }

        return null;
    }

    private function updateOrderStatus($orderId) 
    {
        $order = Order::find($orderId);
        
        if ($order) {
            $order->update(['status' => Order::STATUS_PAID]);
            
            // Send notifications
            $notificationService = new OrderNotificationService();
            $notificationService->sendOrderNotification($order, 'payment_accepted');
        }
    }
}
