<?php

namespace App\Services\UserServices;

use App\Helpers\ResponseError;
use App\Models\Booking\Table;
use App\Models\Notification;
use App\Models\User;
use App\Services\CoreService;
use App\Services\Interfaces\UserServiceInterface;
use DB;
use Exception;
use Throwable;

class UserService extends CoreService implements UserServiceInterface
{
    /**
     * @return string
     */
    protected function getModelClass(): string
    {
        return User::class;
    }

    /**
     * @param array $data
     * @return array
     */
    public function create(array $data): array
    {
        try {
            $password = bcrypt(data_get($data, 'password', 'password'));

            unset($data['password']);

            if (!empty(data_get($data, 'firebase_token'))) {
                $data['firebase_token'] = [data_get($data, 'firebase_token')];
            }

            /** @var User $user */
            $user = $this->model()->create($data + [
                'password'   => $password,
                'ip_address' => request()->ip()
            ]);

            if (data_get($data, 'images.0')) {
                $user->update(['img' => data_get($data, 'images.0')]);
                $user->uploads(data_get($data, 'images'));
            }

            $user->syncRoles(data_get($data, 'role', 'user'));

            if ($user->hasRole(['moderator', 'deliveryman', 'waiter', 'cook']) && is_array(data_get($data, 'shop_id'))) {

                foreach (data_get($data, 'shop_id') as $shopId) {

                    $user->invitations()->withTrashed()->updateOrCreate([
                        'shop_id' => $shopId,
                    ], [
                        'deleted_at' => null
                    ]);
                }

            }

            if ($user->hasRole('cook')) {

                $user->update([
                    'kitchen_id' => data_get($data, 'kitchen_id'),
                ]);

            }

            $ids = Notification::pluck('id')->toArray();

            if ($ids) {
                $user->notifications()->sync($ids);
            } else {
                $user->notifications()->forceDelete();
            }

            $user->emailSubscription()->updateOrCreate([
                'user_id' => $user->id
            ], [
                'active' => true
            ]);

			if (data_get($data, 'table_ids')) {

				$user->waiterTables()
					->when(data_get($data,'shop_id'), function ($q, $shopId) {
						$q->whereHas('shopSection', fn($q) => $q->where('shop_id', $shopId));
					})
					->sync((array)$data['table_ids']);

			}

            $user = (new UserWalletService)->create($user);

            return [
                'status' => true,
                'code'   => ResponseError::NO_ERROR,
                'data'   => $user->loadMissing(['invitations', 'roles'])
            ];
        } catch (Throwable $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_501,
                'message' => __('errors.' . ResponseError::ERROR_501, locale: $this->language)
            ];
        }
    }

    public function update(string $uuid, array $data): array
    {
        /** @var User $user */
        $user = $this->model()->firstWhere('uuid', $uuid);

        if (!$user) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
            ];
        }

        try {

            if (!empty(data_get($data, 'password'))) {

                $password = bcrypt(data_get($data, 'password', 'password'));

				$data['password'] = $password;

            }

			if (data_get($data, 'firebase_token')) {
				$token = is_array($user->firebase_token) ? $user->firebase_token : [];
				$data['firebase_token'] = array_push($token, data_get($data, 'firebase_token'));
			}

			$item = $user->update($data);

            if (data_get($data, 'subscribe') !== null) {

                $user->emailSubscription()->updateOrCreate([
                    'user_id' => $user->id
                ], [
                    'active' => !!data_get($data, 'subscribe')
                ]);

            }

            if (!empty(data_get($data, 'notifications'))) {
                $user->notifications()->sync(data_get($data, 'notifications'));
            }

            if ($item && data_get($data, 'images.0')) {

                $user->galleries()->delete();
                $user->update(['img' => data_get($data, 'images.0')]);
                $user->uploads(data_get($data, 'images'));

            }

            if ($item && data_get($data, 'table_ids')) {

				$user->waiterTables()
					->when(data_get($data,'shop_id'), function ($q, $shopId) {
						$q->whereHas('shopSection', fn($q) => $q->where('shop_id', $shopId));
					})
					->sync((array)$data['table_ids']);

            }

            if ($user->hasRole(['moderator', 'deliveryman', 'waiter', 'cook']) && is_array(data_get($data, 'shop_id'))) {

                $user->invitations()->delete();

                foreach (data_get($data, 'shop_id') as $shopId) {

                    $user->invitations()->withTrashed()->updateOrCreate([
                        'shop_id' => $shopId,
                    ], [
                        'deleted_at' => null
                    ]);

                }

            }

            return [
                'status'    => true,
                'code'      => ResponseError::NO_ERROR,
                'data'      => $user->loadMissing([
                    'emailSubscription', 'notifications', 'invitations', 'roles', 'wallet'
                ])
            ];
        } catch (Exception $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

    public function attachTable(string $uuid, array $data): array
    {
        /** @var User $user */
        $user = $this->model()->firstWhere('uuid', $uuid);

        if (!$user) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
            ];
        }

        if (!$user->isWork) {
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_511,
                'message' => __('errors.' . ResponseError::ERROR_511, locale: $this->language)
            ];
        }

        if (data_get($data,'shop_id')) {
            $table = Table::whereHas('shopSection', function ($query) use ($data) {
                $query->where('shop_id', $data['shop_id']);
            })->find($data['table_id']);

            if (!$table) {
                return [
                    'status'  => false,
                    'code'    => ResponseError::ERROR_404,
                    'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ];
            }
        }

        try {

			$user->waiterTables()
				->when(data_get($data,'shop_id'), function ($q, $shopId) {
					$q->whereHas('shopSection', fn($q) => $q->where('shop_id', $shopId));
				})
				->sync((array)$data['table_ids']);

            return [
                'status' => true,
                'code'   => ResponseError::NO_ERROR,
                'data'   => $user
            ];
        } catch (Exception $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

    public function updatePassword($uuid, $password): array
    {
        $user = $this->model()->firstWhere('uuid', $uuid);

        if (!$user) {
            return ['status' => false, 'code' => ResponseError::ERROR_404];
        }

        try {
            $user->update(['password' => bcrypt($password)]);

            return ['status' => true, 'code' => ResponseError::NO_ERROR, 'data' => $user];
        } catch (Exception $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_502,
                'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
            ];
        }
    }

    public function updateNotifications(array $data): array
    {
		try {
			/** @var User $user */
			$user = auth('sanctum')->user();

			DB::table('notification_user')->where('user_id', $user->id)->delete();

			$user->notifications()->attach(data_get($data, 'notifications'));

			return [
				'status' => true,
				'code'   => ResponseError::NO_ERROR,
				'data'   => $user->fresh('notifications')
			];
		} catch (Throwable $e) {
			$this->error($e);
			return [
				'status'  => false,
				'code'    => ResponseError::ERROR_502,
				'message' => __('errors.' . ResponseError::ERROR_502, locale: $this->language)
			];
		}
    }

    public function delete(?array $ids = []): array
    {

        $errors = [];

        foreach (User::find($ids) as $user) {
            try {
                $user->update([
                    'firebase_token' => null,
                    'deleted_at'     => now()
                ]);
//                $user->delete();
            } catch (Throwable) {
                $errors[] = "$user->firstname $user->lastname";
            }
        }

        return count($errors) === 0 ? [
            'status' => true,
            'code'   => ResponseError::NO_ERROR
        ] : [
            'status' => true,
            'code'   =>  ResponseError::ERROR_501,
            'message'   => __('errors.' . ResponseError::CANT_DELETE_ORDERS, [
                'ids' => implode(', ', $errors)
            ], locale: $this->language)
        ];
    }

}
