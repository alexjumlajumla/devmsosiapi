<?php

namespace App\Observers;

use App\Jobs\AttachDeliveryMan;
use App\Models\Language;
use App\Models\Order;
use App\Models\PushNotification;
use App\Models\Settings;
use App\Services\ModelLogService\ModelLogService;
use App\Traits\Notification;
use DB;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;
use App\Services\OrderNotificationService;
use Throwable;
use App\Models\Trip;

class OrderObserver
{
    use Notification;

    /**
     * Handle the Brand "created" event.
     *
     * @param Order $order
     * @return void
     */
    public function created(Order $order): void
    {
        if ($order->status === Order::STATUS_READY && empty($order->deliveryman) && $this->autoDeliveryMan()) {
            AttachDeliveryMan::dispatchAfterResponse($order, $this->language());
        }

        try {
            // Format address as a string if it's an array
            $addressString = 'Unknown';
            if (isset($order->address)) {
                if (is_array($order->address)) {
                    // Try to extract meaningful address information
                    $addressString = implode(', ', array_filter([
                        $order->address['address'] ?? '',
                        $order->address['house'] ?? '',
                        $order->address['street'] ?? '',
                        $order->address['city'] ?? '',
                    ]));
                    
                    // If still empty after trying to combine array elements
                    if (empty(trim($addressString))) {
                        $addressString = 'Address from Order #' . $order->id;
                    }
                } else {
                    // If it's already a string
                    $addressString = (string)$order->address;
                }
            }

            // Get customer coordinates or use defaults
            $customerLat = is_array($order->location) ? 
                ($order->location['lat'] ?? $order->location['latitude'] ?? 0) : 0;
            $customerLng = is_array($order->location) ? 
                ($order->location['lng'] ?? $order->location['longitude'] ?? 0) : 0;

            // Create a new Trip for the order, always set status to planned initially
            $trip = Trip::create([
                'name' => 'Trip for Order #' . $order->id,
                'start_address' => $addressString,
                'start_lat' => $customerLat,
                'start_lng' => $customerLng,
                'scheduled_at' => now(),
                'status' => 'planned',
                'meta' => [
                    'created_for_order_id' => $order->id,
                    'estimated_travel_time' => 30,  // Default 30 minutes
                    'estimate_method' => 'default'
                ]
            ]);

            // Associate the order with the trip
            $order->trips()->attach($trip->id, ['sequence' => 1, 'status' => 'pending']);
            
            // Add shop location as a destination for the trip
            if ($trip && $order->shop) {
                $shop = $order->shop;
                
                // Get shop coordinates
                $shopLat = is_array($shop->location) ? 
                    ($shop->location['lat'] ?? ($shop->location['latitude'] ?? 0)) : 0;
                $shopLng = is_array($shop->location) ? 
                    ($shop->location['lng'] ?? ($shop->location['longitude'] ?? 0)) : 0;
                
                // Add the trip location
                $trip->locations()->create([
                    'address' => $shop->address ?? 'Delivery destination', 
                    'lat' => $shopLat,
                    'lng' => $shopLng,
                    'sequence' => 0,
                    'eta_minutes' => 30,
                    'status' => 'pending'
                ]);
                
                // Calculate distance-based ETA if we have valid coordinates
                if ($customerLat && $customerLng && $shopLat && $shopLng) {
                    // Use Haversine formula to calculate distance in kilometers
                    $earthRadius = 6371; // km
                    $dLat = deg2rad($shopLat - $customerLat);
                    $dLng = deg2rad($shopLng - $customerLng);
                    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($customerLat)) * cos(deg2rad($shopLat)) * sin($dLng/2) * sin($dLng/2);
                    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                    $distance = $earthRadius * $c;
                    
                    // Rough estimate: 20 km/h speed in city traffic = 1 km takes 3 minutes
                    $eta = round(max(15, $distance * 3)); // Minimum 15 minutes
                    
                    // Update trip and location with calculated ETA
                    $trip->locations()->update(['eta_minutes' => $eta]);
                    $trip->update([
                        'meta' => [
                            'created_for_order_id' => $order->id,
                            'estimated_travel_time' => $eta,
                            'distance_km' => round($distance, 2),
                            'estimate_method' => 'distance_based'
                        ]
                    ]);
                }
            }
        } catch (Throwable $e) {
            // Log the error but don't stop order creation
            Log::error('Error creating trip for order #' . $order->id . ': ' . $e->getMessage());
        }

        try {
            // Send order notification
            (new OrderNotificationService)->sendOrderNotification($order, 'created');
            Log::info('Order notification sent', ['order_id' => $order->id]);
        } catch (\Exception $e) {
            Log::error('Failed to send order notification', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        (new ModelLogService)->logging($order, $order->getAttributes(), 'created');
    }

    /**
     * Handle the Brand "updated" event.
     *
     * @param Order $order
     * @return void
     */
    public function updated(Order $order): void
    {
        if ($order->status === Order::STATUS_READY && empty($order->deliveryman) && $this->autoDeliveryMan()) {
			AttachDeliveryMan::dispatchAfterResponse($order, $this->language());
        }   

        // (new OrderNotificationService)->sendOrderNotification($order, 'updated');

        (new ModelLogService)->logging($order, $order->getAttributes(), 'updated');
    }

    /**
     * Handle the Order "restored" event.
     *
     * @param Order $order
     * @return void
     */
    public function deleted(Order $order): void
    {
		try {
			$order->transactions()->delete();
			$order->reviews()->delete();
			$order->galleries()->delete();
			$order->coupon()->delete();
			$order->pointHistories()->delete();
			$order->orderDetails()->delete();
			DB::table('push_notifications')
				->where(function ($query) {
					$query
						->where('type', PushNotification::NEW_ORDER)
						->orWhere('type', PushNotification::STATUS_CHANGED);
				})
				->where('title', $order->id)
				->delete();
		} catch (Throwable|InvalidArgumentException) {}

        (new ModelLogService)->logging($order, $order->getAttributes(), 'deleted');
    }

    /**
     * Handle the Order "restored" event.
     *
     * @param Order $order
     * @return void
     */
    public function restored(Order $order): void
    {
        (new ModelLogService)->logging($order, $order->getAttributes(), 'restored');
    }

    /**
     * @return string
     */
    public function language(): string
    {
        return request(
            'lang',
            data_get(Language::where('default', 1)->first(['locale', 'default']), 'locale')
        );
    }

    /**
     * @return bool
     */
    public function autoDeliveryMan(): bool
    {
        $autoDeliveryMan = Settings::where('key', 'order_auto_delivery_man')->first();

        return (int)data_get($autoDeliveryMan, 'value', 0) === 1;
    }

}
