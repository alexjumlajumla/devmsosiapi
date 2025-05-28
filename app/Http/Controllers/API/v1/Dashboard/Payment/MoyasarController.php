<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StripeRequest;
use App\Http\Requests\Shop\SubscriptionRequest;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\PaymentPayload;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\PaymentService\MoyasarService;
use App\Traits\ApiResponse;
use App\Traits\OnResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Log;
use Redirect;
use Throwable;

class MoyasarController extends Controller
{
    use OnResponse, ApiResponse;

    public function __construct(private MoyasarService $service)
    {
        parent::__construct();
    }

    /**
     * process transaction.
     *
     * @param StripeRequest $request
     * @return JsonResponse
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

        $to = config('app.admin_url') . "seller/subscriptions/$subscription->id";

        return Redirect::to($to);
    }

    /**
     * @param Request $request
     * @return void
     */
    public function paymentWebHook(Request $request): void
    {
        $payload = PaymentPayload::where('tag', Payment::TAG_MOYA_SAR)->first()?->payload;

        if (data_get($payload, 'secret_token') !== $request->input('secret_token')) {
            Log::error('secret_token', $request->all());
            return;
        }

        $status = $request->input('data.status');

        $status = match ($status) {
            'paid', 'captured'      => Transaction::STATUS_PAID,
            'failed'                => Transaction::STATUS_CANCELED,
            'refunded', 'voided'    => Transaction::STATUS_REFUND,
            default                 => 'progress',
        };

        $token = $request->input('data.invoice_id');

        Log::error('paymentWebHook', $request->all());

        $this->service->afterHook($token, $status);
    }
}
