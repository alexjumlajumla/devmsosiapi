<?php

namespace App\Services\PaymentService;

use DB;
use Http;
use Exception;
use App\Models\Shop;
use App\Models\User;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Payment;
use Illuminate\Support\Str;
use App\Models\Transaction;
use App\Models\Subscription;
use App\Models\PaymentPayload;
use App\Models\PaymentProcess;

class PayFastService extends BaseService
{
	protected function getModelClass(): string
	{
		return Payout::class;
	}

	/**
	 * @param array $data
	 * @return PaymentProcess|array
	 * @throws Exception
	 */
	public function orderProcessTransaction(array $data): PaymentProcess|array
	{
		$payment = Payment::where('tag', Payment::TAG_PAY_FAST)->first();

		$paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
		$payload        = $paymentPayload?->payload ?? [];
		[$key, $before] = $this->getPayload($data, $payload ?? []);
		$modelId 		= data_get($before, 'model_id');

		$host = request()->getSchemeAndHttpHost();
		$url  = "$host/order-stripe-success?$key=$modelId";
		$totalPrice = round((float)data_get($before, 'total_price'), 2);
		$uuid = Str::uuid();

		$notifyUrl = "$host/api/v1/webhook/pay-fast/payment?payment_id=$uuid";

		/** @var User $user */
		$user = auth('sanctum')->user();

		$body = [
			'merchant_id' 	=> (int)$payload['merchant_id'],
			'merchant_key' 	=> $payload['merchant_key'],
			'return_url' 	=> $url,
			'cancel_url' 	=> $url,
			'notify_url' 	=> $notifyUrl,
			'amount' 		=> $totalPrice,
			'name_first' 	=> $user?->firstname ?? 'First Name',
			'name_last'  	=> $user?->lastname  ?? 'Last Name',
			'item_name' 	=> Str::replace('_id', '', Str::ucfirst($key)),
			'email_address' => $user->email ?? Str::random() . '@gmail.com',
		];

		if (data_get($data, 'type') === 'mobile') {

			unset($body['merchant_id']);
			unset($body['merchant_key']);

			return PaymentProcess::updateOrCreate([
				'user_id'    => auth('sanctum')->id(),
				'model_id'   => $modelId,
				'model_type' => data_get($before, 'model_type')
			], [
				'id' => $uuid,
				'data' => array_merge([
					'price' 	 => $totalPrice,
					'payment_id' => $payment?->id,
					'sandbox'	 => $payload['sandbox'],
				], $before, $body),
			]);

		}

		$body['signature'] = $this->generateSignature($body, $payload['pass_phrase']);

		$response = $this->generatePaymentIdentifier($body, $payload);

		if (!isset($response['uuid'])) {
			throw new Exception('error pay fast');
		}

		return PaymentProcess::updateOrCreate([
			'user_id'    => auth('sanctum')->id(),
			'model_id'   => $modelId,
			'model_type' => data_get($before, 'model_type')
		], [
			'id' => $uuid,
			'data' => array_merge([
				'price' 	 => $totalPrice,
				'payment_id' => $payment?->id,
				'sandbox'	 => $payload['sandbox'],
				'signature'  => $body["signature"],
			], $before, $response),
		]);
	}

