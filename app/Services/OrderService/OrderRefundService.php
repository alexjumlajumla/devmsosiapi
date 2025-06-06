<?php

namespace App\Services\OrderService;

use App\Helpers\ResponseError;
use App\Jobs\PayReferral;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderRefund;
use App\Models\PaymentToPartner;
use App\Models\PushNotification;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletHistory;
use App\Services\CoreService;
use App\Services\WalletHistoryService\WalletHistoryService;
use App\Traits\Notification;
use DB;
use Exception;
use Throwable;

class OrderRefundService extends CoreService
{
	use Notification;

    protected function getModelClass(): string
    {
        return OrderRefund::class;
    }

    /**
     * @param array $data
     * @return array
     */
    public function create(array $data): array
    {
        try {
            $exist = OrderRefund::where('order_id', data_get($data,'order_id'))->first();

            if (in_array(data_get($exist, 'status'), [OrderRefund::STATUS_PENDING, OrderRefund::STATUS_ACCEPTED])) {
                return [
                    'status'    => false,
                    'code'      => ResponseError::ERROR_506,
                    'message'   => __('errors.' . ResponseError::ERROR_506, locale: $this->language),
                ];
            }

            /** @var OrderRefund $orderRefund */
            $orderRefund = $this->model();

            $orderRefund->create($data);

            if (data_get($data, 'images.0')) {
                $orderRefund->uploads(data_get($data, 'images'));
            }

            return ['status' => true, 'message' => ResponseError::NO_ERROR];

        } catch (Throwable $e) {
            $this->error($e);

            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_501,
                'message' => __('errors.' . ResponseError::ERROR_501, locale: $this->language),
            ];
        }
    }

    public function update(OrderRefund $orderRefund, array $data): array
    {
        try {

            if ($orderRefund->status == data_get($data, 'status')) {
                return [
                    'status' => false,
                    'code'   => __('errors.' . ResponseError::ERROR_252, locale: $this->language)
                ];
            }

            $orderRefund = $orderRefund->loadMissing([
                'order.shop',
                'order.shop.seller.wallet',
                'order.deliveryMan.wallet',
                'order.user.wallet',
                'order.transactions',
                'order.orderDetails.stock',
                'order.paymentToPartner',
                'order.coupon',
                'order.pointHistories',
            ]);

            /** @var User $user */
            $user = data_get($orderRefund->order, 'user');

            /** @var Transaction $transaction */
            $transaction = $orderRefund?->order
                ?->transactions()
                ?->where('status', Transaction::STATUS_PAID)
                ?->first();

            if (data_get($data, 'status') === OrderRefund::STATUS_ACCEPTED) {

                if (!$user->wallet) {
                    return [
                        'status'  => false,
                        'message' => __('errors.' . ResponseError::ERROR_108, locale: $this->language),
                        'code'    => ResponseError::ERROR_108
                    ];
                }

                if (!$orderRefund->order) {
                    return [
                        'status'  => false,
                        'message' => __('errors' . ResponseError::ORDER_NOT_FOUND, locale: $this->language),
                        'code'    => ResponseError::ERROR_404
                    ];
                }

                /** @var Transaction $existRefund */
                $existRefund = $orderRefund->order->transactions()
                    ->where('status', Transaction::STATUS_REFUND)
                    ->first();

                if ($existRefund) {
                    return [
                        'status'  => false,
                        'code'    => ResponseError::ERROR_501,
                        'message' => __('errors.' . ResponseError::ORDER_REFUNDED, locale: $this->language),
                    ];
                }
            }

            DB::transaction(function () use ($orderRefund, $data, $user, $transaction) {

                $orderRefund->update($data);

                if (data_get($data, 'images.0')) {
                    $orderRefund->galleries()->delete();
                    $orderRefund->uploads(data_get($data, 'images'));
                }

                if ($orderRefund->status !== OrderRefund::STATUS_ACCEPTED) {
                    return true;
                }

                if (!$transaction?->id) {
                    return true;
                }

                $order = $orderRefund->order;

                if (!$user->wallet?->id) {
                    throw new Exception(__('errors.' . ResponseError::ERROR_108, locale: $this->language));
                }

                if ($order->transactions->where('status', Transaction::STATUS_PAID)->first()?->id) {

                    (new WalletHistoryService)->create([
                        'type'   => 'topup',
                        'price'  => $order->total_price,
                        'note'   => "Refund for Order #$order->id",
                        'status' => WalletHistory::PAID,
                        'user'   => $user
                    ]);

					$this->sendNotification(
						is_array($user->firebase_token) ? $user->firebase_token : [$user->firebase_token],
						__('errors.' . ResponseError::ORDER_REFUNDED, locale: $this->language),
						$orderRefund->id,
						[
							'id'     => $orderRefund->id,
							'status' => $orderRefund->status,
							'type'   => PushNotification::ORDER_REFUNDED
						],
						[$user->id],
						__('errors.' . ResponseError::ORDER_REFUNDED, locale: $this->language),
					);

                    if ($order->status === Order::STATUS_DELIVERED) {

                        if (!$order->shop?->seller?->wallet?->id) {
                            throw new Exception(__('errors.' . ResponseError::ERROR_114, locale: $this->language));
                        }

                        if ($order->paymentToPartner?->type === PaymentToPartner::SELLER) {

							// Calculate base price from order details
							$subtotal = $order->orderDetails->sum('total_price');

							// Add shop tax
							$shopTax = max($subtotal / 100 * $order->shop?->tax, 0);
							$subtotal += $shopTax;

							// Handle coupon deduction only if it's for total price
							$couponDeduction = 0;
							if ($order->coupon && $order->coupon->for === 'total_price') {
								$couponDeduction = $order->coupon->price;
							}

							// Calculate seller's refund amount
							$sellerPrice = $subtotal 
								- $order->commission_fee 
								- $couponDeduction
								- $order->pointHistories->sum('price');

							$seller = $order->shop->seller;

							(new WalletHistoryService)->create([
								'type'   => 'withdraw',
								'price'  => $sellerPrice,
								'note'   => "Refund for Order #$order->id",
								'status' => WalletHistory::PAID,
								'user'   => $order->shop->seller
							]);

							$this->sendNotification(
								is_array($seller->firebase_token) ? $seller->firebase_token : [$seller->firebase_token],
								__('errors.' . ResponseError::ORDER_REFUNDED, locale: $this->language),
								$orderRefund->id,
								[
									'id'     => $orderRefund->id,
									'status' => $orderRefund->status,
									'type'   => PushNotification::ORDER_REFUNDED
								],
								[$seller->id],
								__('errors.' . ResponseError::ORDER_REFUNDED, locale: $this->language),
							);
						}

                        if (
							$order->paymentToPartner?->type === PaymentToPartner::DELIVERYMAN
							&& $order->delivery_type === Order::DELIVERY
							&& $order->deliveryMan?->wallet?->id
						) {

							if (!$order->shop?->seller?->wallet?->id) {
								throw new Exception(__('errors.' . ResponseError::ERROR_114, locale: $this->language));
							}

                            (new WalletHistoryService)->create([
                                'type'   => 'withdraw',
                                'price'  => $order->delivery_fee,
                                'note'   => "Refund for Order #$order->id",
                                'status' => WalletHistory::PAID,
                                'user'   => $order->deliveryMan
                            ]);

                        }

                    }

                }

                $order->orderDetails->map(function (OrderDetail $orderDetail) {
                    $orderDetail->stock()->increment('quantity', $orderDetail->quantity);
                });

                if ($order->status === Order::STATUS_DELIVERED) {
                    PayReferral::dispatchAfterResponse($order->user, 'decrement');
                }

                return true;
            });

            return ['status' => true, 'message' => ResponseError::NO_ERROR];

        } catch (Throwable $e) {
            $this->error($e);

            return ['status' => false, 'code' => ResponseError::ERROR_501, 'message' => $e->getMessage()];
        }
    }

    public function delete(?array $ids = [], ?int $shopId = null, ?bool $isAdmin = false): array
    {
        try {

           foreach (OrderRefund::find(is_array($ids) ? $ids : []) as $orderRefund) {

               if (!$isAdmin) {
                   if (empty($shopId) && data_get($orderRefund->order, 'user_id') !== auth('sanctum')->id()) {
                       continue;
                   } else if (!in_array($orderRefund->status, [OrderRefund::STATUS_ACCEPTED, OrderRefund::STATUS_CANCELED])) {
                       continue;
                   } else if(!empty($shopId) && $orderRefund->order?->shop_id !== $shopId) {
                       continue;
                   }
               }

               $orderRefund->galleries()->delete();
               $orderRefund->delete();
           }

            return [
                'status'  => true,
                'message' => ResponseError::NO_ERROR,
            ];

        } catch (Throwable $e) {
            $this->error($e);

            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_503,
                'message' => __('errors.' . ResponseError::ERROR_503, locale: $this->language),
            ];
        }
    }

}
