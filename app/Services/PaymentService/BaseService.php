<?php

namespace App\Services\PaymentService;

use App\Helpers\NotificationHelper;
use App\Helpers\ResponseError;
use App\Models\Cart;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\ParcelOrder;
use App\Models\Payment;
use App\Models\PaymentProcess;
use App\Models\Payout;
use App\Models\Settings;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletHistory;
use App\Repositories\CartRepository\CartRepository;
use App\Repositories\OrderRepository\OrderRepository;
use App\Services\CoreService;
use App\Services\OrderService\OrderService;
use App\Services\SubscriptionService\SubscriptionService;
use App\Services\WalletHistoryService\WalletHistoryService;
use App\Traits\Notification;
use Exception;
use Illuminate\Support\Str;
use Throwable;

class BaseService extends CoreService
{
	use Notification;

	protected function getModelClass(): string
	{
		return Payout::class;
	}

    /**
     * @param $token
     * @param $status
     * @param $token2
     * @return void
     */
    public function afterHook($token, $status, $token2 = null): void
	//public function afterHook($token, $status, $token2 = null)
    {
		
		$paymentProcess = PaymentProcess::where('id', $token)->first();

		if(empty($paymentProcess)) {
			$paymentProcess = PaymentProcess::where('id', $token2)->first();
		}

		if(empty($paymentProcess)) {
			return;
		}

        if ($paymentProcess->model_type !== Order::KIOSK) {
            $paymentProcess->fresh(['model']);
        }

		/** @var PaymentProcess $paymentProcess */
		$paymentId = $paymentProcess->data['payment_id'] ?? Payment::first()?->id;

		if ($paymentProcess->model_type === Subscription::class) {

			$subscription = $paymentProcess->model;

			$shop = Shop::find(data_get($paymentProcess->data, 'shop_id'));

			$shopSubscription = (new SubscriptionService)->subscriptionAttach(
				$subscription,
				(int)$shop?->id,
				$status === 'paid' ? 1 : 0
			);

			$shopSubscription->createTransaction([
				'price'              => $shopSubscription->price,
				'user_id'            => $shop?->user_id,
				'payment_sys_id'     => $paymentId,
				'payment_trx_id'     => $token,
				'note'               => $shopSubscription->id,
				'perform_time'       => now(),
				'status_description' => "Transaction for model #$shopSubscription->id",
				'status'             => $status,
			]);

            $paymentProcess->delete();
			return;
		}
        
		
		if (
            ($paymentProcess->model_type === Cart::class || $paymentProcess->model_type === Order::KIOSK)
            && $status === Transaction::STATUS_PAID
        ) {
			
			try {
				$cart = data_get($paymentProcess->data, 'cart');
			
				if ($status !== Transaction::STATUS_PAID) {
            return; // Prevent order creation if payment is not successful
        }
				
			
				$result = (new OrderService)->create($cart);
				
				//return $result;
				/** @var Order $order */
				$order = $result['data'];

				$this->newOrderNotification($order);

				$order?->createTransaction([
					'price'              => $order->total_price,
					'user_id'            => $order->user_id,
					'payment_sys_id'     => $paymentId,
					'payment_trx_id'     => $token,
					'note'               => $order->id,
					'perform_time'       => now(),
					'status_description' => "Transaction for model #$order->id",
					'status'             => $status,
				]);

				if ((int)data_get(Settings::where('key', 'order_auto_approved')->first(), 'value') === 1) {
					(new NotificationHelper)->autoAcceptNotification(
						data_get($result, 'data'),
						$this->language,
						Order::STATUS_ACCEPTED
					);
				}
				

			} catch (Throwable $e) {
				dd($e->getMessage());
				$this->error($e);
			}

            $paymentProcess->delete();

			return;
		}


		$paymentProcess = $paymentProcess->fresh(['model.transaction']);

		$userId = data_get($paymentProcess->data, 'user_id');
		$type   = data_get($paymentProcess->data, 'type');

		if ($userId && $type === 'wallet') {

			$trxId       = data_get($paymentProcess->data, 'trx_id');
			$transaction = Transaction::find($trxId);

			$transaction->update([
				'payment_trx_id' => $token,
				'status'         => $status,
			]);

			if ($status === WalletHistory::PAID) {

				$user = User::find($userId);

				$user?->wallet?->increment('price', data_get($paymentProcess->data, 'price'));

				$user->wallet->histories()->create([
					'uuid'           => Str::uuid(),
					'transaction_id' => $transaction->id,
					'type'           => 'topup',
					'price'          => $transaction->price,
					'note'           => "Payment top up via Wallet" ,
					'status'         => WalletHistory::PAID,
					'created_by'     => $transaction->user_id,
				]);

			}

//            $paymentProcess->delete();

            return;
		}

		if ($paymentProcess->model_type === Wallet::class && $status === Transaction::STATUS_PAID) {

			$data = $paymentProcess->data;

			$totalPrice = (double)data_get($data, 'total_price');

			$user = User::find($paymentProcess->model?->user_id);

			try {
				$note = __(
					'errors.' . ResponseError::WALLET_TOP_UP,
					['sender' => ''],
					$user?->lang ?? $this->language
				);

				(new WalletHistoryService)->create([
					'type'           => 'topup',
					'payment_sys_id' => data_get($data, 'payment_id'),
					'created_by'     => data_get($data, 'created_by'),
					'payment_trx_id' => $token,
					'price'          => $totalPrice,
					'note'           => $note,
					'status'         => WalletHistory::PAID,
					'user'           => $user
				]);

			} catch (Throwable $e) {
				$this->error($e);
			}

            $paymentProcess->delete();

			return;
		}

		$modelClass = Str::replace('App\\Models\\', '', $paymentProcess->model_type);

		if ($paymentProcess->model_type === Order::class && !isset($paymentProcess->data['tips'])) {

			$paymentProcess->fresh(['model.transaction']);

			$split = (int)($paymentProcess->data['split'] ?? 1);

			/** @var Order $order */
			$order = $paymentProcess->model;

			if ($split === 1) { // for not qr orders

				$tip = ($paymentProcess->data['after_payment_tips'] ?? 0 / $order->rate);

				$order->update([
					'tips' 		  => round($order->tips + $tip, 2),
					'total_price' => $order->total_price + $tip
				]);

				$order->createTransaction([
					'price'              => $order->total_price,
					'user_id'            => $order->user_id,
					'payment_sys_id'     => $paymentId,
					'payment_trx_id'     => $token,
					'note'               => $order->id,
					'perform_time'       => now(),
					'status_description' => "Transaction for $modelClass #$order->id",
					'status'             => $status,
				]);

                $paymentProcess->delete();

				$order->orderDetails()
					->where('transaction_status', OrderDetail::TRANSACTION_STATUS_PROGRESS)
					->update(['transaction_status' => $status]);

				return;
			}

			$splitPaidCount = Transaction::whereNotNull('parent_id')
				->where('payable_id',   $paymentProcess->model_id)
				->where('payable_type', $paymentProcess->model_type)
				->where('payment_sys_id', $paymentId)
				->where('status',	 Transaction::STATUS_PAID)
				->count();

			if ($status === Transaction::STATUS_PAID) {
				$splitPaidCount += 1;
			}

			if ($status !== Transaction::STATUS_PAID) {

				$processData = $paymentProcess->data;
				$processData['clicked'] = true;

				$paymentProcess->update([
					'data' => $processData
				]);

			}

			$transactionId = $order->transaction?->id;
			$totalPrice    = $order->total_price;

			if ($transactionId && $order->transaction?->payment_sys_id !== $paymentId) {
				$order->createManyTransaction($order->transaction->toArray());
			}

			if ($status === Transaction::STATUS_PAID) {

				$tip = ($paymentProcess->data['after_payment_tips'] ?? 0 / $order->rate);

				$order->update([
					'tips' 		  => round($order->tips + $tip, 2),
					'total_price' => $totalPrice + $tip
				]);

				$transaction = Transaction::where('payable_id', $paymentProcess->model_id)
					->where('payable_type', $paymentProcess->model_type)
					->whereNotNull('parent_id')
					->where('status', Transaction::STATUS_PROGRESS)
					->first();

				$transaction?->update([
					'status' => $status,
					'price' => $transaction->price + $tip
				]);

				$paymentProcess->delete();

				$order->orderDetails()
					->where('transaction_status',OrderDetail::TRANSACTION_STATUS_PROGRESS)
					->update(['transaction_status' => $status]);
			}

			if ($splitPaidCount <= $split) {
				$order->transactions()
					->where('status', Transaction::STATUS_SPLIT)
					->update(['status' => $status]);
			}

			return;

		}

		if ($paymentProcess->model_type === Order::class && isset($paymentProcess->data['tips'])) {

			$paymentProcess->fresh(['model']);

			$paymentProcess->model?->createTransaction([
				'price'              => $paymentProcess->data['tips'],
				'user_id'            => $paymentProcess->model->user_id,
				'payment_sys_id'     => $paymentId,
				'payment_trx_id'     => $token,
				'note'               => $paymentProcess->model->id,
				'perform_time'       => now(),
				'status_description' => "Transaction for $modelClass #{$paymentProcess->model->id}",
				'status'             => $status,
				'type'				 => Transaction::TYPE_TIP
			]);

			$paymentProcess->model?->update([
				'tips' => $paymentProcess->data['tips'],
				'total_price' => $paymentProcess->model->total_price + $paymentProcess->data['tips']
			]);

            $paymentProcess->delete();

		}

		if ($paymentProcess->model_type !== Cart::class) {

			$paymentProcess->fresh([
				'model.transactions' => fn($q) => $q->where('status', Transaction::STATUS_PAID)
			]);

			$paymentProcess->model?->createTransaction([
				'price'              => $paymentProcess->model->total_price,
				'user_id'            => $paymentProcess->model->user_id,
				'payment_sys_id'     => $paymentId,
				'payment_trx_id'     => $token,
				'note'               => $paymentProcess->model->id,
				'perform_time'       => now(),
				'status_description' => "Transaction for $modelClass #{$paymentProcess->model->id}",
				'status'             => $status,
			]);

            $paymentProcess->delete();

		}

	}

