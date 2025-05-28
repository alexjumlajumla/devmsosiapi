<?php

namespace App\Services\PaymentService;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;
use App\Models\Payout;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Transaction;
use DB;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Str;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Throwable;

class StripeService extends BaseService
{
    protected function getModelClass(): string
    {
        return Payout::class;
    }

    /**
     * @param array $data
     * @param array $types
     * @return PaymentProcess|Model
     * @throws Throwable
     */
    public function orderProcessTransaction(array $data, array $types = ['card']): Model|PaymentProcess
    {
        $payment = Payment::where('tag', Payment::TAG_STRIPE)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        Stripe::setApiKey(data_get($payload, 'stripe_sk'));

		[$key, $before] = $this->getPayload($data, $payload);
		$modelId 		= data_get($before, 'model_id');
		$modelType 	    = data_get($before, 'model_type');

        $totalPrice = round((float)data_get($before, 'total_price') * 100, 2);

		DB::table('payment_process')
			->where(['model_id' => $modelId, 'model_type' => $modelType])
			->when(auth('sanctum')->id(), fn($q, $id) => $q->where('user_id', $id))
			->delete();

		$transactions = Transaction::with([
			'children' => fn($q) => $q->where('status', Transaction::STATUS_PAID)
		])
			->where('payable_id', $modelId)
			->where('payable_type', $modelType)
			->where('status', '!=', Transaction::STATUS_PAID)
			->get();

		foreach ($transactions as $trn) {
			/** @var Transaction $trn */
			if (!empty($trn->children)) {
				$trn->children()->update(['parent_id' => null]);
			}

			$trn->delete();
		}

		if (@$data['type'] === 'mobile') {
			return $this->mobile($data, $types, $before, $totalPrice, $modelId, $payment);
		}

		$host = request()->getSchemeAndHttpHost();
		$url  = "$host/order-stripe-success?token={CHECKOUT_SESSION_ID}&$key=$modelId";

		return $this->web($data, $types, $before, $totalPrice, $modelId, $payment, $url);
    }

	/**
	 * @param array $data
	 * @param array $types
	 * @return array
	 * @throws Exception
	 */
    public function splitTransaction(array $data, array $types = ['card']): array
	{
        $payment = Payment::where('tag', Payment::TAG_STRIPE)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        Stripe::setApiKey(data_get($payload, 'stripe_sk'));

		[$key, $before] = $this->getPayload($data, $payload);
		$modelId 		= data_get($before, 'model_id');
		$modelType 	    = data_get($before, 'model_type');
		$modelClass     = Str::replace('App\\Models\\', '', $modelType);

		$result = [];
		$split  = $data['split'] ?? 1;

		$totalPrice = round((float)$before['total_price'] * 100 / $split, 2);
		$before['total_price'] = $totalPrice;

		if ($before['total_price'] <= 0) {
			throw new Exception('The minimum amount must be greater than 1' . $before['currency']);
		}

		DB::table('payment_process')
			->where(['model_id' => $modelId, 'model_type' => $modelType])
			->when(auth('sanctum')->id(), fn($q, $id) => $q->where('user_id', $id))
			->delete();

		$transactions = Transaction::with([
			'children' => fn($q) => $q->where('status', Transaction::STATUS_PAID)
		])
			->where('payable_id', $modelId)
			->where('payable_type', $modelType)
			->where('status', '!=', Transaction::STATUS_PAID)
			->get();

		foreach ($transactions as $trn) {
			/** @var Transaction $trn */
			if (!empty($trn->children)) {
				$trn->children()->update(['parent_id' => null]);
			}

			$trn->delete();
		}

		$order = $modelType::with(['transaction'])->find($modelId);

		/** @var Order $order */
		if ($order->transaction && $order->transaction->payment_sys_id !== $payment?->id) {
			$order->createManyTransaction($order->transaction->toArray());
		}

		$transaction = $order->createTransaction([
			'price'              => $before['total_price'],
			'user_id'            => $order->user_id,
			'payment_sys_id'     => $payment?->id,
			'payment_trx_id'     => null,
			'note'               => "Split payment for $modelClass #$order->id",
			'perform_time'       => now(),
			'status_description' => "Transaction for $modelClass #$order->id with split",
			'status'             => Transaction::STATUS_SPLIT,
		]);

		for ($i = 0; $split > $i; $i++) {

			$totalPrice = ceil($totalPrice);

			$order->createManyTransaction([
				'price'              => $totalPrice / 100,
				'user_id'            => $order->user_id,
				'payment_sys_id'     => $payment?->id,
				'payment_trx_id'     => null,
				'note'               => "Split payment for $modelClass #$order->id",
				'perform_time'       => now(),
				'status_description' => "Transaction for $modelClass #$order->id with split",
				'status'             => Transaction::STATUS_PROGRESS,
				'parent_id'          => $transaction->id,
			]);

			if (@$data['type'] === 'mobile') {
				$result[] = $this->mobile($data, $types, $before, $totalPrice, $modelId, $payment);
				continue;
			}

			$host = request()->getSchemeAndHttpHost();
			$url  = "$host/order-stripe-success?token={CHECKOUT_SESSION_ID}&$key=$modelId";

			$result[] = $this->web($data, $types, $before, $totalPrice, $modelId, $payment, $url);
		}

		return $result;
    }

