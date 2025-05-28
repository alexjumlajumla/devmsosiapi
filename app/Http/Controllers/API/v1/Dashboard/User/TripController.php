<?php

namespace App\Http\Controllers\API\v1\Dashboard\User;

use App\Helpers\ResponseError;
use App\Http\Resources\TripResource;
use App\Models\Order;
use App\Models\Trip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends UserBaseController
{
    /**
     * Get trip by ID
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $trip = Trip::with(['driver', 'vehicle', 'locations'])
                ->where(['id' => $id])
                ->first();

            if (!$trip) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ]);
            }

            // Check if the trip belongs to an order for this user
            $userOrderIds = Order::where('user_id', auth('sanctum')->id())->pluck('id')->toArray();
            $tripOrderIds = $trip->orders()->pluck('orders.id')->toArray();
            
            // Check if any of the trip's orders belong to the user
            if (!array_intersect($userOrderIds, $tripOrderIds)) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_403,
                    'message' => __('errors.' . ResponseError::ERROR_403, locale: $this->language)
                ]);
            }

            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                TripResource::make($trip)
            );
        } catch (\Exception $e) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_400,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get trip for an order
     *
     * @param int $orderId
     * @return JsonResponse
     */
    public function getOrderTrip(int $orderId): JsonResponse
    {
        try {
            // Check if order belongs to the user
            $order = Order::where('id', $orderId)
                ->where('user_id', auth('sanctum')->id())
                ->first();

            if (!$order) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ]);
            }

            // Check if trip should be visible for this order status
            if (!$order->isTripVisible()) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ORDER_TRIP_NOT_FOUND, locale: $this->language)
                ]);
            }

            // Get the first trip for this order with relationships
            $trip = $order->trips()->with(['driver', 'vehicle', 'locations'])->first();
            
            if (!$trip) {
                // Create a new trip for the order if missing, regardless of status
                // Format address as a string if it's an array
                $addressString = 'Unknown';
                if (isset($order->address)) {
                    if (is_array($order->address)) {
                        $addressString = implode(', ', array_filter([
                            $order->address['address'] ?? '',
                            $order->address['house'] ?? '',
                            $order->address['street'] ?? '',
                            $order->address['city'] ?? '',
                        ]));
                        
                        if (empty(trim($addressString))) {
                            $addressString = 'Address from Order #' . $order->id;
                        }
                    } else {
                        $addressString = (string)$order->address;
                    }
                }

                // Get customer coordinates
                $customerLat = is_array($order->location) ? 
                            ($order->location['lat'] ?? $order->location['latitude'] ?? 0) : 0;
                $customerLng = is_array($order->location) ? 
                            ($order->location['lng'] ?? $order->location['longitude'] ?? 0) : 0;

                // Set appropriate trip status based on order status
                $tripStatus = 'planned';
                if ($order->status === Order::STATUS_DELIVERED) {
                    $tripStatus = 'completed';
                } else if ($order->status === Order::STATUS_ON_A_WAY || $order->status === Order::STATUS_SHIPPED) {
                    $tripStatus = 'in_progress';
                }

                // Create a new Trip for the order
                $trip = Trip::create([
                    'name' => 'Trip for Order #' . $order->id,
                    'start_address' => $addressString,
                    'start_lat' => $customerLat,
                    'start_lng' => $customerLng,
                    'scheduled_at' => now(),
                    'status' => $tripStatus,
                    'meta' => [
                        'created_for_order_id' => $order->id,
                        'estimated_travel_time' => 30,  // Default 30 minutes
                        'estimate_method' => 'default'
                    ]
                ]);

                // Associate the order with the trip
                $order->trips()->attach($trip->id, [
                    'sequence' => 1, 
                    'status' => $order->status === Order::STATUS_DELIVERED ? 'delivered' : 'pending'
                ]);
                
                // Reload the trip with relationships
                $trip->load(['driver', 'vehicle', 'locations']);
            }

            // If trip exists but order is delivered, update trip status to completed
            if ($trip && $order->status === Order::STATUS_DELIVERED && $trip->status !== 'completed') {
                $trip->update(['status' => 'completed']);
                $order->trips()->updateExistingPivot($trip->id, ['status' => 'delivered']);
                $trip->refresh();
            }
            
            // If there are no locations, create a default one using shop location
            if ($trip->locations->isEmpty() && $order->shop) {
                $shop = $order->shop;
                
                // Get shop coordinates
                $shopLat = is_array($shop->location) ? 
                        ($shop->location['lat'] ?? ($shop->location['latitude'] ?? 0)) : 0;
                $shopLng = is_array($shop->location) ? 
                        ($shop->location['lng'] ?? ($shop->location['longitude'] ?? 0)) : 0;
                
                // Calculate estimated travel time if we have coordinates
                $eta = 30; // Default value
                if ($trip->start_lat && $trip->start_lng && $shopLat && $shopLng) {
                    // Calculate distance in kilometers
                    $earthRadius = 6371; // km
                    $dLat = deg2rad($shopLat - $trip->start_lat);
                    $dLng = deg2rad($shopLng - $trip->start_lng);
                    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($trip->start_lat)) * cos(deg2rad($shopLat)) * sin($dLng/2) * sin($dLng/2);
                    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                    $distance = $earthRadius * $c;
                    
                    // Rough estimate: 20 km/h speed in city traffic = 1 km takes 3 minutes
                    $eta = round(max(15, $distance * 3)); // Minimum 15 minutes
                    
                    // Update trip meta with calculated ETA
                    $meta = $trip->meta ?? [];
                    $meta['estimated_travel_time'] = $eta;
                    $meta['distance_km'] = round($distance, 2);
                    $meta['estimate_method'] = 'distance_based';
                    $trip->update(['meta' => $meta]);
                }
                
                // Create the location
                $trip->locations()->create([
                    'address' => $shop->address ?? 'Delivery destination', 
                    'lat' => $shopLat,
                    'lng' => $shopLng,
                    'sequence' => 0,
                    'eta_minutes' => $eta,
                    'status' => $order->status === Order::STATUS_DELIVERED ? 'arrived' : 'pending'
                ]);
                
                // Reload locations
                $trip->load('locations');
            }

            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                TripResource::make($trip)
            );
        } catch (\Exception $e) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_400,
                'message' => $e->getMessage()
            ]);
        }
    }
} 