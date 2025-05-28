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
use Matscode\Paystack\Transaction;
use Str;
use Throwable;

class PayStackService extends BaseService
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
        $payment = Payment::where('tag', Payment::TAG_PAY_STACK)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $transaction    = new Transaction(data_get($payload, 'paystack_sk'));

		[$key, $before] = $this->getPayload($data, $payload);
		$modelId 		= data_get($before, 'model_id');

		$totalPrice = round((float)data_get($before, 'total_price') * 100, 2);

        $host = request()->getSchemeAndHttpHost();
        $url  = "$host/order-stripe-success?$key=$modelId";

        $body = [
            'email'    => auth('sanctum')->user()?->email ?? Str::random(16) . '@gmail.com',
            'amount'   => $totalPrice,
            'currency' => Str::upper(data_get($before, 'currency')),
        ];

        $response = $transaction->setCallbackUrl($url)->initialize($body);

        if (isset($response?->status) && !data_get($response, 'status')) {
            throw new Exception(data_get($response, 'message', 'PayStack server error'));
        }

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $modelId,
            'model_type' => data_get($before, 'model_type')
        ], [
            'id' => data_get($response, 'reference'),
            'data' => array_merge([
                'url'   	 => data_get($response, 'authorizationUrl'),
                'price' 	 => $totalPrice,
                'payment_id' => $payment->id,
            ], $before),
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

			$transaction    = new Transaction(data_get($payload, 'paystack_sk'));

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

				$body = [
					'email'    => auth('sanctum')->user()?->email,
					'amount'   => $totalPrice,
					'currency' => Str::upper(data_get($before, 'currency')),
				];

				$response = $transaction->setCallbackUrl($url)->initialize($body);

				if (isset($response?->status) && !data_get($response, 'status')) {
					throw new Exception(data_get($response, 'message', 'PayStack server error'));
				}

				$paymentProcess = PaymentProcess::create([
					'user_id'    => auth('sanctum')->id(),
					'model_id'   => $modelId,
					'model_type' => data_get($before, 'model_type'),
					'id' 		 => data_get($response, 'reference'),
					'data' 		 => array_merge([
                        'url'   	 => data_get($response, 'authorizationUrl'),
                        'price' 	 => $totalPrice,
                        'payment_id' => $payment->id,
                    ], $before)
				]);

				$paymentProcess->id = data_get($response, 'reference');

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
        $payment = Payment::where('tag', Payment::TAG_PAY_STACK)->first();

        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;

        $host           = request()->getSchemeAndHttpHost();
        $subscription   = Subscription::find(data_get($data, 'subscription_id'));

        $transaction    = new Transaction(data_get($payload, 'paystack_sk'));
        $totalPrice     = ceil($subscription->price) * 100;

        $data = [
            'email'     => $shop->seller?->email,
            'amount'    => $totalPrice,
            'currency'  => Str::upper($currency ?? data_get($payload, 'currency'))
        ];

        $response = $transaction
            ->setCallbackUrl("$host/subscription-paystack-success?subscription_id=$subscription->id")
            ->initialize($data);

        if (isset($response?->status) && !data_get($response, 'status')) {
            throw new Exception(data_get($response, 'message', 'PayStack server error'));
        }

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
			'model_id'   => $subscription->id,
			'model_type' => get_class($subscription)
        ], [
            'id' => data_get($response, 'reference'),
            'data' => [
                'url'             => data_get($response, 'authorizationUrl'),
                'price'           => $totalPrice,
                'shop_id'         => $shop->id,
                'subscription_id' => $subscription->id,
				'payment_id' 	  => $payment->id,
            ]
        ]);
    }
}
