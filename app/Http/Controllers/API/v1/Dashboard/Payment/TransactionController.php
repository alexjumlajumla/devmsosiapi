<?php

namespace App\Http\Controllers\API\v1\Dashboard\Payment;

use App\Helpers\ResponseError;
use App\Http\Requests\Payment\TransactionRequest;
use App\Http\Requests\Payment\TransactionUpdateRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ParcelOrderResource;
use App\Http\Resources\ShopAdsPackageResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\WalletResource;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\ParcelOrder;
use App\Models\ShopAdsPackage;
use App\Models\ShopSubscription;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\PaymentService\PayPalService;
use App\Services\TransactionService\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Log;

class TransactionController extends PaymentBaseController
{
    public function store(string $type, int $id, TransactionRequest $request): JsonResponse
    {
        if ($type === 'order') {

            $result = (new TransactionService)->orderTransaction($id, $request->validated());

            if (!data_get($result, 'status')) {
                return $this->onErrorResponse($result);
            }

            return $this->successResponse(
                __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
                OrderResource::make(data_get($result, 'data'))
            );

        } else if ($type === 'parcel-order') {

            $result = (new TransactionService)->orderTransaction($id, $request->validated(), ParcelOrder::class);

            if (!data_get($result, 'status')) {
                return $this->onErrorResponse($result);
            }

            return $this->successResponse(
                __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
                ParcelOrderResource::make(data_get($result, 'data'))
            );

        } else if ($type === 'subscription') {

            $result = (new TransactionService)->subscriptionTransaction($id, $request->validated());

            if (!data_get($result, 'status')) {
                return $this->onErrorResponse($result);
            }

            return $this->successResponse(
                __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
				SubscriptionResource::make(data_get($result, 'data'))
            );

        } else if ($type === 'ads') {

            $result = (new TransactionService)->adsTransaction($id);

            if (!data_get($result, 'status')) {
                return $this->onErrorResponse($result);
            }

            return $this->successResponse(
                __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
                ShopAdsPackageResource::make(data_get($result, 'data'))
            );

        }

        $result = (new TransactionService)->walletTransaction($id, $request->validated());

        if (!data_get($result, 'status')) {
            return $this->onErrorResponse($result);
        }

        return $this->successResponse(
            __('errors.' . ResponseError::RECORD_WAS_SUCCESSFULLY_CREATED, locale: $this->language),
            WalletResource::make(data_get($result, 'data'))
        );
    }

    public function updateStatus(string $type, int $id, TransactionUpdateRequest $request): JsonResponse
    {
		/** @var Order $model */
		$model = match($type) {
			'parcel-order'  	 => ParcelOrder::with('transaction')->find($id),
			'subscription'  	 => ShopSubscription::with('transaction')->find($id),
			'ads-package', 'ads' => ShopAdsPackage::with('transaction')->find($id),
			'wallet'        	 => Wallet::with(['transaction' => fn($q) => $q->orderBy('id', 'desc')])->find($id),
			default         	 => Order::with('transaction')->find($id),
		};

		if (!$model) {
			return $this->onErrorResponse(['code' => ResponseError::ERROR_404]);
		}

		if (!$model->transaction) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_501,
				'message' => __('errors.' . ResponseError::ERROR_501, locale: $this->language)
			]);
		}

		/** @var Transaction $transaction */
		$model->transaction()
			->where('id', $request->input('transaction_id'))
			->update([
				'status' => $request->input('status')
			]);

		if ($request->input('status') === Transaction::STATUS_PAID) {
			$model->orderDetails()
				->whereIn('transaction_status', [OrderDetail::TRANSACTION_STATUS_WAITING, OrderDetail::TRANSACTION_STATUS_PROGRESS])
				->update([
					'transaction_status' => OrderDetail::TRANSACTION_STATUS_PAID
				]);
		}

		return $this->successResponse(
			__('errors.' . ResponseError::NO_ERROR, locale: $this->language),
			$model->fresh('transaction')
		);
    }

    public function created(Request $request) {
        Log::error('created', $request->all());

        $transaction = Transaction::find($request->input('id'));

        if (!empty($transaction)) {
            (new PayPalService)->updateOrderStatus($request->all(), $transaction);
        }

    }
}
