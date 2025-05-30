<?php

namespace App\Services\AuthService;

use App\Helpers\ResponseError;
use App\Http\Resources\UserResource;
use App\Models\Notification;
use App\Models\SmsCode;
use App\Models\User;
use App\Services\CoreService;
use App\Services\SMSGatewayService\SMSBaseService;
use App\Services\UserServices\UserWalletService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Throwable;

class AuthByMobilePhone extends CoreService
{
    /**
     * @return string
     */
    protected function getModelClass(): string
    {
        return User::class;
    }

    /**
     * @param array $array
     * @return JsonResponse
     */
  
	
	
	public function authentication(array $array): JsonResponse
    {
        $phone = preg_replace('/\D/', '', data_get($array, 'phone'));
        $phone = str_contains($phone, '+') ? substr($phone, 1) : $phone;
    
        $user = $this->model()->where('phone', $phone)->first();
    
        if ($user) {
            // Update existing user
            $user->update([
                'phone'         => $phone,
                'ip_address'    => request()->ip(),
                'auth_type'     => "phone",
                // Only update firstname if it doesn't already exist
                'firstname'     => $user->firstname ?: $phone,
            ]);
            
            try {
                // Check if user has any role, if not assign 'user' role
                $roles = Role::pluck('name')->toArray();
                if (empty($roles) || !$user->hasAnyRole($roles)) {
                    $user->syncRoles('user');
                }
            } catch (\Exception $e) {
                // If there's any error with roles, just log it and continue
                \Log::error('Error assigning user role: ' . $e->getMessage());
                $user->syncRoles('user');
            }
        } else {
            // Create new user with default 'user' role
            $user = $this->model()->create([
                'firstname'     => $phone,
                'phone'         => $phone,
                'ip_address'    => request()->ip(),
                'auth_type'     => "phone"
            ]);
            
            // Assign default 'user' role
            $user->syncRoles('user');
        }
    
        $sms = (new SMSBaseService)->smsGateway($phone);
    
        if (!data_get($sms, 'status')) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_400,
                'message' => data_get($sms, 'message', '')
            ]);
        }
    
        return $this->successResponse(__('errors.' . ResponseError::SUCCESS, locale: $this->language), [
            'verifyId'  => data_get($sms, 'verifyId'),
            'phone'     => data_get($sms, 'phone'),
            'message'   => data_get($sms, 'message', '')
        ]);
    }

	/**
	 * @param array $array
	 * @return JsonResponse
	 * @todo REMOVE IN THE FUTURE
	 */
    public function confirmOPTCode(array $array): JsonResponse
    {
        if (data_get($array, 'type') !== 'firebase') {

            $data = SmsCode::where("verifyId", data_get($array, 'verifyId'))->first();
            
            if (empty($data)) {
                return $this->onErrorResponse([
                    'code'      => ResponseError::ERROR_404,
                    'message'   => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                ]);
            }
            if (Carbon::parse(data_get($data, 'expiredAt')) < now()) {
                return $this->onErrorResponse([
                    'code'      => ResponseError::ERROR_203,
                    'message'   => __('errors.' . ResponseError::ERROR_203, locale: $this->language)
                ]);
            }
            if (data_get($data, 'OTPCode') != data_get($array, 'verifyCode')) {
                return $this->onErrorResponse([
                    'code'      => ResponseError::ERROR_201,
                    'message'   => __('errors.' . ResponseError::ERROR_201, locale: $this->language)
                ]);
            }
            $data->delete();
            $user = $this->model()->where('phone', data_get($data, 'phone'))->first();

        } else {
            $data['phone']      = data_get($array, 'phone');
            $data['email']      = data_get($array, 'email');
            $data['referral']   = data_get($array, 'referral');
            $data['firstname']  = data_get($array, 'firstname');
            $data['lastname']   = data_get($array, 'lastname');
            $data['password']   = data_get($array, 'password');
            $data['gender']     = data_get($array, 'gender', 'male');

            $d = SmsCode::where("verifyId", data_get($array, 'verifyId'))->first();
            if($d) $d->delete();
            
        }

        // If email already belongs to another user, return error early
        $verifiedPhone = data_get($data, 'phone');
        $submittedEmail = data_get($data, 'email');

        if ($submittedEmail) {
            $emailExistsForOther = $this->model()
                ->where('email', $submittedEmail)
                ->where('phone', '!=', $verifiedPhone)
                ->exists();

            if ($emailExistsForOther) {
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_106, // User already exists (email)
                    'message' => __('errors.' . ResponseError::ERROR_106, locale: $this->language),
                ]);
            }
        }

        if (empty($user)) {
			try {
				$user = $this->model()
					->withTrashed()
					->updateOrCreate([
						'phone'             => data_get($data, 'phone')
					], [
						'phone'             => data_get($data, 'phone'),
						'email'             => data_get($data, 'email'),
						'referral'          => data_get($data, 'referral'),
						'active'            => 1,
						'phone_verified_at' => now(),
						'deleted_at'        => null,
						'firstname'         => data_get($data, 'firstname'),
						'lastname'          => data_get($data, 'lastname'),
						'gender'            => data_get($data, 'gender'),
						'password'          => bcrypt(data_get($data, 'password', 'password')),
					]);
			} catch (Throwable $e) {
				$this->error($e);
				return $this->onErrorResponse([
					'code'    => ResponseError::ERROR_400,
					'message' => 'Email or phone already exist',
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
        }

        if ($user) {
            try {
                // Check if user has any role, if not assign 'user' role
                $roles = Role::pluck('name')->toArray();
                if (empty($roles) || !$user->hasAnyRole($roles)) {
                    $user->syncRoles('user');
                }
            } catch (\Exception $e) {
                // If there's any error with roles, just log it and continue
                \Log::error('Error assigning user role in confirmOPTCode: ' . $e->getMessage());
                if ($user) {
                    $user->syncRoles('user');
                }
            }
        } else {
            \Log::error('Cannot assign roles: User object is null in confirmOPTCode');
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_404,
                'message' => 'User not found',
            ]);
        }

        if(empty($user->wallet?->uuid)) {
            $user = (new UserWalletService)->create($user);
        }

        $token = $user->createToken('api_token')->plainTextToken;

        return $this->successResponse(__('errors.'. ResponseError::SUCCESS, locale: $this->language), [
            'token' => $token,
            'user'  => UserResource::make($user),
        ]);

    }

    public function forgetPasswordVerify(array $data): JsonResponse
    {
        $user = User::withTrashed()->where('phone', str_replace('+', '', data_get($data, 'phone')))->first();

		if (empty($user)) {
			return $this->onErrorResponse(['code' => ResponseError::ERROR_404]);
		}

        if (!empty($user->deleted_at)) {
            $user->update([
                'deleted_at' => null
            ]);
        }

        $token = $user->createToken('api_token')->plainTextToken;

        return $this->successResponse(__('errors.'. ResponseError::SUCCESS, locale: $this->language), [
            'token' => $token,
            'user'  => UserResource::make($user),
        ]);
    }

    public function confirmOTP(array $array): JsonResponse
    {   
        $data = SmsCode::where("verifyId", data_get($array, 'verifyId'))->first();

        if (empty($data)) {
            return $this->onErrorResponse([
                'code'      => ResponseError::ERROR_404,
                'message'   => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
            ]);
        }

        if (Carbon::parse(data_get($data, 'expiredAt')) < now()) {
            return $this->onErrorResponse([
                'code'      => ResponseError::ERROR_203,
                'message'   => __('errors.' . ResponseError::ERROR_203, locale: $this->language)
            ]);
        }

        if (data_get($data, 'OTPCode') != data_get($array, 'verifyCode')) {
            return $this->onErrorResponse([
                'code'      => ResponseError::ERROR_201,
                'message'   => __('errors.' . ResponseError::ERROR_201, locale: $this->language)
            ]);
        }

        $data->delete();

        return $this->successResponse(__('errors.'. ResponseError::SUCCESS, locale: $this->language), [
            'result' => true,
            'message'  => 'Verified',
        ]);

    }

}