	/**
	 * @param array $data
	 * @param array $payload
	 * @return array
	 * @throws Exception
	 */
	public function getPayload(array $data, array $payload): array
	{
		$key    = '';
		$before = [];
		$tips = data_get($data, 'tips');

		if (data_get($data, 'cart_id')) {

			$key = 'cart_id';
			$before = $this->beforeCart($data, $payload);

		} else if (data_get($data, 'parcel_id')) {

			$key = 'parcel_id';
			$before = $this->beforeParcel($data, $payload);

		} else if (!$tips && data_get($data, 'order_id')) {

			$key = 'order_id';
			$before = $this->beforeOrder($data, $payload);

		} else if ($tips && data_get($data, 'order_id')) {

			$key = 'order_id';
			$before = $this->beforeTip($data, $payload);

		} else if (data_get($data, 'wallet_id')) {

			$key = 'wallet_id';
			$before = $this->beforeWallet($data, $payload);

		} else if (data_get($data, 'delivery_type') === Order::KIOSK) {

			$key = 'kiosk_id';
			$before = $this->beforeKiosk($data, $payload);

		}

		return [$key, $before];
	}

	/**
	 * @param array $data
	 * @param array|null $payload
	 * @return array
	 * @throws Exception
	 */
	public function beforeCart(array $data, array|null $payload): array
	{
		$cart         = Cart::find(data_get($data, 'cart_id'));
		$data['type'] = data_get($data, 'delivery_type');

		$calculate  = (new CartRepository)->calculateByCartId((int)data_get($data, 'cart_id'), array_merge($data, [
			'address' => $data['location'] ?? []
		]));

		if (!data_get($calculate, 'status')) {
			throw new Exception('Cart is empty');
		}

		$totalPrice = round(data_get($calculate, 'data.total_price'), 2);
		$tips       = round(data_get($calculate, 'data.tips'), 2);

		return [
			'model_type'  => get_class($cart),
			'model_id'    => $cart->id,
			'total_price' => $totalPrice,
			'tips'		  => $tips,
			'currency'    => $cart->currency?->title ?? data_get($payload, 'currency'),
			'cart_id'     => $cart->id,
			'user_id'     => auth('sanctum')->id(),
			'status'      => Order::STATUS_NEW,
		] + $data;
	}

