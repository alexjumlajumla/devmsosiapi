<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Http\Requests\Payment\PaymentRequest;
use App\Models\PaymentProcess;
use App\Models\WalletHistory;
use App\Services\PaymentService\SelcomService;
use App\Traits\ApiResponse;
use App\Traits\OnResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Log;
use Throwable;
use Redirect;
use App\Library\Selcom\Selcom;
use App\Models\PaymentPayload;
use App\Models\SelcomPayment;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use App\Http\Requests\Payment\SplitRequest;
use App\Http\Requests\Payment\StripeRequest;
use App\Http\Requests\Shop\SubscriptionRequest;
use App\Models\ParcelOrder;
use App\Models\PushNotification;
use App\Models\User;
use App\Traits\Notification;


//class SelcomController extends PaymentBaseController
class SelcomController extends PaymentBaseController
{
    use OnResponse, ApiResponse, Notification;

    public function __construct(private SelcomService $service)
    {
        parent::__construct();
    }

    public function orderProcessTransaction(StripeRequest $request): JsonResponse
    //public function orderProcessTransaction(StripeRequest $request)
    {
		Log::info('Incoming request top ' . $request->url());

        
        try {
            
            $paymentProcess = $this->service->orderProcessTransaction($request->all());
			
		

            //return $this->successResponse($paymentProcess);
            //return $this->successResponse($paymentProcess['data']['url']);
            return $this->successResponse('success', $paymentProcess);
        } catch (Throwable $e) {
            return $this->onErrorResponse(['message' => $e->getMessage()]);
        }
    }

    public function splitTransaction(SplitRequest $request): JsonResponse
	{
		try {
			$result = $this->service->splitTransaction($request->all());

			return $this->successResponse('success', $result);
		} catch (Throwable $e) {
			$this->error($e);
			return $this->onErrorResponse([
				'message' => $e->getMessage(),
				'param'   => $e->getFile() . $e->getLine()
			]);
		}
	}

