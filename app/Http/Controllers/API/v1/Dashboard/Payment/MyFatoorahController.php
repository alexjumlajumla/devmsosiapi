<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\SplitRequest;
use App\Http\Requests\Payment\StripeRequest;
use App\Http\Requests\Shop\SubscriptionRequest;
use App\Models\Currency;
use App\Models\Transaction;
use App\Services\PaymentService\MyFatoorahService;
use App\Traits\ApiResponse;
use App\Traits\OnResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Redirect;
use Throwable;

class MyFatoorahController extends Controller
{
	use OnResponse, ApiResponse;

	public function __construct(private MyFatoorahService $service)
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

			if (empty($request->input('invoice_id'))) {
				return $this->onErrorResponse([
					'code' 	  => ResponseError::ERROR_400,
					'message' => 'invoice id required'
				]);
			}

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
	 * @param SplitRequest $request
	 * @return JsonResponse
	 */
	public function splitTransaction(SplitRequest $request): JsonResponse
	{
		try {
			$result = $this->service->splitTransaction($request->all());

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

		if (empty($request->input('invoice_id'))) {
			return $this->onErrorResponse([
				'code' 	  => ResponseError::ERROR_400,
				'message' => 'invoice id required'
			]);
		}

		$currency = Currency::currenciesList()->where('active', 1)->where('default', 1)->first()?->title;

		if (empty($currency)) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_404,
				'message' => __('errors.' . ResponseError::CURRENCY_NOT_FOUND)
			]);
		}

		try {
			$shop = auth('sanctum')->user()?->shop ?? auth('sanctum')->user()?->moderatorShop;

			$result = $this->service->subscriptionProcessTransaction($request->all(), $shop);

			return $this->successResponse('success', $result);
		} catch (Throwable $e) {
			$this->error($e);
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_501,
				'message' => $e->getMessage() . $e->getFile() . $e->getLine()
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
	 * @return RedirectResponse
	 */
    public function subscriptionResultTransaction(): RedirectResponse
    {
        return Redirect::to(config('app.front_url'));
	}

	/**
	 * @param Request $request
	 * @return void
	 */
	/**
	 * @param Request $request
	 * @return void
	 */
	public function paymentWebHook(Request $request): void
	{
		$token = $request->input('data.object.id');

		$status = match ($request->input('data.object.status')) {
			'succeeded', 'paid' => Transaction::STATUS_PAID,
			'payment_failed', 'canceled' => Transaction::STATUS_CANCELED,
			default => 'progress',
		};

        $this->service->afterHook($token, $status);
	}

}
