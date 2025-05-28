<?php

namespace App\Services\PaymentService;

use App\Helpers\ResponseError;
use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Payout;
use App\Models\Shop;
use App\Models\Subscription;
use DB;
use Exception;
use Http;
use Illuminate\Database\Eloquent\Model;
use Str;
use Throwable;

class PayTabsService extends BaseService
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
    {
        $payment = Payment::where('tag', 'paytabs')->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $headers = [
            'Accept' 		=> 'application/json',
            'Content-Type' 	=> 'application/json',
            'authorization' => data_get($payload, 'server_key')
        ];

		[$key, $before] = $this->getPayload($data, $payload);
		$modelId 		= data_get($before, 'model_id');

		$totalPrice = round((float)data_get($before, 'total_price') * 100, 2);

		$host = request()->getSchemeAndHttpHost();
		$url  = "$host/order-stripe-success?$key=$modelId";

        $trxRef = "$modelId-" . time();

        $currency = Str::upper(data_get($before, 'currency'));

        if(!in_array($currency, ['AED','EGP','SAR','OMR','JOD','US'])) {
            throw new Exception(__('errors.' . ResponseError::CURRENCY_NOT_FOUND, locale: $this->language));
        }

        $request = Http::withHeaders($headers)->post('https://secure.paytabs.sa/payment/request', [
            'profile_id'        => data_get($payload, 'profile_id'),
            'tran_type'         => 'sale',
            'tran_class'        => 'ecom',
            'cart_id'        	=> $trxRef,
            'cart_description'  => "payment for #$modelId",
            'cart_currency'  	=> $currency,
            'cart_amount'  		=> $totalPrice,
            'callback'          => "$host/api/v1/webhook/paytabs/payment",
            'return'          	=> $url,
        ]);

        $body = $request->json();

        if (!in_array($request->status(), [200, 201])) {
            throw new Exception(data_get($body, 'message'));
        }

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $modelId,
            'model_type' => data_get($before, 'model_type')
        ], [
            'id' => $trxRef,
            'data' => [
                'url'  		 => data_get($body, 'redirect_url'),
                'price'		 => $totalPrice,
				'cart'  	 => $data,
				'payment_id' => $payment->id,
            ]
        ]);
    }

	/**
	 * @param array $data
	 * @return array
	 * @throws Throwable
	 */
	public function splitTransaction(array $data): array
	{
		return DB::transaction(function () use ($data) {
			$payment = Payment::where('tag', Payment::TAG_PAY_STACK)->first();

			$paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
			$payload        = $paymentPayload?->payload;

			$headers = [
				'Accept' 		=> 'application/json',
				'Content-Type' 	=> 'application/json',
				'authorization' => data_get($payload, 'server_key')
			];

			[$key, $before] = $this->getPayload($data, $payload);
			$modelId 		= data_get($before, 'model_id');

			$result = [];
			$split  = $data['split'] ?? 1;

			$totalPrice = round((float)$before['total_price'] * 100 / $split, 2);

			$before['total_price'] = $totalPrice;

			if ($before['total_price'] <= 0) {
				throw new Exception('The minimum amount must be greater than 1' . $before['currency']);
			}

			$host = request()->getSchemeAndHttpHost();
			$url  = "$host/order-stripe-success?$key=$modelId";

			for ($i = 0; $split > $i; $i++) {

				$totalPrice = round($totalPrice, 2);

				$trxRef = "$modelId-" . time();

				$currency = Str::upper(data_get($before, 'currency'));

				if(!in_array($currency, ['AED','EGP','SAR','OMR','JOD','US'])) {
					throw new Exception(__('errors.' . ResponseError::CURRENCY_NOT_FOUND, locale: $this->language));
				}

				$request = Http::withHeaders($headers)->post('https://secure.paytabs.sa/payment/request', [
					'profile_id'        => data_get($payload, 'profile_id'),
					'tran_type'         => 'sale',
					'tran_class'        => 'ecom',
					'cart_id'        	=> $trxRef,
					'cart_description'  => "payment for #$modelId",
					'cart_currency'  	=> $currency,
					'cart_amount'  		=> $totalPrice,
					'callback'          => "$host/api/v1/webhook/paytabs/payment",
					'return'          	=> $url,
				]);

				$body = $request->json();

				if (!in_array($request->status(), [200, 201])) {
					throw new Exception(data_get($body, 'message'));
				}

				$paymentProcess = PaymentProcess::create([
					'user_id'    => auth('sanctum')->id(),
					'model_id'   => $modelId,
					'model_type' => data_get($before, 'model_type'),
					'id' 		 => $trxRef,
            		'data' => [
						'url'  		 => data_get($body, 'redirect_url'),
						'price'		 => $totalPrice,
						'cart'  	 => $data,
						'payment_id' => $payment->id,
					]
				]);

				$paymentProcess->id = $trxRef;

				$result[] = $paymentProcess;

			}

			return $result;
		});
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
        $payment = Payment::where('tag', Payment::TAG_PAY_TABS)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $host           = request()->getSchemeAndHttpHost();

        /** @var Subscription $subscription */
        $subscription   = Subscription::find(data_get($data, 'subscription_id'));

        $headers = [
            'Accept' 		=> 'application/json',
            'Content-Type' 	=> 'application/json',
            'authorization' => data_get($payload, 'server_key')
        ];

        $trxRef = "$subscription->id-" . time();

        $currency = Str::upper(data_get($payload, 'currency', $currency));

        if(!in_array($currency, ['AED','EGP','SAR','OMR','JOD','US'])) {
            throw new Exception(__('errors.' . ResponseError::CURRENCY_NOT_FOUND, locale: $this->language));
        }

        $request = Http::withHeaders($headers)->post('https://secure.paytabs.sa/payment/request', [
            'profile_id'        => data_get($payload, 'profile_id'),
            'tran_type'         => 'sale',
            'tran_class'        => 'ecom',
            'cart_id'        	=> $trxRef,
            'cart_description'  => "seller subscription",
            'cart_currency'  	=> $currency,
            'cart_amount'  		=> ceil($subscription->price),
            'callback'          => "$host/api/v1/webhook/paytabs/payment",
            'return'          	=> "$host/subscription-stripe-success?subscription_id=$subscription->id",
        ]);

        $body = $request->json();

        if (!in_array($request->status(), [200, 201])) {
            throw new Exception(data_get($body, 'message'));
        }

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $subscription->id,
            'model_type' => get_class($subscription)
        ], [
            'id' => $trxRef,
            'data' => [
                'url'  			  => data_get($body, 'redirect_url'),
                'price'           => ceil($subscription->price) * 100,
                'shop_id'         => $shop->id,
                'subscription_id' => $subscription->id,
				'payment_id' 	  => $payment->id,
            ]
        ]);
    }
}
