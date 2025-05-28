<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\SplitRequest;
use App\Http\Requests\Payment\StripeRequest;
use App\Http\Requests\Shop\SubscriptionRequest;
use App\Models\Currency;
use App\Models\Transaction;
use App\Services\PaymentService\PayFastService;
use App\Traits\ApiResponse;
use App\Traits\OnResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayFastController extends Controller
{
	use OnResponse, ApiResponse;

	public function __construct(private PayFastService $service)
	{
		parent::__construct();
	}

	/**
	 * process transaction.
	 *
	 * @param StripeRequest $request
	 * @return Response|JsonResponse
	 */
	public function orderProcessTransaction(StripeRequest $request): JsonResponse|Response
	{
		try {
			$result = $this->service->orderProcessTransaction($request->all());

			$result->id = (string)$result->id;

			return $this->successResponse('success', $result);
		} catch (Throwable $e) {
			$this->error($e);
			return $this->onErrorResponse(['code' => ResponseError::ERROR_400, 'message' => $e->getMessage() . $e->getFile() . $e->getLine()]);
		}
	}

	/**
	 * process transaction.
	 *
	 * @param SplitRequest $request
	 * @return JsonResponse
	 */
	public function splitTransaction(SplitRequest $request): JsonResponse
	{
		try {
			$result = $this->service->splitTransaction($request->all());
			$result->id = (string)$result->id;

			return $this->successResponse('success', $result);
		} catch (Throwable $e) {
			$this->error($e);
			return $this->onErrorResponse([
				'message' => $e->getMessage(),
				'param'   => $e->getFile() . $e->getLine()
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
		$shop = auth('sanctum')->user()?->shop ?? auth('sanctum')->user()?->moderatorShop;

		$currency = Currency::currenciesList()->where('active', 1)->where('default', 1)->first()?->title;

		if (empty($shop)) {
			return $this->onErrorResponse([
				'message' => __('errors.' . ResponseError::SHOP_NOT_FOUND, locale: $this->language)
			]);
		}

		if (empty($currency)) {
			return $this->onErrorResponse([
				'message' => __('errors.' . ResponseError::CURRENCY_NOT_FOUND, locale: $this->language)
			]);
		}

		try {
			$result = $this->service->subscriptionProcessTransaction($request->all(), $shop);

			return $this->successResponse('success', $result);
		} catch (Throwable $e) {
			$this->error($e);
			return $this->onErrorResponse([
				'message' => __('errors.' . ResponseError::ERROR_501, locale: $this->language)
			]);
		}

	}

	/**
	 * @param Request $request
	 * @return void
	 */
	public function paymentWebHook(Request $request): void
	{
		Log::error('payfast', $request->all());

		$token = $request->input('m_payment_id');

        Log::error('token', [$token]);

        if (empty($token)) {
            $token = $request->input('payment_id');
        }

		$status = match ($request->input('payment_status')) {
			'COMPLETE' => Transaction::STATUS_PAID,
			'CANCELED' => Transaction::STATUS_CANCELED,
			default	   => 'progress',
		};

		$this->service->afterHook($token, $status);
	}
}
