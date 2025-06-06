<?php

namespace App\Services\OrderService;

use DB;
use Log;
use Throwable;
use App\Models\User;
use App\Models\Order;
use App\Models\Point;
use App\Models\Payment;
use App\Models\Language;
use App\Jobs\PayReferral;
use App\Models\OrderDetail;
use App\Models\Transaction;
use App\Models\Translation;
use App\Models\PointHistory;
use App\Traits\Notification;
use App\Models\WalletHistory;
use App\Services\CoreService;
use App\Helpers\ResponseError;
use App\Models\NotificationUser;
use App\Models\PushNotification;
use App\Models\Settings;
use App\Services\EmailSettingService\EmailSendService;
use App\Services\WalletHistoryService\WalletHistoryService;
use App\Services\OrderService\OrderSmsService;
use App\Models\Trip;
use App\Models\TripLocation;
use App\Helpers\NotificationHelper;
use App\Services\FCM\FcmTokenService;
use App\Services\Order\VfdReceiptService;

class OrderStatusUpdateService extends CoreService
{
    use Notification;

    private FcmTokenService $fcmTokenService;
    private VfdReceiptService $vfdReceiptService;

    public function __construct(
        FcmTokenService $fcmTokenService,
        VfdReceiptService $vfdReceiptService
    ) {
        $this->fcmTokenService = $fcmTokenService;
        $this->vfdReceiptService = $vfdReceiptService;
    }

    /**
     * @return string
     */
    protected function getModelClass(): string
    {
        return Order::class;
    }

