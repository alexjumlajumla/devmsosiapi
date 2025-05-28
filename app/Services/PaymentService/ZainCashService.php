<?php
declare(strict_types=1);

namespace App\Services\PaymentService;

use App\Models\Cart;
use App\Models\Order;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\PaymentProcess;
use App\Models\Payout;
use Exception;
use Firebase\JWT\JWT;
use Http;

class ZainCashService extends BaseService
{
    protected function getModelClass(): string
    {
        return Payout::class;
    }

    /**
     * @param array $data
     * @return PaymentProcess
     * @throws Exception
     */
    public function orderProcessTransaction(array $data): PaymentProcess
    {
        /** @var Payment $payment */
        $payment = Payment::with([
            'paymentPayload'
        ])
            ->where('tag', Payment::TAG_ZAIN_CASH)
            ->first();

        $payload = $payment?->paymentPayload?->payload ?? [];

        /** @var Order|ParcelOrder $order */
        $order = data_get($data, 'parcel_id')
			? ParcelOrder::find(data_get($data, 'parcel_id'))
			: Cart::find(data_get($data, 'cart_id'));

		$totalPrice = ceil($order->rate_total_price * 2 * 100) / 2;

        $host = request()->getSchemeAndHttpHost();

        $key  = data_get($data, 'parcel_id') ? 'parcel_id' : 'cart_id';

        $time = time();

        $data = [
            'amount'      => $totalPrice,
            'serviceType' => 'Order',
            'msisdn'      => $payload['msisdn'],
            'orderId'     => $order->id,
            'redirectUrl' => "$host/payment-success?$key=$order->id&lang=$this->language",
            'iat'         => $time,
            'exp'         => $time + 60 * 60 * 4
        ];

        $newToken = JWT::encode($data, $payload['key'],'HS256');

        $init = Http::withHeaders([
            'Content-Type' => 'application/json'
        ])
            ->post(($payload['url'] ?? 'https://test.zaincash.iq') . '/transaction/init', [
                'token'      => $newToken,
                'merchantId' => $payload['merchantId'],
                'lang'       => $this->language
            ]);

        $errorMessage = $init->json('err.msg');

        if (!empty($errorMessage)) {
            throw new Exception($errorMessage, $init->status());
        }

        $init = $init->json();

        $transactionId = $init['id'];

        $newUrl = ($payload['url'] ?? 'https://test.zaincash.iq') . "/transaction/pay?id=$transactionId";

        return PaymentProcess::updateOrCreate([
            'user_id'    => auth('sanctum')->id(),
            'model_id'   => $order->id,
            'model_type' => get_class($order)
        ], [
            'id'   => $transactionId,
            'data' => [
				'url' 		 => $newUrl,
				'payment_id' => $payment?->id,
				'price' 	 => $totalPrice,
			]
        ]);
    }

}
