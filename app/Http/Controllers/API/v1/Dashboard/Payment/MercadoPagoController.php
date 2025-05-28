<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StripeRequest;
use App\Http\Requests\Shop\SubscriptionRequest;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\PaymentProcess;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\PaymentService\MercadoPagoService;
use App\Traits\ApiResponse;
use App\Traits\OnResponse;
use Exception;
use Http;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Log;
use Redirect;
use Throwable;

class MercadoPagoController extends Controller
{
    use OnResponse, ApiResponse;

    public function __construct(private MercadoPagoService $service)
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
                'code'    => ResponseError::ERROR_501,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * process transaction.
     *
     * @param SubscriptionRequest $request
     * @return JsonResponse
     */
    public function subscriptionProcessTransaction(SubscriptionRequest $request): JsonResponse
    {
        $shop     = auth('sanctum')->user()?->shop ?? auth('sanctum')->user()?->moderatorShop;
        $currency = Currency::currenciesList()->where('active', 1)->where('default', 1)->first()?->title;

        if (empty($shop)) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::SHOP_NOT_FOUND, locale: $this->language)
            ]);
        }

        if (empty($currency)) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::CURRENCY_NOT_FOUND)
            ]);
        }

        try {
            $result = $this->service->subscriptionProcessTransaction($request->all(), $shop, $currency);

            return $this->successResponse('success', $result);
        } catch (Throwable $e) {
            $this->error($e);
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_501,
                'message' => __('errors.' . ResponseError::ERROR_501)
            ]);
        }

    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function orderResultTransaction(Request $request): RedirectResponse
    {
		$cartId   = (int)$request->input('cart_id');
		$parcelId = (int)$request->input('parcel_id');

		$to = config('app.front_url') . ($cartId ? '/' : "parcels/$parcelId");

		return Redirect::to($to);
    }

    /**
     * @param Request $request
     * @return RedirectResponse
     */
    public function subscriptionResultTransaction(Request $request): RedirectResponse
    {
        $subscription = Subscription::find((int)$request->input('subscription_id'));

        $to = config('app.front_url') . "seller/subscriptions/$subscription->id";

        return Redirect::to($to);
    }

    /**
     * @param Request $request
     * @return void
     */
    public function paymentWebHook(Request $request): void
    {
        Log::error('mercado pago', [
            'all'   => $request->all(),
            'input' => @file_get_contents("php://input")
        ]);
        if ($request->input('data.id')) {
            $id = $request->input('data.id');


            $payment = Payment::where('tag',Payment::TAG_MERCADO_PAGO)->first();

            $payload = $payment->paymentPayload?->payload;

            $headers = [
                'Authorization' => 'Bearer '.data_get($payload,'token')
            ];

            $response = Http::withHeaders($headers)->get('https://api.mercadopago.com/v1/payments/'.$id);

            if ($response->status() == 200) {

                $token = $response->json('additional_info.items.0.id');

                $status = match ($response->json('status')) {
                    'succeeded', 'successful', 'success', 'approved'                        => Transaction::STATUS_PAID,
                    'failed', 'cancelled', 'reversed', 'chargeback', 'disputed', 'rejected' => Transaction::STATUS_CANCELED,
                    default                                                                 => Transaction::STATUS_PROGRESS,
                };

                $this->service->afterHook($token, $status);

            }
        }

    }

}