	/**
	 * @param Order $order
	 * @param string|null $status
	 * @param bool $isDelivery
	 * @param string|null $detailStatus
	 * @return array
	 */
    public function statusUpdate(Order $order, ?string $status, bool $isDelivery = false, ?string $detailStatus = null): array
    {
        if ($order->status == $status) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_252,
                'message' => __('errors.' . ResponseError::ERROR_252, locale: $this->language)
            ];
        }

		$order = $order->fresh([
			'user',
			'shop',
			'pointHistories',
			'orderRefunds',
			'orderDetails',
			'transaction.paymentSystem',
		]);

        try {
            $order = DB::transaction(function () use ($order, $status, $detailStatus) {

				$paymentCash = Payment::where('tag', Payment::TAG_CASH)->value('id');

				if (in_array(request('transaction_status'), Transaction::STATUSES)) {

					$paymentId = $order?->transaction?->payment_sys_id ?? $paymentCash;

					$order->createTransaction([
						'price'              => $order->total_price,
						'user_id'            => $order?->user_id,
						'payment_sys_id'     => $paymentId,
						'payment_trx_id'     => $order?->transaction?->payment_trx_id,
						'note'               => $order->id,
						'perform_time'       => now(),
						'status_description' => "Transaction for model #$order->id",
						'status'             => request('transaction_status'),
					]);

				}

                if ($status == Order::STATUS_DELIVERED) {

                    // Check if this order is part of a Trip and mark the related location as completed
                    $this->completeOrderTripLocation($order);

                    // Add points to user
                    if (Settings::where('key', 'reward_system')->first()?->value) {

                        if (Settings::where('key', 'reward_type')->first()?->value == 'order') {

                            $type = Settings::where('key', 'order_point_type')->first()?->value;

                            if ($type == 'fix') {
                                // fixed point to each order
                                $orderPoints = (float)Settings::where('key', 'fixed_point')->first()?->value;

                            } else {
                                // percentage point to the order amount
                                $percentage = (float)Settings::where('key', 'percentage_point')->first()?->value;
                                $orderPoints = ($percentage * $order->price) / 100;
                            }

                            $points = Point::orderBy('from', 'asc')->get();
                            $userPoint = $order->user?->point;
                            $reward = 0;

                            if ($userPoint) {
                                $lastPoint = $userPoint;
                                $lastPoint->price += $orderPoints;
                                $lastPoint->save();

                                if ($points->count() > 0) {
                                    foreach ($points as $key => $point) {
                                        if ($lastPoint->price >= $point->from) {
                                            $reward = $point->reward;
                                        }
                                    }

                                    $lastPoint->update(['value' => $reward]);
                                }
                            }
                        }
                    }

                    PayReferral::dispatchAfterResponse($order->user, 'increment');

					if ($order?->transaction?->paymentSystem?->tag == Payment::TAG_CASH) {

						$trxStatus = request('transaction_status');
						$trxStatus = in_array($trxStatus, Transaction::STATUSES) ? $trxStatus : Transaction::STATUS_PAID;

						$order->transaction->update(['status' => $trxStatus]);
					}

                    // Generate VFD receipt automatically for delivery fee if not already generated
                    try {
                        // Ensure order has a delivery fee greater than zero before generating receipt
                        if ($order->delivery_fee > 0) {
                            // Avoid duplicate receipts for the same order
                            $hasReceipt = \App\Models\VfdReceipt::where('model_type', \App\Models\Order::class)
                                ->where('model_id', $order->id)
                                ->where('receipt_type', \App\Models\VfdReceipt::TYPE_DELIVERY)
                                ->exists();

                            if (!$hasReceipt) {
                                // Determine payment method for the delivery fee
                                $paymentMethod = \App\Models\VfdReceipt::PAYMENT_CASH; // Default
                                if ($order->transaction) {
                                    $paymentSystem = $order->transaction->paymentSystem;
                                    if ($paymentSystem) {
                                        if ($paymentSystem->tag === Payment::TAG_CASH) {
                                            $paymentMethod = \App\Models\VfdReceipt::PAYMENT_CASH;
                                        } else {
                                            $paymentMethod = \App\Models\VfdReceipt::PAYMENT_CREDIT_CARD;
                                        }
                                    }
                                }

                                (new \App\Services\VfdService\VfdService)->generateReceipt(
                                    \App\Models\VfdReceipt::TYPE_DELIVERY,
                                    [
                                        'model_id'       => $order->id,
                                        'model_type'     => \App\Models\Order::class,
                                        'amount'         => $order->delivery_fee,
                                        'payment_method' => $paymentMethod,
                                        'customer_name'  => $order->user?->firstname . ' ' . $order->user?->lastname,
                                        'customer_phone' => $order->user?->phone,
                                        'customer_email' => $order->user?->email,
                                    ]
                                );
                            }
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('Automatic VFD receipt generation failed', [
                            'order_id' => $order->id,
                            'message'  => $e->getMessage(),
                            'trace'    => $e->getTraceAsString(),
                        ]);
                    }

                }

                if ($status == Order::STATUS_CANCELED && $order->orderRefunds?->count() === 0) {

                    $user = $order->user;

					$order->transaction?->update([
						'status' => Transaction::STATUS_CANCELED,
					]);

                    if ($order->pointHistories?->count() > 0) {
                        foreach ($order->pointHistories as $pointHistory) {
                            /** @var PointHistory $pointHistory */
							$user?->wallet?->decrement('price', $pointHistory->price);
                            $pointHistory->delete();
                        }
                    }

                    if ($order->status === Order::STATUS_DELIVERED) {
                        PayReferral::dispatchAfterResponse($user, 'decrement');
                    }

                    $order->orderDetails->map(function (OrderDetail $orderDetail) {
                        $orderDetail->stock()->increment('quantity', $orderDetail->quantity);
                    });

                }

				if (in_array($order->status, $order->shop?->email_statuses ?? []) && ($order->email || $order->user?->email)) {
					(new EmailSendService)->sendOrder($order);
				}

                // Update order status
                $order->update([
                    'status' => $status,
                    'current' => in_array($status, [Order::STATUS_NEW, Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_ON_A_WAY, Order::STATUS_PAID]) ? 1 : 0,
                ]);

                // Generate VFD receipt when order is delivered
                if ($status === Order::STATUS_DELIVERED && $order->delivery_fee > 0) {
                    try {
                        $result = $this->vfdReceiptService->generateForOrder($order, $order->paymentProcess?->payment?->tag ?? 'cash');
                        if (!$result['status']) {
                            Log::error('Failed to generate VFD receipt', [
                                'order_id' => $order->id,
                                'error' => $result['error'] ?? 'Unknown error'
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Exception while generating VFD receipt', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }

                // Send SMS notification based on new status
                switch($status) {
                    case Order::STATUS_PROCESSING:
                        OrderSmsService::orderProcessing($order);
                        break;
                    case Order::STATUS_SHIPPED:
                        OrderSmsService::orderShipped($order);
                        break;
                    case Order::STATUS_DELIVERED:
                        OrderSmsService::orderDelivered($order);
                        break;
                    case Order::STATUS_CANCELED:
                        OrderSmsService::orderCancelled($order);
                        break;
                }

				if (!empty($detailStatus)) {

					foreach ($order->orderDetails as $orderDetail) {

						$order->update(['status' => $detailStatus]);

						$orderDetail->children()->update(['status' => $detailStatus]);

					}

				}

                return $order;
            });
        } catch (Throwable $e) {

            $this->error($e);

            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_501,
                'message' => $e->getMessage()
            ];
        }

        /** @var Order $order */

        $order->loadMissing(['shop.seller', 'deliveryMan', 'user']);

        /** @var NotificationUser $notification */
        $notification = $order->user?->notifications
            ?->where('type', \App\Models\Notification::ORDER_STATUSES)
            ?->first();

        if ($notification?->notification?->active) {
            $userToken = $order->user?->firebase_token;
        }

        if (!$isDelivery) {
            $deliveryManToken = $order->deliveryMan?->firebase_token;
        }

        if (in_array($status, [Order::STATUS_ON_A_WAY, Order::STATUS_DELIVERED, Order::STATUS_CANCELED])) {
            $sellerToken = $order->shop?->seller?->firebase_token;
        }

        $title = '';
        $body  = '';

        $language = data_get(Language::languagesList()->where('default', 1)->first(), 'locale');

        $tStatus = Translation::where([
            ['key', "order_status_$status"],
            ['locale', $language]
        ])->first()?->value;

        $tStatus = $tStatus ?: $status;

        // Send notification to the user about order status change
        if ($order->user && $userToken) {
            $title = "Order #$order->id";
            $body  = "Your order #$order->id has been $tStatus";

            $this->sendStatusNotification($order, $status, $title, $body);
        }

        // Send notification to the deliveryman about order status change
        if ($deliveryManToken) {
            $title = "Order #$order->id";
            $body  = "Order #$order->id status changed to $tStatus";

            $data = [
                'title' => $title,
                'body' => $body,
                'type' => PushNotification::STATUS_CHANGED,
                'id' => $order->id,
                'order' => $order,
            ];

            $this->sendNotification(
                is_array($deliveryManToken) ? $deliveryManToken : [$deliveryManToken],
                $data,
                [$order->deliveryman?->id]
            );
        }

        // Send notification to the seller about order status change
        if ($sellerToken) {
            $title = "Order #$order->id";
            $body  = "Order #$order->id status changed to $tStatus";

            $data = [
                'title' => $title,
                'body' => $body,
                'type' => PushNotification::STATUS_CHANGED,
                'id' => $order->id,
                'order' => $order,
            ];

            $this->sendNotification(
                is_array($sellerToken) ? $sellerToken : [$sellerToken],
                $data,
                [$order->shop?->seller?->id]
            );
        }

        return [
            'status' => true,
            'code'   => ResponseError::NO_ERROR,
            'data'   => $order
        ];
    }

    /**
     * Check if order is part of a Trip and complete the related location
     */
    private function completeOrderTripLocation(Order $order): void
    {
        try {
            // Find the trip location related to this order
            $tripLocation = TripLocation::where('order_id', $order->id)
                ->orWhere(function($query) use ($order) {
                    // If no direct order_id, try to match by coordinates
                    if ($order->location && isset($order->location['latitude']) && isset($order->location['longitude'])) {
                        $query->where('lat', $order->location['latitude'])
                              ->where('lng', $order->location['longitude']);
                    }
                })
                ->first();

            if ($tripLocation) {
                // Mark the location as arrived
                $tripLocation->update([
                    'status' => 'arrived',
                    'updated_at' => now()
                ]);

                // Get the trip
                $trip = Trip::find($tripLocation->trip_id);
                
                if ($trip) {
                    // Check if all locations are now arrived
                    $pendingLocations = $trip->locations()->where('status', 'pending')->count();
                    
                    if ($pendingLocations === 0) {
                        // If all locations are completed, mark trip as completed
                        $trip->update([
                            'status' => 'completed',
                            'updated_at' => now(),
                            'meta' => array_merge($trip->meta ?? [], [
                                'completed_at' => now()->toIso8601String(),
                                'completion_method' => 'order_delivered'
                            ])
                        ]);
                        
                        Log::info("Trip #{$trip->id} automatically completed due to order #{$order->id} delivery");
                    }
                }
            }
        } catch (\Throwable $e) {
            // Just log errors but don't interrupt the main process
            Log::error("Error completing trip location for order #{$order->id}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Send status update notification to the order's user
     * 
     * @param Order $order
     * @param string $status
     * @param string $title
     * @param string $body
     * @return void
     */
    private function sendStatusNotification(Order $order, string $status, string $title, string $body)
    {
        try {
            $firebaseTokens = [];
            $userIds = [];

            // Get the user and their FCM tokens using the EnhancedFcmTokenService
            if ($order->user) {
                $user = $order->user;
                $fcmService = $this->fcmTokenService;
                $tokens = $fcmService->getUserTokens($user);
                
                if (!empty($tokens)) {
                    $firebaseTokens = $tokens;
                    $userIds[] = $user->id;
                    
                    Log::channel('orders')->info('Found FCM tokens for order notification', [
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'token_count' => count($tokens),
                        'status' => $status
                    ]);
                } else {
                    Log::channel('orders')->warning('No valid FCM tokens found for user', [
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'status' => $status
                    ]);
                }
            }

            if (empty($firebaseTokens)) {
                Log::channel('orders')->warning('No FCM tokens available for order notification', [
                    'order_id' => $order->id,
                    'user_id' => $order->user_id ?? null,
                    'status' => $status
                ]);
                return;
            }

            // Prepare notification data
            $notificationData = [
                'id' => $order->id,
                'status' => $status,
                'type' => PushNotification::STATUS_CHANGED,
                'title' => $title,
                'body' => $body,
                'order' => [
                    'id' => $order->id,
                    'status' => $status
                ]
            ];

            try {
                // Send notification
                $this->sendNotification(
                    $firebaseTokens,
                    $notificationData,
                    $userIds
                );
                
                Log::channel('orders')->info('Notification sent via FCM', [
                    'order_id' => $order->id,
                    'user_ids' => $userIds,
                    'token_count' => count($firebaseTokens),
                    'status' => $status
                ]);
            } catch (\Throwable $e) {
                Log::channel('orders')->error('Failed to send FCM notification', [
                    'order_id' => $order->id,
                    'user_ids' => $userIds,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            // Store notification in database for each user
            foreach ($userIds as $userId) {
                try {
                    PushNotification::create([
                        'user_id' => $userId,
                        'type' => PushNotification::STATUS_CHANGED,
                        'title' => $title,
                        'body' => $body,
                        'data' => [
                            'order_id' => $order->id,
                            'status' => $status
                        ]
                    ]);
                    
                    Log::channel('orders')->debug('Notification stored in database', [
                        'order_id' => $order->id,
                        'user_id' => $userId,
                        'status' => $status
                    ]);
                } catch (\Throwable $e) {
                    Log::channel('orders')->error('Failed to store notification in database', [
                        'order_id' => $order->id,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
            
            Log::channel('orders')->info('Notification processing completed for order', [
                'order_id' => $order->id,
                'status' => $status,
                'user_count' => count($userIds),
                'token_count' => count($firebaseTokens)
            ]);
        } catch (\Throwable $e) {
            Log::channel('orders')->error('Error in sendStatusNotification', [
                'order_id' => $order->id,
                'status' => $status,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e; // Re-throw to be handled by the caller
        }
    }
}