    public function subscriptionProcessTransaction(SubscriptionRequest $request): array
    {
        $shop = auth('sanctum')->user()?->shop ?? auth('sanctum')->user()?->moderatorShop;

        if (empty($shop)) {
            return ['status' => false, 'code' => ResponseError::ERROR_101];
        }

        /** @var Shop $shop */
        $currency = Currency::currenciesList()->where('active', 1)->where('default', 1)->first()?->title;

        if (empty($currency)) {
            return [
                'status'    => true,
                'code'      => ResponseError::ERROR_431,
                'message'   => 'Active default currency not found'
            ];
        }

        try {
            $paymentProcess = $this->service->subscriptionProcessTransaction($request->all(), $shop, $currency);

            return ['status' => true, 'data' => $paymentProcess];
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    public function subscriptionResultTransaction(Request $request): RedirectResponse
    {
        $to = config('app.front_url') . "seller/subscriptions/" . (int)$request->input('subscription_id');
        $transID = $request->input('trxRef');
        $order = SelcomPayment::where('transid', $transID)->first();

        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $api = new Selcom($payload);
            
        $response = $api->orderStatus($order->order_id); dd($response);
        $status = match (data_get($response['data'][0], 'payment_status')) {
            'cancelled', 'expired'  => Transaction::CANCELED,
            'COMPLETED'             => Transaction::STATUS_PAID,
            default                 => 'progress',
        };
        $this->service->afterHook($order->transid, $status);

        return Redirect::to($to);
    }

    /**
     * @param Request $request
     * @return void
     */
    public function paymentWebHook(Request $request): void
    {
        Log::error('Selcom paymentWebHook', $request->all());
        // $status = $request->input('data.object.status');

        // $status = match ($status) {
        //     'succeeded' => WalletHistory::PAID,
        //     default     => 'progress',
        // };

        // $token = $request->input('data.object.id');

        // $this->service->afterHook($token, $status);
    }

    public function orderResultTransaction(Request $request): RedirectResponse
{
    // Add detailed request logging
    Log::info('Selcom Result Transaction - Full Request', [
        'request' => $request->all(),
        'headers' => $request->headers->all(),
        'url' => $request->fullUrl()
    ]);
    
    try {
        $transID = $request->input('trxRef');
        Log::info('Selcom Transaction ID', ['transID' => $transID]);
        
        $mStatus = $request->input('status');
        Log::info('Selcom Status', ['status' => $mStatus]);
        
        // Log cart_id retrieval attempt
        if (!$request->has('cart_id')) {
            Log::info('Attempting to get cart_id from SelcomPayment');
            $selcomPayment = SelcomPayment::where('transid', $transID)->first();
            Log::info('SelcomPayment record', ['payment' => $selcomPayment]);
            $cart_id = $selcomPayment?->cart_id ?? null;
        } else {
            $cart_id = (int)$request->input('cart_id');
        }
        Log::info('Cart ID', ['cart_id' => $cart_id]);

        // Remove trailing slash from base URL
        $frontUrl = rtrim(config('app.front_url'), '/');
        
        $order = SelcomPayment::where('transid', $transID)->first();

        // Wallet top-up flow may not create SelcomPayment record
        if (!$order) {
            // Determine status based on request status param directly if API check is unavailable
            $status = $mStatus === 'success' ? Transaction::STATUS_PAID : Transaction::CANCELED;

            // Execute common after-hook logic to update wallet etc.
            $this->service->afterHook($transID, $status);

            $frontUrl = rtrim(config('app.front_url'), '/');
            return Redirect::to("$frontUrl/wallet-histories");
        }

        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();
        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        
        if (!$paymentPayload) {
            throw new \Exception('Payment configuration not found');
        }

        $api = new Selcom($paymentPayload->payload);
        $response = $api->orderStatus($order->order_id);

        $status = match (data_get($response['data'][0], 'payment_status')) {
            'cancelled', 'expired' => Transaction::CANCELED,
            'COMPLETED' => Transaction::STATUS_PAID,
            default => 'progress',
        };

        $this->service->afterHook($order->transid, $status);
        
        // Redirect based on status and cart_id availability
        if ($status === Transaction::STATUS_PAID) {
            $to = "$frontUrl/orders";
        } else {
            $to = $cart_id ? "$frontUrl/restaurant/$cart_id/checkout" : "$frontUrl/orders";
        }

        // Add payment status as query parameter
        $to .= (strpos($to, '?') === false ? '?' : '&') . 'payment_status=' . ($mStatus ?? 'failed');

    } catch (\Exception $e) {
        Log::error('Selcom Result Error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
        $frontUrl = rtrim(config('app.front_url'), '/');
        return Redirect::to("$frontUrl/orders?payment_status=error");
    }

    return Redirect::to($to);
}
	

	

public function checkTransaction(string $transid): JsonResponse
{
    // Start with assuming transaction does not exist
    $exists = false;
    $selcomStatus = null;
    $selcomResponse = null;

    try {
        // Get the SelcomPayment record to access order_id
        $order = SelcomPayment::where('transid', $transid)->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found in selcom_payments table.',
            ], 404);
        }

        // Get Selcom credentials (payload) for API
        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();
        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();

        if (!$paymentPayload) {
            throw new \Exception('Payment payload not found.');
        }
		
		
		  \Log::info("Selcom API credentials:", [
            'selcom_key' => data_get($paymentPayload->payload, 'selcom_key'),
            'selcom_secret' => data_get($paymentPayload->payload, 'selcom_secret'),
            'selcom_vendor_id' => data_get($paymentPayload->payload, 'selcom_vendor_id')
        ]);

        // Instantiate Selcom API
        $api = new Selcom($paymentPayload->payload);
		
		

        // Make the API call
        $selcomResponse = $api->orderStatus($order->order_id);
        $selcomStatus = data_get($selcomResponse['data'][0], 'payment_status');

        if ($selcomStatus === 'COMPLETED') {
            // Set exists to true because it's now complete
            $exists = true;

            // Update payment status in the DB
            SelcomPayment::where('transid', $transid)->update([
                'payment_status' => 'COMPLETED'
            ]);
        }

    } catch (\Throwable $e) {
        \Log::error("Selcom order status check failed for $transid", [
            'error' => $e->getMessage(),
        ]);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'transid' => $transid,
            'exists' => $exists,
            'selcom_status' => $selcomStatus,
            'selcom_response' => $selcomResponse,
        ]
    ]);
}

	
	
	
	
	
	