	/**
	 * @param array $data
	 * @param array|null $payload
	 * @return array
	 * @throws Exception
	 */
	public function beforeKiosk(array $data, array|null $payload): array
	{
		$data['type'] = data_get($data, 'delivery_type');

		$calculate  = (new OrderRepository)
            ->orderStocksCalculate(
                array_merge($data, ['address' => $data['location'] ?? []])
            );

		if (!data_get($calculate, 'status')) {
			throw new Exception('Products is empty');
		}

		$totalPrice = round(data_get($calculate, 'data.total_price'), 2);
		$tips       = round(data_get($calculate, 'data.tips'), 2);

		return [
			'model_type'  => $data['type'],
			'model_id'    => time(),
			'total_price' => $totalPrice,
			'tips'		  => $tips,
			'currency'    => Currency::find($this->currency)?->title ?? data_get($payload, 'currency'),
			'user_id'     => auth('sanctum')->id(),
			'status'      => Order::STATUS_NEW,
		] + $data;
	}

	/**
	 * @param array $data
	 * @param array|null $payload
	 * @return array
	 */
	public function beforeWallet(array $data, array|null $payload): array
	{
		$model = Wallet::find(data_get($data, 'wallet_id'));

		$totalPrice = data_get($data, 'total_price');

		$currency = Currency::find($this->currency);

		return [
			'model_type'  => get_class($model),
			'model_id'    => $model->id,
			'total_price' => $totalPrice,
			'currency'    => $currency?->title ?? data_get($payload, 'currency')
		];
	}

