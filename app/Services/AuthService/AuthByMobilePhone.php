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
        try {
            \Log::debug('Starting confirmOPTCode', ['input' => array_merge($array, ['verifyCode' => '***'])]);
            
            $isFirebase = data_get($array, 'type') === 'firebase';
            $data = [];
            $user = null;

            if (!$isFirebase) {
                // Standard OTP flow
                $data = SmsCode::where("verifyId", data_get($array, 'verifyId'))->first();
                \Log::debug('SmsCode query result', ['found' => (bool)$data, 'verifyId' => data_get($array, 'verifyId')]);
                
                if (empty($data)) {
                    \Log::error('SmsCode not found', ['verifyId' => data_get($array, 'verifyId')]);
                    return $this->onErrorResponse([
                        'code'    => ResponseError::ERROR_404,
                        'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                    ]);
                }
                
                if (Carbon::parse($data->expiredAt) < now()) {
                    \Log::error('OTP code expired', [
                        'expiredAt' => $data->expiredAt,
                        'now' => now()
                    ]);
                    return $this->onErrorResponse([
                        'code'    => ResponseError::ERROR_203,
                        'message' => __('errors.' . ResponseError::ERROR_203, locale: $this->language)
                    ]);
                }
                
                if ($data->OTPCode != data_get($array, 'verifyCode')) {
                    \Log::error('Invalid OTP code', [
                        'expected' => $data->OTPCode,
                        'received' => data_get($array, 'verifyCode')
                    ]);
                    return $this->onErrorResponse([
                        'code'    => ResponseError::ERROR_201,
                        'message' => __('errors.' . ResponseError::ERROR_201, locale: $this->language)
                    ]);
                }
                
                // Cleanup the used OTP
                $data->delete();
                
                // Find or create user
                $user = $this->model()->where('phone', $data->phone)->first();
                \Log::debug('User lookup result', ['phone' => $data->phone, 'user_found' => (bool)$user]);
                
                if (!$user) {
                    // Create new user with minimal required fields
                    $user = $this->model()->create([
                        'firstname'  => $data->phone, // Default to phone as firstname
                        'phone'      => $data->phone,
                        'ip_address' => request()->ip(),
                        'auth_type'  => 'phone',
                        'active'     => true,
                        'phone_verified_at' => now(),
                    ]);
                    \Log::info('New user created during OTP verification', ['user_id' => $user->id]);
                }
                
            } else {
                // Firebase OTP flow
                $phone = data_get($array, 'phone');
                if (empty($phone)) {
                    return $this->onErrorResponse([
                        'code'    => ResponseError::ERROR_400,
                        'message' => 'Phone number is required'
                    ]);
                }
                
                // Find or create user
                $user = $this->model()->where('phone', $phone)->first();
                \Log::debug('Firebase user lookup', ['phone' => $phone, 'user_found' => (bool)$user]);
                
                if (!$user) {
                    // Create new user with provided data
                    $userData = [
                        'firstname'  => data_get($array, 'firstname', $phone),
                        'lastname'   => data_get($array, 'lastname', ''),
                        'email'      => data_get($array, 'email'),
                        'phone'      => $phone,
                        'password'   => bcrypt(data_get($array, 'password', Str::random(10))),
                        'ip_address' => request()->ip(),
                        'auth_type'  => 'firebase',
                        'active'     => true,
                        'phone_verified_at' => now(),
                    ];
                    
                    $user = $this->model()->create($userData);
                    \Log::info('New user created during Firebase OTP verification', ['user_id' => $user->id]);
                }
                
                // Clean up any existing verification codes
                $d = SmsCode::where("verifyId", data_get($array, 'verifyId'))->first();
                if ($d) {
                    $d->delete();
                }
            }
            
            // Ensure user has a role
            $this->ensureUserHasRole($user);
            
            // Ensure user has a wallet
            if (empty($user->wallet?->uuid)) {
                $user = (new UserWalletService)->create($user);
            }
            
            // Generate auth token
            $token = $user->createToken('api_token')->plainTextToken;
            
            return $this->successResponse(__('errors.'. ResponseError::SUCCESS, locale: $this->language), [
                'token' => $token,
                'user'  => UserResource::make($user),
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Error in confirmOPTCode', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'input' => array_merge($array, ['verifyCode' => '***'])
            ]);
            
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_400,
                'message' => 'An error occurred during authentication. Please try again.'
            ]);
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

        // Debug: Log user object
        \Log::debug('User object in confirmOPTCode', [
            'user_id' => $user ? $user->id : null,
            'user_exists' => (bool)$user,
            'user_class' => $user ? get_class($user) : 'null'
        ]);

        if (!$user) {
            \Log::error('Cannot assign roles: User object is null in confirmOPTCode');
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_404,
                'message' => 'User not found',
            ]);
        }

        try {
            // Debug: Before getting roles
            \Log::debug('Attempting to get roles');
            $roles = Role::pluck('name')->toArray();
            
            // Debug: After getting roles
            \Log::debug('Roles retrieved', ['roles' => $roles]);
            
            if (empty($roles) || !$user->hasAnyRole($roles)) {
                // Debug: Before syncing roles
                \Log::debug('Syncing user roles to "user"', ['user_id' => $user->id]);
                $user->syncRoles('user');
                // Debug: After syncing roles
                \Log::debug('Successfully synced roles for user', ['user_id' => $user->id]);
            }
        } catch (\Exception $e) {
            // Log the full error with stack trace
            \Log::error('Error in confirmOPTCode role assignment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user ? $user->id : null
            ]);
            
            // Only try to sync roles if user exists and we can verify the method exists
            if ($user && method_exists($user, 'syncRoles')) {
                try {
                    $user->syncRoles('user');
                } catch (\Exception $syncError) {
                    \Log::error('Failed to sync roles in error handler', [
                        'error' => $syncError->getMessage(),
                        'user_id' => $user->id
                    ]);
                }
            }
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

    /**
     * @param array $array
     * @return JsonResponse
     */
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

    /**
     * Ensure the user has a default role assigned
     * 
     * @param \App\Models\User $user
     * @return void
     * @throws \Exception
     */
    protected function ensureUserHasRole($user)
    {
        try {
            if (!$user) {
                throw new \Exception('User object is null');
            }
            
            // Check if user has any roles
            if ($user->roles->isEmpty()) {
                // Get the default role (usually 'user')
                $defaultRole = Role::where('name', 'user')->first();
                
                if (!$defaultRole) {
                    // Create the default role if it doesn't exist
                    $defaultRole = Role::create(['name' => 'user', 'guard_name' => 'api']);
                    \Log::info('Created default user role');
                }
                
                // Assign the role to the user
                $user->syncRoles([$defaultRole->name]);
                \Log::info('Assigned default role to user', [
                    'user_id' => $user->id,
                    'role' => $defaultRole->name
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Error ensuring user role', [
                'user_id' => $user->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
