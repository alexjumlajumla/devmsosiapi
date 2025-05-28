<?php

namespace App\Services\PaymentService;

use App\Models\Cart;
use App\Models\Order;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Payout;
use App\Models\SelcomPayment;
use App\Models\Shop;
use App\Models\User;
use App\Models\Subscription;
use App\Library\Selcom\Selcom;
use App\Services\OrderNotificationService;
use Exception;
use Http;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SelcomService extends BaseService
{
    private $selcom;

    public function __construct()
    {
        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();
        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload = $paymentPayload?->payload ?? [];

        $host = request()->getSchemeAndHttpHost();
        
        // Default redirect and webhook URLs
        $redirectUrl = "$host/selcom-result";
        $cancelUrl = "$host/selcom-result";
        $webhookUrl = "$host/api/v1/webhook/selcom/payment";

        $this->selcom = new Selcom($payload, $redirectUrl, $cancelUrl, $webhookUrl);
    }

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
        \Log::info('Selcom data', $data);

        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        /** @var Order|ParcelOrder $order */
        $order = data_get($data, 'parcel_id')
			? ParcelOrder::find(data_get($data, 'parcel_id'))
			: Cart::find(data_get($data, 'cart_id'));

        [$key, $before] = $this->getPayload($data, $payload);
        $modelId 		= data_get($before, 'model_id');
        $totalPrice = data_get($before, 'total_price'); 
        // $totalPrice = $order->rate_total_price; //round($order->rate_total_price * 100, 1);

        $host = request()->getSchemeAndHttpHost();
        $trxRef = "$order->id-" . time();

        //return $trxRef;
        $redirectUrl  = "$host/selcom-result?&status=success&trxRef=$trxRef&" . (
            data_get($data, 'parcel_id') ? "parcel_id=$order->id" : "cart_id=$order->id"
        );
        
        //return $redirectUrl;

        $cancelUrl  = $redirectUrl; //"$host/selcom-result?&status=error&trxRef=$trxRef&" . (
        //     data_get($data, 'parcel_id') ? "parcel_id=$order->id" : "cart_id=$order->id"
        // );

        $user       = auth('sanctum')->user();

        // Fix webhook URL format to properly handle payment callbacks
        $webhookUrl = "$host/api/v1/webhook/selcom/payment?trxRef=$trxRef";
        
        $api = new Selcom($payload, $redirectUrl, $cancelUrl, $webhookUrl);
        $response =  $api->cardCheckoutUrl([
            'name' => $order->username ?? "{$order->user?->firstname} {$order->user?->lastname}", 
            'email' => $order->user?->email,
            'phone' => $this->formatPhone($order->phone ?? $order->user?->phone),
            'amount' => $totalPrice,
            'transaction_id' => $trxRef,
            'address' => 'Dar Es Salaam',
            'postcode' => '',
            'user_id' => auth('sanctum')->id(),
            'country_code' => $order->user?->address?->country?->translation?->title,
            'state' => $order->user?->address?->region?->translation?->title,
            'city' => $order->user?->address?->city?->translation?->title,
            'billing_phone' => $this->formatPhone($order->phone ?? $order->user?->phone),
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
				'payment_id' => $payment->id,
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
				'payment_id' 	  => $payment->id,
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
        try {
            $selcomPayment = SelcomPayment::with(['order.user'])
                ->where('transid', $transID)
                ->first();

            if (!$selcomPayment) {
                Log::error('Selcom payment not found', ['transid' => $transID]);
                return [
                    'status' => false,
                    'message' => 'Payment record not found',
                    'data' => null
                ];
            }

            // Validate order and user existence
            if (!$selcomPayment->order) {
                Log::error('Order not found', [
                    'transid' => $transID,
                    'order_id' => $selcomPayment->order_id
                ]);
                return [
                    'status' => false,
                    'message' => 'Order record not found',
                    'data' => null
                ];
            }

            // Ensure user exists before proceeding
            if (!$selcomPayment->order->user_id) {
                Log::error('Order has no associated user', [
                    'transid' => $transID,
                    'order_id' => $selcomPayment->order_id
                ]);
                return [
                    'status' => false,
                    'message' => 'User not found for order',
                    'data' => null
                ];
            }

            // Now we can safely proceed with getting references
            try {
                $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();
                $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
                $payload = $paymentPayload?->payload ?? [];
                
                // Process the payment result
                $api = new Selcom($payload);
                $result = $api->checkoutStatus($transID);
                
                // Log the detailed response for debugging
                Log::info('Selcom payment status result', ['response' => $result]);
                
                if ($this->isSuccessfulPayment($result)) {
                    // Payment was successful, update the order
                    $this->updateOrderStatus($selcomPayment->order_id);
                    
                    // Send email notification if needed
                    $order = Order::find($selcomPayment->order_id);
                    if ($order && $this->shouldSendEmail($order)) {
                        $this->sendOrderEmail($order);
                    }
                    
                    return [
                        'status' => true,
                        'message' => 'Payment successful',
                        'data' => $result
                    ];
                } else {
                    // Payment failed
                    Log::error('Selcom payment failed', [
                        'transid' => $transID,
                        'order_id' => $selcomPayment->order_id,
                        'result' => $result
                    ]);
                    
                    // Mark the order as payment failed
                    $order = Order::find($selcomPayment->order_id);
                    if ($order) {
                        $order->update(['status' => 'payment_failed']);
                        
                        // Send notification to customer
                        if ($order->user && $order->user->firebase_token) {
                            try {
                                event(new \App\Events\PaymentFailedEvent($order));
                            } catch (\Exception $e) {
                                Log::error('Failed to send payment failed notification', [
                                    'order_id' => $order->id,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    }
                    
                    return [
                        'status' => false,
                        'message' => 'Payment failed',
                        'data' => $result
                    ];
                }
            } catch (\Exception $e) {
                Log::error('Selcom payment processing error', [
                    'transid' => $transID,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return [
                    'status' => false,
                    'message' => 'Error processing payment: ' . $e->getMessage(),
                    'data' => null
                ];
            }
        } catch (\Exception $e) {
            Log::error('Selcom transaction error', [
                'transid' => $transID,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'status' => false,
                'message' => 'Error processing transaction: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    private function isSuccessfulPayment($response): bool
    {
        return $response 
            && $response['result'] === "SUCCESS" 
            && data_get($response, 'data.0.payment_status') === "COMPLETED";
    }

    private function updateOrderStatus($orderId) 
    {
        try {
            DB::beginTransaction();
            
            $order = Order::with(['user'])->findOrFail($orderId);
            
            if (!$order) {
                Log::error('Order not found for ID: ' . $orderId);
                return false;
            }

            // Only update order status
            $order->update([
                'status' => Order::STATUS_PAID,
                'payment_status' => 'paid'
            ]);

            DB::commit();

            // Queue email sending after successful status update
            if ($order->user_id && $this->shouldSendEmail($order)) {
                try {
                    dispatch(function() use ($order) {
                        $this->sendOrderEmail($order);
                    })->afterCommit()->delay(now()->addSeconds(30));
                } catch (\Exception $e) {
                    Log::error('Failed to queue email', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage()
                    ]);
                    // Don't throw - let order processing complete
                }
            }

            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update order status', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function sendOrderEmail(Order $order)
    {
        if (!$this->shouldSendEmail($order)) {
            return;
        }

        try {
            $user = User::where('id', $order->user_id)->first();

            if (!$user || !$user->email) {
                Log::info('Skipping email - Invalid user data', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id
                ]);
                return;
            }

            $emailService = new EmailSendService();
            $emailService->sendOrderPaymentConfirmation($order, $user);

        } catch (\Exception $e) {
            // Log error but don't throw
            Log::error('Email sending failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function shouldSendEmail(Order $order): bool 
    {
        if (!$order->user_id || !$order->user) {
            Log::info('No user associated with order', [
                'order_id' => $order->id
            ]);
            return false;
        }

        if (!$order->user->email) {
            Log::info('User has no email address', [
                'order_id' => $order->id,
                'user_id' => $order->user_id
            ]);
            return false;
        }

        if ($order->status !== Order::STATUS_PAID || $order->payment_status !== 'paid') {
            Log::info('Order not in paid status', [
                'order_id' => $order->id,
                'status' => $order->status,
                'payment_status' => $order->payment_status
            ]);
            return false;
        }

        if (!$order->user->hasVerifiedEmail()) {
            Log::info('User email not verified', [
                'order_id' => $order->id,
                'user_id' => $order->user_id
            ]);
            return false;
        }

        return true;
    }

    private function generateUniqueTransId(): string 
    {
        try {
            // Start a new database transaction with serializable isolation
            DB::beginTransaction();
            
            // Get current timestamp with nanosecond precision
            $timestamp = now();
            $nano = hrtime(true);
            
            // Generate components for transaction ID
            $dateComponent = $timestamp->format('ymdHis');
            $nanoComponent = substr(str_pad($nano, 12, '0'), -6);
            $randomComponent = strtoupper(Str::random(8));
            
            // Combine components with delimiter to ensure uniqueness
            $transId = $this->getKey();
            
            // Acquire exclusive lock on temporary record
            DB::select('SELECT GET_LOCK(?, 10)', [$transId]);
            
            try {
                // Create reservation record
                SelcomPayment::create([
                    'transid' => $transId,
                    'order_id' => 'TEMP-' . Str::random(8),
                    'amount' => 0,
                    'payment_status' => 'TEMP',
                    'created_at' => now()
                ]);
                
                DB::commit();
                return $transId;
                
            } finally {
                // Always release lock
                DB::select('SELECT RELEASE_LOCK(?)', [$transId]);
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log error but don't expose details
            Log::error('Failed to generate transaction ID', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Last resort - use UUID based ID
            $fallbackId = "TX" . now()->format('ymdHis') . '-' . (string)Str::uuid();
            
            // Create with fallback ID
            SelcomPayment::create([
                'transid' => $fallbackId,
                'order_id' => 'TEMP-' . Str::random(8),
                'amount' => 0, 
                'payment_status' => 'TEMP',
                'created_at' => now()
            ]);
            
            return $fallbackId;
        }
    }

    private function getKey(){
        $timestamp = now();
        $nano = hrtime(true);
        
        // Generate components for transaction ID
        $dateComponent = $timestamp->format('ymdHis');
        $nanoComponent = substr(str_pad($nano, 12, '0'), -6);
        $randomComponent = strtoupper(Str::random(8));
        
        // Combine components with delimiter to ensure uniqueness
        $transId = "TX{$dateComponent}-{$randomComponent}-{$nanoComponent}";
        $isExist = SelcomPayment::where("transid", $transId)->first();
        if($isExist){
            return $this->getKey();
        }
        return $transId;
    }
    private function isDuplicateKeyError(\Exception $e): bool 
    {
        return $e instanceof \Illuminate\Database\QueryException 
            && str_contains($e->getMessage(), '1062 Duplicate entry');
    }

    public function createTransaction(array $data)
    {
        try {
            DB::beginTransaction();

            $cart = Cart::with(['user', 'shop'])
                ->find($data['cart_id']);

            if (!$cart || !$cart->user) {
                return [
                    'status' => false,
                    'code' => 404,
                    'message' => 'Cart or user not found'
                ];
            }

            // Create order first
            $order = Order::create([
                'user_id' => auth()->id(),
                'shop_id' => $cart->shop_id,
                'cart_id' => $cart->id,
                'total_price' => $cart->total_price,
                'currency_id' => $cart->currency_id,
                'rate' => $cart->rate,
                'status' => Order::STATUS_NEW,
                'payment_status' => 'pending'
            ]);

            // Generate unique transaction ID first
            $transId = $this->generateUniqueTransId();

            $payment = SelcomPayment::create([
                'transid' => $transId,
                'order_id' => $order->id,
                'amount' => $cart->total_price,
                'currency' => $cart->currency?->title ?? 'TZS',
                'user_id' => $cart->user->id,
                'payment_status' => 'PENDING',
                'payment_type' => 'CARD',
                'msisdn' => $this->formatPhone($cart->user->phone)
            ]);

            // Call the Selcom API to create the payment URL
            $paymentUrl = $this->selcom->generatePaymentUrl($order);

            DB::commit();

            return [
                'status' => true,
                'message' => 'Transaction created successfully',
                'data' => [
                    'payment_url' => $paymentUrl,
                    'transaction_id' => $transId,
                    'order_id' => $order->id
                ]
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Selcom create transaction error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'status' => false,
                'code' => 500,
                'message' => $e->getMessage()
            ];
        }
    }

    public function generatePaymentUrl(Order $order)
    {
        try {
            $transId = $this->generateUniqueTransId();
            $user = auth('sanctum')->user();
            
            // Get user's address details
            $address = $user?->address;
            $addressLine = $address ? 
                trim($address->address ?? '') : 
                'Dar Es Salaam, Tanzania';
            $postcode = $address?->postcode ?? '00000';

            $payment = SelcomPayment::create([
                'transid' => $transId,
                'order_id' => $order->id,
                'amount' => $order->total_price,
                'currency' => $order->currency?->title ?? 'TZS',
                'user_id' => $user?->id,
                'payment_status' => 'PENDING',
                'payment_type' => 'CARD',
                'msisdn' => $this->formatPhone($user?->phone)
            ]);

            return $this->selcom->generatePaymentUrl([
                'transid' => $transId,
                'amount' => $order->total_price,
                'currency' => $order->currency?->title ?? 'TZS',
                'order_id' => $order->id,
                'cart_id' => $order->cart_id,
                'callback_url' => route('payment.selcom.callback'),
                'address' => $addressLine,
                'postcode' => $postcode,
                'country_code' => $address?->country?->code ?? 'TZ',
                'state' => $address?->region?->translation?->title ?? 'Dar Es Salaam',
                'city' => $address?->city?->translation?->title ?? 'Dar Es Salaam',
            ]);

        } catch (\Exception $e) {
            Log::error('Selcom generate payment URL error', [
                'error' => $e->getMessage(),
                'order_id' => $order->id,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