	/**
	 * @param array $data
	 * @param array|null $payload
	 * @return array
	 */
	public function beforeParcel(array $data, array|null $payload): array
	{
		$parcel     = ParcelOrder::find(data_get($data, 'parcel_id'));
		$totalPrice = round($parcel->rate_total_price * 1, 2);

		return [
			'model_type'  => get_class($parcel),
			'model_id'    => $parcel->id,
			'total_price' => $totalPrice,
			'currency'    => $parcel->currency?->title ?? data_get($payload, 'currency')
		];
	}

	/**
	 * @param array $data
	 * @param array|null $payload
	 * @return array
	 */
	public function beforeOrder(array $data, array|null $payload): array
	{
		/** @var Order $order */
		$order = Order::with(['currency'])->find(data_get($data, 'order_id'));

		//when one of split payment failed. Generate new link with total paying price
		$paid = Transaction::where([
			'status'        => [Transaction::STATUS_PAID, Transaction::STATUS_REPAY],
			'payable_type'  => get_class($order),
			'payable_id'    => $order->id,
		])->sum('price');

		$totalPrice = round($order->rate_total_price - $paid, 2);

		$tip = max(round($data['after_payment_tips'] ?? 0, 1), 0);

		$totalPrice += $tip;

		return [
			'model_type'  		 => get_class($order),
			'model_id'    		 => $order->id,
			'shop_id'    		 => $order->shop_id,
			'table_id'    		 => $order?->table_id,
			'chair_count'  		 => $order?->table?->chair_count,
			'name'       		 => str_replace(' ','+', $order?->table?->name),
			'after_payment_tips' => $tip,
			'total_price' 		 => max($totalPrice, 0),
			'currency'    		 => $order->currency?->title ?? data_get($payload, 'currency')
		];
	}

	/**
	 * @param array $data
	 * @param array|null $payload
	 * @return array
	 */
	public function beforeTip(array $data, array|null $payload): array
	{
		/** @var Order $order */
		$order = Order::find(data_get($data, 'order_id'));

		$totalPrice = round(data_get($data, 'tips') / $order->rate, 2);

		return [
			'model_type'  => get_class($order),
			'model_id'    => $order->id,
			'total_price' => max($totalPrice, 0),
			'tips' 		  => max($totalPrice, 0),
			'currency'    => $order->currency?->title ?? data_get($payload, 'currency')
		];
	}
}
