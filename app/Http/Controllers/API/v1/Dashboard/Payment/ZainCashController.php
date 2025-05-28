<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Http\Requests\Payment\StripeRequest;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\PaymentService\ZainCashService;
use Exception;
use Firebase\JWT\JWT;
use Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Log;
use Throwable;

class ZainCashController extends PaymentBaseController
{
    public function __construct(private ZainCashService $service)
    {
        parent::__construct();
    }

    /**
     * process transaction.
     *
     * @param StripeRequest $request
     * @return JsonResponse
     * @throws Exception
     */
    public function orderProcessTransaction(StripeRequest $request): JsonResponse
    {
        try {
            $result = $this->service->orderProcessTransaction($request->all());

            return $this->successResponse('success', $result);
        } catch (Throwable $e) {
            $this->error($e);
            return $this->onErrorResponse([
                'message' => $e->getMessage() . $e->getFile() . $e->getLine(),
            ]);
        }

    }

    /**
     * @param Request $request
     * @return void
     */
    public function paymentWebHook(Request $request): void
    {
        Log::error('$request', $request->all());

        /** @var Payment $payment */
        $payment = Payment::with(['paymentPayload'])
            ->where('tag', Payment::TAG_ZAIN_CASH)
            ->first();

        $payload = $payment?->paymentPayload?->payload ?? [];

        $id   = $request->input('id');
        $time = time();
        $data = [
            'id'      => $id,
            'msisdn'  => $payload['msisdn'],
            'iat'     => $time,
            'exp'     => $time + 60 * 60 * 4
        ];

        $newToken = JWT::encode($data, $payload['key'],'HS256');

        $rUrl = ($payload['url'] ?? 'https://test.zaincash.iq') . '/transaction/get';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json'
        ])
            ->post($rUrl, [
                'token'      => $newToken,
                'merchantId' => $payload['merchantId'],
                'lang'       => $this->language
            ]);

        $status = match (data_get($response, 'data.0.payment_status')) {
            'succeeded', 'paid'			 => Transaction::STATUS_PAID,
            'payment_failed', 'canceled' => Transaction::STATUS_CANCELED,
            default				  		 => 'progress',
        };

        $this->service->afterHook($newToken, $status);
    }

}