	/**
	 * @param array $data
	 * @return PaymentProcess|array
	 * @throws Exception
	 */
	public function splitTransaction(array $data): PaymentProcess|array
	{
		$payment = Payment::where('tag', Payment::TAG_PAY_FAST)->first();

		$paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
		$payload        = $paymentPayload?->payload ?? [];
		[$key, $before] = $this->getPayload($data, $payload ?? []);
		$modelId 		= data_get($before, 'model_id');
		$modelType 	    = data_get($before, 'model_type');
		$modelClass     = Str::replace('App\\Models\\', '', $modelType);
		$host 			= request()->getSchemeAndHttpHost();
		$url  			= "$host/order-stripe-success?$key=$modelId";
		$totalPrice 	= round((float)data_get($before, 'total_price'), 2);
		$result			= [];
		$split 			= $data['split'] ?? 1;
		$uuid 			= Str::uuid();
		$notifyUrl 		= "$host/api/v1/webhook/pay-fast/payment?payment_id=$uuid";

		/** @var User $user */
		$user = auth('sanctum')->user();

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

			$body = [
				'merchant_id' 	=> (int)($payload['merchant_id']),
				'merchant_key' 	=> $payload['merchant_key'],
				'return_url' 	=> $url,
				'cancel_url' 	=> $url,
				'notify_url' 	=> $notifyUrl,
				'amount' 		=> $totalPrice,
				'name_first' 	=> $user?->firstname ?? 'First Name',
				'name_last'  	=> $user?->lastname  ?? 'Last Name',
				'item_name' 	=> "$key#$modelId",
				'email_address' => $user->email ?? Str::random() . '@gmail.com',
			];

			$body['signature'] = $this->generateSignature($body, $payload['pass_phrase']);

			if (data_get($data, 'type') === 'mobile') {

				$result[] = PaymentProcess::updateOrCreate([
					'user_id'    => auth('sanctum')->id(),
					'model_id'   => $modelId,
					'model_type' => data_get($before, 'model_type')
				], [
					'id' => $uuid,
					'data' => array_merge([
						'price' 	 => $totalPrice,
						'payment_id' => $payment?->id,
						'sandbox'	 => $payload['sandbox'],
						'signature'  => $body['signature'],
					], $before, $body),
				]);
			}

			$response = $this->generatePaymentIdentifier($body, $payload);

			if (!isset($response['uuid'])) {
				throw new Exception('error pay fast');
			}

			$result[] = PaymentProcess::updateOrCreate([
				'user_id'    => auth('sanctum')->id(),
				'model_id'   => $modelId,
				'model_type' => data_get($before, 'model_type')
			], [
				'id' => $uuid,
				'data' => array_merge([
					'price' 	 => $totalPrice,
					'payment_id' => $payment?->id,
					'sandbox'	 => $payload['sandbox'],
					'signature'  => $body["signature"],
				], $before, $response),
			]);

		}

		return $result;
	}

	/**
	 * @param array $data
	 * @param Shop $shop
	 * @return PaymentProcess
	 * @throws Exception
	 */
	public function subscriptionProcessTransaction(array $data, Shop $shop): PaymentProcess
	{
		$payment = Payment::where('tag', Payment::TAG_STRIPE)->first();

		$paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
		$payload        = $paymentPayload?->payload;

		$subscription = Subscription::find(data_get($data, 'subscription_id'));

		$host = request()->getSchemeAndHttpHost();
		$key  = data_get($data, 'subscription_id');
		$url  = "$host/subscription-pay-fast?$key=$subscription->id";
		$uuid = Str::uuid();
		$notifyUrl = "$host/api/v1/webhook/pay-fast/payment?payment_id=$uuid";

		/** @var User $user */
		$user = auth('sanctum')->user();

		$body = [
			'merchant_id' 	=> (int)($payload['merchant_id'] ?? 10000100),
			'merchant_key' 	=> $payload['merchant_key'] ?? '46f0cd694581a',
			'return_url' 	=> $url,
			'cancel_url' 	=> $url,
			'notify_url' 	=> $notifyUrl,
			'amount' 	    => ceil($subscription->price),
			'name_first' 	=> $user?->firstname ?? 'First Name',
			'name_last'  	=> $user?->lastname  ?? 'Last Name',
			'item_name' 	=> "Subscription#$subscription->id",
			'email_address' => $user->email ?? Str::random() . '@gmail.com',
		];

		$body['signature'] = $this->generateSignature($body, $payload['pass_phrase']);

		$response = $this->generatePaymentIdentifier($body, $payload);

		if (!isset($response['uuid'])) {
			throw new Exception('error pay fast');
		}

		return PaymentProcess::updateOrCreate([
			'user_id'    => auth('sanctum')->id(),
			'model_id'   => $subscription->id,
			'model_type' => get_class($subscription)
		], [
			'id' => $uuid,
			'data' => array_merge([
				'shop_id' 		  => $shop->id,
				'url'     		  => $notifyUrl,
				'price'   		  => ceil($subscription->price),
				'subscription_id' => $subscription->id,
				'payment_id' 	  => $payment->id,
				'sandbox'		  => $payload['sandbox'],
				'signature' 	  => $body["signature"],
			], $response),
		]);
	}

	/**
	 * @param array $data
	 * @param string|null $passPhrase
	 * @return string
	 */
	private function generateSignature(array $data, ?string $passPhrase = null): string
	{
		if ($passPhrase !== null) {
			$data['passphrase'] = $passPhrase;
		}

		// Sort the array by key, alphabetically
		ksort($data);

		//create parameter string
		$pfParamString = http_build_query($data);

		return md5($pfParamString);
	}

	public function generatePaymentIdentifier(array $body, array $payload): array|string
	{
		$url = 'www.payfast.co.za';

		if ($payload['sandbox']) {
			$url = 'sandbox.payfast.co.za';
		}

		$request = Http::post("https://$url/onsite/process", $body);

		return $request->json();
	}

}
