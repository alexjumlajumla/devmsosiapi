<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\SplitRequest;
use App\Http\Requests\Payment\StripeRequest;
use App\Http\Requests\Shop\SubscriptionRequest;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Settings;
use App\Models\WalletHistory;
use App\Services\PaymentService\PayPalService;
use App\Traits\ApiResponse;
use App\Traits\OnResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Redirect;
use Throwable;

class PayPalController extends Controller
{
	use OnResponse, ApiResponse;

	public function __construct(private PayPalService $service)
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
			$result = $this->service->processTransaction($request->all());

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
		$currency = Currency::currenciesList()->where('active', 1)->where('default', 1)->first()?->title;

		if (empty($currency)) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_404,
				'message' => __('errors.' . ResponseError::CURRENCY_NOT_FOUND)
			]);
		}

		try {
			$shop = auth('sanctum')->user()?->shop ?? auth('sanctum')->user()?->moderatorShop;

			$result = $this->service->subscriptionProcessTransaction($request->all(), $shop, $currency);

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
		$parcelId 		= (int)$request->input('parcel_id');
		$orderId 		= (int)$request->input('order_id');
		$subscriptionId = (int)$request->input('subscription_id');

		$to = config('app.front_url');

		if ($parcelId) {
			$to = "parcels/$parcelId";
		} elseif($subscriptionId) {
			$to = config('app.admin_url');
		} elseif($orderId) {

			/** @var Order $order */
			$order = Order::with('table')->find($orderId);

			if (!empty($order->table_id)) {

				$qrUrl  = rtrim(Settings::where('key', 'qrcode_base_url')->value('value'), '/');
				$qrType = rtrim(Settings::where('key', 'qrcode_type')->value('value') ?? 'w2');

				$to = "$qrUrl/$qrType?shop_id=$order->shop_id&table_id=$order->table_id&chair_count={$order->table?->chair_count}&name={$order->table?->name}&redirect_from=stripe#recommended";
			}

		}

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
	public function paymentWebHook(Request $request): void
	{
		$status = $request->input('resource.status');

		$status = match ($status) {
			'APPROVED', 'COMPLETED', 'CAPTURED' => WalletHistory::PAID,
			'VOIDED'                            => WalletHistory::CANCELED,
			default                             => 'progress',
		};

		$token = $request->input('resource.id');

		$this->service->afterHook($token, $status);

	}

}
