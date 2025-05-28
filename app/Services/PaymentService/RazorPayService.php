<?php

namespace App\Services\PaymentService;

use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Payout;
use App\Models\Shop;
use App\Models\Subscription;
use DB;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Razorpay\Api\Api;
use Str;
use Throwable;

class RazorPayService extends BaseService
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
        return DB::transaction(function () use ($data) {

            $host           = request()->getSchemeAndHttpHost();

            $payment        = Payment::where('tag', Payment::TAG_RAZOR_PAY)->first();
            $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
            $payload        = $paymentPayload?->payload;

            $apiKey = data_get($paymentPayload?->payload, 'razorpay_key');
            $secret = data_get($paymentPayload?->payload, 'razorpay_secret');
            $api    = new Api($apiKey, $secret);

			[$key, $before] = $this->getPayload($data, $payload);
			$modelId 		= data_get($before, 'model_id');

			$totalPrice = round((float)data_get($before, 'total_price') * 100, 2);

            $paymentLink = $api->paymentLink->create([
                'amount'                    => $totalPrice,
                'currency'                  => Str::upper(data_get($before, 'currency')),
                'accept_partial'            => false,
                'first_min_partial_amount'  => $totalPrice,
                'description'               => "For #$modelId",
                'callback_url'              => "$host/order-stripe-success?$key=$modelId",
                'callback_method'           => 'get'
            ]);

            return PaymentProcess::updateOrCreate([
                'user_id'   => auth('sanctum')->id(),
				'model_id'   => $modelId,
				'model_type' => data_get($before, 'model_type')
            ], [
                'id'    => data_get($paymentLink, 'id'),
                'data'  => [
                    'url'        => data_get($paymentLink, 'short_url'),
                    'price'      => $totalPrice,
                    'cart_id'    => $modelId,
					'cart'	     => $data,
					'payment_id' => $payment->id,
                ]
            ]);
        });
    }

	/**
	 * @param array $data
	 * @return array
	 * @throws Throwable
	 */
	public function splitTransaction(array $data): array
	{
		return DB::transaction(function () use ($data) {

			$host           = request()->getSchemeAndHttpHost();
			$payment        = Payment::where('tag', Payment::TAG_RAZOR_PAY)->first();
			$paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
			$payload        = $paymentPayload?->payload;

			$apiKey = data_get($paymentPayload?->payload, 'razorpay_key');
			$secret = data_get($paymentPayload?->payload, 'razorpay_secret');
			$api    = new Api($apiKey, $secret);

			[$key, $before] = $this->getPayload($data, $payload);
			$modelId 		= data_get($before, 'model_id');

			$result = [];
			$split  = $data['split'] ?? 1;

			$totalPrice = round((float)$before['total_price'] * 100 / $split, 2);

			$before['total_price'] = $totalPrice;

			if ($before['total_price'] <= 0) {
				throw new Exception('The minimum amount must be greater than 1' . $before['currency']);
			}

			for ($i = 0; $split > $i; $i++) {

				$totalPrice = round($totalPrice, 2);

				$paymentLink = $api->paymentLink->create([
					'amount'                    => $totalPrice,
					'currency'                  => Str::upper(data_get($before, 'currency')),
					'accept_partial'            => false,
					'first_min_partial_amount'  => $totalPrice,
					'description'               => "For #$modelId",
					'callback_url'              => "$host/order-stripe-success?$key=$modelId",
					'callback_method'           => 'get'
				]);

				$paymentProcess = PaymentProcess::updateOrCreate([
					'user_id'    => auth('sanctum')->id(),
					'model_id'   => $modelId,
					'model_type' => data_get($before, 'model_type'),
					'id'    	 => data_get($paymentLink, 'id'),
					'data'  => [
						'url'        => data_get($paymentLink, 'short_url'),
						'price'      => $totalPrice,
						'cart_id'    => $modelId,
						'cart'	     => $data,
						'payment_id' => $payment->id,
					]
				]);

				$paymentProcess->id = data_get($paymentLink, 'id');

				$result[] = $paymentProcess;

			}

			return $result;
		});
	}

	/**
     * @param array $data
     * @param Shop $shop
     * @param $currency
     * @return Model|PaymentProcess
     */
    public function subscriptionProcessTransaction(array $data, Shop $shop, $currency): Model|PaymentProcess
    {
        /** @var Shop $shop */
        $payment        = Payment::where('tag', Payment::TAG_RAZOR_PAY)->first();
        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $key            = data_get($payload, 'razorpay_key');
        $secret         = data_get($payload, 'razorpay_secret');
        $api            = new Api($key, $secret);

        $host           = request()->getSchemeAndHttpHost();

        $subscription   = Subscription::find(data_get($data, 'subscription_id'));

        $totalPrice     = round($subscription->price * 100, 2);

        $paymentLink    = $api->paymentLink->create([
            'amount'                    => $totalPrice,
            'currency'                  => Str::upper($currency),
            'accept_partial'            => false,
            'first_min_partial_amount'  => $totalPrice,
            'description'               => "For Subscription",
            'callback_url'              => "$host/subscription-razorpay-success?subscription_id=$subscription->id",
            'callback_method'           => 'get'
        ]);

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
			'model_id'   => $subscription->id,
			'model_type' => get_class($subscription)
        ], [
            'id'    => data_get($paymentLink, 'id'),
            'data'  => [
                'url'        => data_get($paymentLink, 'short_url'),
                'price'      => $totalPrice,
                'shop_id'    => $shop->id,
				'payment_id' => $payment->id,
            ]
        ]);
    }
}