public function tokens(int $userId = null): array
{
    $userFirebaseTokens = [];
    $adminFirebaseTokens = [];

    if ($userId) {
        $user = User::find($userId);
        if ($user && $user->firebase_token) {
            $userFirebaseTokens[$user->id] = $user->firebase_token;
        }
    }

    $adminFirebaseTokens = User::with([
        'roles' => fn($q) => $q->where('name', 'admin')
    ])
        ->whereHas('roles', fn($q) => $q->where('name', 'admin'))
        ->whereNotNull('firebase_token')
        ->pluck('firebase_token', 'id')
        ->toArray();

    // Merge user first, then admins
    $allFirebaseTokens = array_merge($userFirebaseTokens, $adminFirebaseTokens);

    $aTokens = [];
    foreach ($allFirebaseTokens as $token) {
        $aTokens = array_merge($aTokens, is_array($token) ? array_values($token) : [$token]);
    }

    return [
        'tokens' => array_values(array_unique($aTokens)),
        'ids'    => array_keys($allFirebaseTokens)
    ];
}

	
public function checkTransactionStatusofParcelAndDelete(int $parcelId, $transid = null): JsonResponse
{
    $isCompleted = false;
    $selcomStatus = null;

    try {
        // First check if parcel exists to avoid 404 errors
        $parcel = ParcelOrder::find($parcelId);
        $parcelExists = $parcel !== null;
        
        // Check if transaction exists (if transid is provided)
        $order = $transid ? SelcomPayment::where('transid', $transid)->first() : null;

        // If neither the transaction nor parcel exist, return a 404
        if (!$order && !$parcelExists) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction and parcel not found. No action taken.',
            ], 404);
        }

        // If no transaction exists but parcel does, delete the parcel
        if (!$order && $parcelExists) {
            $parcel->delete();
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found. Parcel has been deleted.',
            ], 200);
        }

        // If transaction exists, check Selcom status
        if ($order) {
            $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();
            $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();

            if (!$paymentPayload) {
                throw new \Exception('Payment payload not found.');
            }

            $api = new Selcom($paymentPayload->payload);
            $selcomResponse = $api->orderStatus($order->order_id);
            $selcomStatus = data_get($selcomResponse['data'][0], 'payment_status');

            if ($selcomStatus === 'COMPLETED') {
                $isCompleted = true;
                $order->update(['payment_status' => 'COMPLETED']);

                if ($parcel) {
                    $tokens = $this->tokens($parcel->user_id);
                    $this->sendNotification(
                        data_get($tokens, 'tokens'),
                        "New parcel order was created",
                        $parcel->id,
                        $parcel->setAttribute('type', PushNotification::NEW_PARCEL_ORDER)->only(['id', 'status', 'type']),
                        data_get($tokens, 'ids', [])
                    );
                }
            } else if ($parcelExists) {
                // If transaction not completed and parcel exists, delete the parcel
                $parcel->delete();
            }
        }

    } catch (\Throwable $e) {
        \Log::error("Selcom order status check failed for parcel $parcelId, transaction $transid", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Something went wrong during transaction check.',
            'error'   => $e->getMessage()
        ], 500);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'transid' => $transid,
            'parcel_id' => $parcelId,
            'is_completed' => $isCompleted,
            'selcom_status' => $selcomStatus,
        ]
    ]);
}
	
public function checkTransactionDirect1(): JsonResponse
{
    // Hardcoded transaction ID (replace with your test value)
    $transid = '551-1744714604';
    $exists = false;
    $selcomStatus = null;
    $selcomResponse = null;

    try {
        // 1. Get Selcom API credentials
        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();
        
        if (!$payment) {
            throw new \Exception('Selcom payment configuration not found.');
        }

        $paymentPayload = PaymentPayload::where('payment_id', $payment->id)->first();

        if (!$paymentPayload) {
            throw new \Exception('Selcom API credentials not found.');
        }

        // 2. Initialize Selcom API client
        $api = new Selcom($paymentPayload->payload);

        // 3. Prepare request data (using transid AS order_id)
        $requestData = [
            'order_id' => $transid // Using transid as order_id
        ];

        // 4. Make API call (adjust path if needed)
        $selcomResponse = $api->getFunc("/v1/checkout/order-status", $requestData);

        // 5. Extract payment status
        $selcomStatus = data_get($selcomResponse, 'data.0.payment_status');

        // 6. Verify transaction ID matches (optional)
        $responseTransid = data_get($selcomResponse, 'data.0.transid');
        
        if ($responseTransid && $responseTransid !== $transid) {
            \Log::warning("Transaction ID mismatch", [
                'requested_transid' => $transid,
                'response_transid' => $responseTransid
            ]);
        }

        if ($selcomStatus === 'COMPLETED') {
            $exists = true;
        }

    } catch (\Throwable $e) {
        \Log::error("Selcom direct status check failed for $transid", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Failed to check transaction status.',
            'error' => $e->getMessage(),
        ], 500);
    }

    return response()->json([
        'success' => true,
        'data' => [
            'transid' => $transid,
            'exists' => $exists,
            'selcom_status' => $selcomStatus,
            'selcom_response' => $selcomResponse,
        ],
    ]);
}
	
	
	
	
	public function checkTransactionDirect(string $transid)
{
    // Your API credentials (make sure they're securely fetched from the config or environment)
    $authorization = 'SELCOM VElMTDYxMTA4MDE5LWVmNGQxZmE5ZjNjYzRlMzA5ZWNiOTM0ZGJkZTMyNWY2';
    $digestMethod = 'HS256';
    $digest = '2r0seRrH653725Gxsxsxsxsx/we1l49q42LQPlhp5QmaqvLQM+rw=';
    $timestamp = '2025-04-28T13:14:04+03:00';
    
    // Make the GET request to the Selcom API
    $response = Http::withHeaders([
        'Authorization' => $authorization,
        'Digest-Method' => $digestMethod,
        'Digest' => $digest,
        'Signed-Fields' => '',
        'Timestamp' => $timestamp,
        'Content-type' => 'application/json;charset=utf-8',
        'Accept' => 'application/json',
    ])->get('https://apigw.selcommobile.com/v1/checkout/order-status', [
        'order_id' => $transid
    ]);
    
    // Log or handle the response
    \Log::info('Selcom API Response:', ['response' => $response->json()]);
    
    return $response->json();
}
	
	
}
