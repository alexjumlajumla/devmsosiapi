<?php

namespace App\Http\Resources;

use App\Helpers\Utility;
use App\Http\Resources\Booking\TableResource;
use App\Models\User;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        /** @var User|UserResource $this */

        $reviewsGroup = null;

        if ($request->input('ReviewCountGroup')) {
            $reviewsGroup = $this->prepareReviewCountGroup($this->id);
        }

        return [
            'id'                    => $this->id,
            'uuid'                  => $this->when($this->uuid, $this->uuid),
            'firstname'             => $this->firstname,
            'auth_type'             => $this->auth_type ==  null ? 'email' : $this->auth_type,
            'lastname'              => $this->lastname,
            'empty_p'               => empty($this->password),
            'email'                 => $this->when($this->email, $this->email),
            'kitchen_id'            => $this->when($this->kitchen_id, $this->kitchen_id),
            'isWork'                => $this->when($this->isWork, $this->isWork),
            'phone'                 => $this->phone,
            'birthday'              => $this->when($this->birthday, function() {
                $birthday = $this->birthday;
                if (is_string($birthday)) {
                    try {
                        $birthday = \Carbon\Carbon::parse($birthday);
                    } catch (\Exception $e) {
                        return $birthday;
                    }
                }
                return $birthday ? $birthday->format('Y-m-d H:i:s') . 'Z' : null;
            }),
            'gender'                => $this->when($this->gender, $this->gender),
            'active'                => $this->active,
            'img'                   => $this->when($this->img, $this->img),
            'referral'              => $this->when($this->referral, $this->referral),
            'my_referral'           => $this->when($this->my_referral, $this->my_referral),
            'role'                  => $this->when($this->role, $this->role),
            'email_verified_at'     => $this->when($this->email_verified_at, function() {
                $date = $this->email_verified_at;
                if (is_string($date)) {
                    try {
                        $date = \Carbon\Carbon::parse($date);
                    } catch (\Exception $e) {
                        return $date;
                    }
                }
                return $date ? $date->format('Y-m-d H:i:s') . 'Z' : null;
            }),
            'phone_verified_at'     => $this->when($this->phone_verified_at, function() {
                $date = $this->phone_verified_at;
                if (is_string($date)) {
                    try {
                        $date = \Carbon\Carbon::parse($date);
                    } catch (\Exception $e) {
                        return $date;
                    }
                }
                return $date ? $date->format('Y-m-d H:i:s') . 'Z' : null;
            }),
            'registered_at'         => $this->when($this->created_at, function() {
                $date = $this->created_at;
                if (is_string($date)) {
                    try {
                        $date = \Carbon\Carbon::parse($date);
                    } catch (\Exception $e) {
                        return $date;
                    }
                }
                return $date ? $date->format('Y-m-d H:i:s') . 'Z' : null;
            }),
            'orders_sum_price'      => $this->when($this->orders_sum_total_price, $this->orders_sum_total_price),
            'delivery_man_orders_count' => $this->when($this->delivery_man_orders_count, $this->delivery_man_orders_count),
            'delivery_man_orders_sum_total_price' => $this->when($this->delivery_man_orders_sum_total_price,
                $this->delivery_man_orders_sum_total_price
            ),
            'reviews_avg_rating' => $this->when($this->reviews_avg_rating, $this->reviews_avg_rating),
            'reviews_count'      => $this->when($this->reviews_count, $this->reviews_count),
            'assign_reviews_avg_rating' => $this->when($this->assign_reviews_avg_rating,
                $this->assign_reviews_avg_rating
            ),
            'review_count_by_rating'        => $this->when($reviewsGroup, $reviewsGroup),
            'created_at'                    => $this->when($this->created_at, function() {
                $date = $this->created_at;
                if (is_string($date)) {
                    try {
                        $date = \Carbon\Carbon::parse($date);
                    } catch (\Exception $e) {
                        return $date;
                    }
                }
                return $date ? $date->format('Y-m-d H:i:s') . 'Z' : null;
            }),
            'updated_at'                    => $this->when($this->updated_at, function() {
                $date = $this->updated_at;
                if (is_string($date)) {
                    try {
                        $date = \Carbon\Carbon::parse($date);
                    } catch (\Exception $e) {
                        return $date;
                    }
                }
                return $date ? $date->format('Y-m-d H:i:s') . 'Z' : null;
            }),
            'deleted_at'                    => $this->when($this->deleted_at, function() {
                $date = $this->deleted_at;
                if (is_string($date)) {
                    try {
                        $date = \Carbon\Carbon::parse($date);
                    } catch (\Exception $e) {
                        return $date;
                    }
                }
                return $date ? $date->format('Y-m-d H:i:s') . 'Z' : null;
            }),
            'referral_from_topup_price'     => $this->when(request('referral'), $this->referral_from_topup_price),
            'referral_from_withdraw_price'  => $this->when(request('referral'), $this->referral_from_withdraw_price),
            'referral_to_withdraw_price'    => $this->when(request('referral'), $this->referral_to_withdraw_price),
            'referral_to_topup_price'       => $this->when(request('referral'), $this->referral_to_topup_price),
            'referral_from_topup_count'     => $this->when(request('referral'), $this->referral_from_topup_count),
            'referral_from_withdraw_count'  => $this->when(request('referral'), $this->referral_from_withdraw_count),
            'referral_to_withdraw_count'    => $this->when(request('referral'), $this->referral_to_withdraw_count),
            'referral_to_topup_count'       => $this->when(request('referral'), $this->referral_to_topup_count),
            'orders'                        => OrderResource::collection($this->whenLoaded('orders')),
            'orders_count'                  => $this->when($this->orders_count, $this->orders_count),
            'orders_sum_total_price'        => $this->whenLoaded($this->orders_sum_total_price, $this->orders_sum_total_price),

            'deliveryman_orders'            => OrderResource::collection($this->whenLoaded('deliveryManOrders')),
            'delivery_man_delivery_zone'    => DeliveryManDeliveryZoneResource::make($this->whenLoaded('deliveryManDeliveryZone')),
            'email_subscribe'               => $this->whenLoaded('emailSubscription'),
            'notifications'                 => $this->whenLoaded('notifications'),
            'shop'                          => ShopResource::make($this->whenLoaded('shop')),
            'kitchen'                       => KitchenResource::make($this->whenLoaded('kitchen')),
            'tables'                        => TableResource::collection($this->whenLoaded('waiterTables')),
            'shops'                         => ShopResource::collection($this->whenLoaded('shops')),
            'wallet'                        => WalletResource::make($this->whenLoaded('wallet')),
            'point'                         => UserPointResource::make($this->whenLoaded('point')),
            'reviews'                       => ReviewResource::collection($this->whenLoaded('reviews')),
            'assign_reviews'                => ReviewResource::collection($this->whenLoaded('assignReviews')),
            'invitations'                   => InviteResource::collection($this->whenLoaded('invitations')),
            'invite'                        => InviteResource::make($this->whenLoaded('invite')),
            'delivery_man_setting'          => DeliveryManSettingResource::make($this->whenLoaded('deliveryManSetting')),
            'logs'                          => ModelLogResource::collection($this->whenLoaded('logs')),
            'addresses'                     => UserAddressResource::collection($this->whenLoaded('addresses')),
            'models'                        => RequestModelResource::collection($this->whenLoaded('models')),
            'model'                         => RequestModelResource::make($this->whenLoaded('model')),
        ];
    }

    /**
     * @param $userId
     * @return array[]
     */
    public function prepareReviewCountGroup($userId): array
    {
        $reviews = DB::table('reviews')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->select([
                DB::raw('count(id) as count, rating')
            ])
            ->groupBy(['rating'])
            ->get();

        return Utility::groupRating($reviews);
    }
}