	/**
	 * @param array $data
	 * @param array $types
	 * @param array $before
	 * @param float $totalPrice
	 * @param int $modelId
	 * @param Payment $payment
	 * @return Model|PaymentProcess
	 * @throws ApiErrorException
	 */
	private function mobile(array $data, array $types, array $before, float $totalPrice, int $modelId, Payment $payment): Model|PaymentProcess
	{
		$session = PaymentIntent::create([
			'payment_method_types' => $types,
			'currency' => Str::lower(data_get($before, 'currency')),
			'amount' => $totalPrice,
		]);

		$paymentProcess = PaymentProcess::create([
			'user_id'    => auth('sanctum')->id(),
			'model_id'   => $modelId,
			'model_type' => data_get($before, 'model_type'),
			'id' 		 => $session->id,
			'data' 		 => array_merge([
				'client_secret' => $session->client_secret,
				'price'         => $totalPrice,
				'type'          => 'mobile',
				'cart'			=> $data,
				'payment_id' 	=> $payment->id,
				'split'			=> $data['split'] ?? 1
			], $before)
		]);

		$paymentProcess->id = $session->id;

		return $paymentProcess;
	}

	/**
	 * @param array $data
	 * @param array $types
	 * @param array $before
	 * @param float $totalPrice
	 * @param int $modelId
	 * @param Payment $payment
	 * @param string $url
	 * @return Model|PaymentProcess
	 * @throws ApiErrorException
	 */
	private function web(array $data, array $types, array $before, float $totalPrice, int $modelId, Payment $payment, string $url): Model|PaymentProcess
	{
		$session = Session::create([
			'payment_method_types' => $types,
			'currency' => Str::lower(data_get($before, 'currency')),
			'line_items' => [
				[
					'price_data' => [
						'currency' => Str::lower(data_get($before, 'currency')),
						'product_data' => [
							'name' => 'Payment'
						],
						'unit_amount' => $totalPrice,
					],
					'quantity' => 1,
				]
			],
			'mode'        => 'payment',
			'success_url' => $url,
			'cancel_url'  => $url,
		]);

		$paymentProcess = PaymentProcess::create([
			'user_id'    => auth('sanctum')->id(),
			'model_id'   => $modelId,
			'model_type' => data_get($before, 'model_type'),
			'id' 		 => $session->payment_intent ?? $session->id,
			'data' 		 => array_merge([
				'url'        => $session->url,
				'payment_id' => $payment->id,
				'split'		 => $data['split'] ?? 1
			], $before)
		]);

		$paymentProcess->id = $session->id;

		return $paymentProcess;
	}

	/**
	 * @param array $data
	 * @param Shop|null $shop
	 * @param $currency
	 * @param array $types
	 * @return Model|array|PaymentProcess
	 * @throws ApiErrorException
	 */
    public function subscriptionProcessTransaction(array $data, ?Shop $shop, $currency, array $types = ['card']): Model|array|PaymentProcess
    {
        $payment = Payment::where('tag', Payment::TAG_STRIPE)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();

        Stripe::setApiKey(data_get($paymentPayload?->payload, 'stripe_sk'));

        $host           = request()->getSchemeAndHttpHost();
        $subscription   = Subscription::find(data_get($data, 'subscription_id'));
		$currency	    = Str::lower(data_get($paymentPayload?->payload, 'currency', $currency));
		$url 			= "$host/subscription-stripe-success?token={CHECKOUT_SESSION_ID}&subscription_id=$subscription->id";

		$totalPrice = round($subscription->price * 100, 2);

		if (@$data['type'] === 'mobile') {

			$session = PaymentIntent::create([
				'payment_method_types' => $types,
				'currency' => $currency,
				'amount' => $totalPrice,
			]);

			return PaymentProcess::updateOrCreate([
				'user_id'    => auth('sanctum')->id(),
				'model_id'   => $subscription->id,
				'model_type' => get_class($subscription)
			], [
				'id' => $session->id,
				'data' => [
					'client_secret'   => $session->client_secret,
					'price'           => $totalPrice / 100,
					'type'            => 'mobile',
					'shop_id'         => $shop?->id,
					'subscription_id' => $subscription->id,
				]
			]);

		}

        $session = Session::create([
            'payment_method_types' => $types,
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => Str::lower(data_get($paymentPayload?->payload, 'currency', $currency)),
                        'product_data' => [
                            'name' => 'Payment'
                        ],
                        'unit_amount' => $totalPrice,
                    ],
                    'quantity' => 1,
                ]
            ],
            'mode' => 'payment',
            'success_url' => $url,
            'cancel_url'  => $url,
        ]);

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
			'model_id'   => $subscription->id,
			'model_type' => get_class($subscription)
        ], [
            'id' => $session->payment_intent ?? $session->id,
            'data' => [
                'price'           => $totalPrice,
                'shop_id'         => $shop?->id,
				'url'      		  => $session->url,
				'subscription_id' => $subscription->id
			]
        ]);
    }

}
