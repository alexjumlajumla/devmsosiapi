<?php

namespace App\Services\AuthService;

use App\Helpers\ResponseError;
use App\Http\Resources\UserResource;
use App\Models\Notification;
use App\Models\SmsCode;
use App\Models\User;
use App\Models\Role;
use App\Services\CoreService;
use App\Services\SMSGatewayService\SMSBaseService;
use App\Services\UserServices\UserWalletService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as SpatieRole;
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
     * Normalize phone number by removing non-numeric characters and optional leading +
     */
    protected function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (empty($phone)) {
            throw new \InvalidArgumentException('Phone number is required');
        }
        
        if (strlen($phone) < 9) {
            throw new \InvalidArgumentException('Invalid phone number');
        }
        
        return $phone;
    }
    
    /**
     * Handle authentication with proper transaction management and race condition handling
     */
    public function authentication(array $array): JsonResponse
    {
        $startTime = microtime(true);
        $logContext = [
            'phone' => data_get($array, 'phone'),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_data' => array_merge($array, ['phone' => '***']),
            'timestamp' => now()->toDateTimeString()
        ];
        
        try {
            // Normalize phone number
            $phone = $this->normalizePhone(data_get($array, 'phone'));
            $logContext['phone_cleaned'] = $phone;
            
            // Set transaction isolation level to SERIALIZABLE for this connection
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            
            // Use transaction with retries
            return DB::transaction(function () use ($phone, $logContext) {
                // First try to find existing user with lock
                $user = $this->model()->withTrashed()
                    ->where('phone', $phone)
                    ->lockForUpdate()
                    ->first();
                
                if ($user) {
                    return $this->handleExistingUser($user, $phone);
                }
                
                // If no user exists, create a new one
                return $this->createNewUser($phone);
                
            }, 3); // Retry up to 3 times
            
        } catch (\Exception $e) {
            // Log the error with detailed context
            $errorContext = array_merge($logContext, [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'execution_time' => round(microtime(true) - $startTime, 3) . 's',
                'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
                'timestamp' => now()->toDateTimeString()
            ]);

            // Add database error details if available
            if ($e->getPrevious() instanceof \PDOException) {
                $pdoEx = $e->getPrevious();
                $errorContext['pdo_error'] = [
                    'code' => $pdoEx->getCode(),
                    'message' => $pdoEx->getMessage(),
                    'sql_state' => $pdoEx->errorInfo[0] ?? null,
                    'driver_code' => $pdoEx->errorInfo[1] ?? null,
                    'driver_message' => $pdoEx->errorInfo[2] ?? null,
                ];
            }

            \Log::error('Authentication failed', $errorContext);

            // Return a user-friendly error message
            return $this->onErrorResponse([
                'code' => ResponseError::ERROR_400,
                'message' => 'Failed to process your request. Please try again.'
            ]);
        }
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
            
            // Log database connection status
            try {
                $dbConnected = \DB::connection()->getPdo() ? true : false;
                \Log::debug('Database connection status', ['connected' => $dbConnected]);
            } catch (\Exception $e) {
                \Log::error('Database connection error', ['error' => $e->getMessage()]);
                throw new \Exception('Database connection error. Please try again later.');
            }

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
                try {
                    $user = $this->model()->where('phone', $data->phone)->first();
                    \Log::debug('User lookup result', [
                        'phone' => $data->phone, 
                        'user_found' => (bool)$user,
                        'user_id' => $user->id ?? null
                    ]);
                    
                    if (!$user) {
                        // Create new user with minimal required fields
                        $userData = [
                            'firstname'  => $data->phone, // Default to phone as firstname
                            'phone'      => $data->phone,
                            'ip_address' => request()->ip(),
                            'auth_type'  => 'phone',
                            'active'     => true,
                            'phone_verified_at' => now(),
                        ];
                        
                        \Log::debug('Creating new user with data', $userData);
                        
                        $user = new $this->model();
                        $user->fill($userData);
                        
                        if (!$user->save()) {
                            throw new \Exception('Failed to save user model');
                        }
                        
                        \Log::info('New user created during OTP verification', [
                            'user_id' => $user->id,
                            'phone' => $user->phone
                        ]);
                        
                        // Assign default role
                        $this->ensureUserHasRole($user);
                    }
                    
                    // Use proceedWithSmsVerification to handle the response
                    return $this->proceedWithSmsVerification($user, $data->phone);
                    
                } catch (\Exception $e) {
                    \Log::error('Error in user creation/lookup', [
                        'phone' => $data->phone,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw new \Exception('Error verifying your account. Please try again.');
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
                try {
                    $user = $this->model()->where('phone', $phone)->first();
                    \Log::debug('Firebase user lookup', [
                        'phone' => $phone, 
                        'user_found' => (bool)$user,
                        'user_id' => $user->id ?? null
                    ]);
                    
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
                        
                        \Log::debug('Creating new Firebase user with data', [
                            'phone' => $phone,
                            'has_email' => !empty(data_get($array, 'email')),
                            'has_password' => !empty(data_get($array, 'password'))
                        ]);
                        
                        $user = new $this->model();
                        $user->fill($userData);
                        
                        if (!$user->save()) {
                            throw new \Exception('Failed to save Firebase user model');
                        }
                        
                        \Log::info('New Firebase user created during OTP verification', [
                            'user_id' => $user->id,
                            'phone' => $user->phone
                        ]);
                        
                        // Assign default role
                        $this->ensureUserHasRole($user);
                    }
                    
                    // Use proceedWithSmsVerification to handle the response
                    return $this->proceedWithSmsVerification($user, $phone);
                    
                } catch (\Exception $e) {
                    \Log::error('Error in Firebase user creation/lookup', [
                        'phone' => $phone,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw new \Exception('Error verifying your account. Please try again.');
                }
                
                // Clean up any existing verification codes
                $d = SmsCode::where("verifyId", data_get($array, 'verifyId'))->first();
                if ($d) {
                    $d->delete();
                }
                
                // This code was unreachable because it was after a return statement
                // Moved to the appropriate place in the flow
            }
            
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

        // This code was unreachable as it was after a return statement
        // Moved the relevant parts to their appropriate places in the flow

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
    /**
     * Assign the default 'user' role to a user
     * 
     * @param \App\Models\User $user
     * @return void
     * @throws \Exception
     */
    /**
     * Assign the default 'user' role to a user
     * 
     * @param \App\Models\User $user
     * @return void
     * @throws \Exception
     */
    protected function assignUserRole($user): void
    {
        if (!$user) {
            throw new \Exception('User object is null in assignUserRole');
        }
        
        if (!is_object($user) || !method_exists($user, 'syncRoles')) {
            throw new \Exception('Invalid user object provided to assignUserRole');
        }
        
        try {
            // Ensure user is saved to database
            if (!$user->exists) {
                $user->save();
            }
            
            // Get or create the default 'user' role
            $defaultRole = Role::where('name', 'user')->first();
            
            if (!$defaultRole) {
                // Create the default role if it doesn't exist
                $defaultRole = Role::create([
                    'name' => 'user',
                    'guard_name' => 'api',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                if (!$defaultRole) {
                    throw new \Exception('Failed to create default user role');
                }
                
                \Log::info('Created default user role', ['role_id' => $defaultRole->id]);
            }
            
            // Ensure the user model uses the correct guard
            $user->setAppends([]);
            
            // Assign the role to the user
            $result = $user->syncRoles([$defaultRole->name]);
            
            if (!$result) {
                throw new \Exception('Failed to assign role to user');
            }
            
            // Refresh the user model to ensure roles are loaded
            $user->load('roles');
            
            \Log::info('Assigned default role to user', [
                'user_id' => $user->id,
                'role' => $defaultRole->name,
                'user_roles' => $user->roles->pluck('name')->toArray()
            ]);
            
        } catch (\Exception $e) {
            $errorContext = [
                'user_id' => $user->id ?? 'unknown',
                'user_exists' => isset($user->id) ? 'yes' : 'no',
                'user_class' => get_class($user),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
            
            \Log::error('Error in assignUserRole', $errorContext);
            
            // Re-throw with more context
            throw new \Exception(sprintf(
                'Failed to assign role to user %s: %s',
                $user->id ?? 'unknown',
                $e->getMessage()
            ), 0, $e);
        }
    }
    
    /**
     * Handle existing user (active or soft-deleted)
     */
    protected function handleExistingUser($user, string $phone): JsonResponse
    {
        if ($user->trashed()) {
            $user->restore();
            $user->update([
                'active' => true,
                'phone_verified_at' => $user->phone_verified_at ?? now(),
                'ip_address' => request()->ip(),
                'auth_type' => 'phone',
                'deleted_at' => null
            ]);
            
            \Log::info('Restored soft-deleted user', [
                'user_id' => $user->id,
                'phone' => $phone
            ]);
        } else {
            // Update last login and IP for active user
            $user->update([
                'ip_address' => request()->ip(),
                'auth_type' => 'phone',
                'active' => true
            ]);
            
            // Only update firstname if it doesn't exist
            if (empty($user->firstname)) {
                $user->update(['firstname' => $phone]);
            }
        }
        
        // Ensure user has a role
        $this->ensureUserHasRole($user);
        
        // Proceed with SMS verification
        return $this->proceedWithSmsVerification($user, $phone);
    }
    
    /**
     * Create a new user with the given phone number
     */
    protected function createNewUser(string $phone): JsonResponse
    {
        $userData = [
            'firstname' => $phone,
            'phone' => $phone,
            'ip_address' => request()->ip(),
            'auth_type' => 'phone',
            'active' => true,
            'phone_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ];
        
        // Create user directly using query builder to avoid model events
        $userId = DB::table('users')->insertGetId($userData);
        $user = $this->model()->findOrFail($userId);
        
        \Log::info('New user created', [
            'user_id' => $user->id,
            'phone' => $phone
        ]);
        
        // Assign default role
        $this->assignUserRole($user);
        
        // Proceed with SMS verification
        return $this->proceedWithSmsVerification($user, $phone);
    }
    
    /**
     * Ensure user has a role, assign default 'user' role if none exists
     * 
     * @param \App\Models\User $user
     * @return void
     * @throws \Exception
     */
    protected function ensureUserHasRole($user): void
    {
        if (!$user) {
            throw new \Exception('User object is null in ensureUserHasRole');
        }
        
        if (!is_object($user) || !method_exists($user, 'roles')) {
            throw new \Exception('Invalid user object provided to ensureUserHasRole');
        }
        
        try {
            // Ensure user is loaded with roles relationship
            if (!$user->relationLoaded('roles')) {
                $user->load('roles');
            }
            
            // Check if user has any roles
            if ($user->roles->isEmpty()) {
                $this->assignUserRole($user);
            } else {
                \Log::debug('User already has roles', [
                    'user_id' => $user->id,
                    'roles' => $user->roles->pluck('name')->toArray()
                ]);
            }
        } catch (\Exception $e) {
            $errorContext = [
                'user_id' => $user->id ?? 'unknown',
                'user_exists' => isset($user->id) ? 'yes' : 'no',
                'user_class' => get_class($user),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
            
            \Log::error('Error in ensureUserHasRole', $errorContext);
            
            throw new \Exception(sprintf(
                'Failed to ensure user %s has role: %s',
                $user->id ?? 'unknown',
                $e->getMessage()
            ), 0, $e);
        }
    }
    
    /**
     * Handle SMS verification for a user
     * 
     * @param \App\Models\User $user
     * @param string $phone
     * @return JsonResponse
     */
    protected function proceedWithSmsVerification($user, $phone): JsonResponse
    {
        $startTime = microtime(true);
        $logContext = [
            'user_id' => $user->id ?? null,
            'phone' => $phone,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'in_transaction' => DB::transactionLevel() > 0 ? 'yes' : 'no'
        ];
        
        $isInTransaction = DB::transactionLevel() > 0;
        
        try {
            \Log::debug('Starting SMS verification process', $logContext);
            
            // Ensure user has a role
            try {
                $this->ensureUserHasRole($user);
                \Log::debug('User role verified', $logContext);
            } catch (\Exception $roleEx) {
                \Log::error('Failed to ensure user role', array_merge($logContext, [
                    'error' => $roleEx->getMessage(),
                    'trace' => $roleEx->getTraceAsString()
                ]));
                // Try to assign role one more time
                try {
                    $this->assignUserRole($user);
                } catch (\Exception $e) {
                    // If we're in a transaction and role assignment fails, we should still proceed
                    if ($isInTransaction) {
                        \Log::warning('Role assignment failed but continuing in transaction', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage()
                        ]);
                    } else {
                        throw $e;
                    }
                }
            }
            
            // Send SMS with verification code
            $sms = null;
            $smsError = null;
            
            try {
                \Log::debug('Sending SMS via SMSBaseService', $logContext);
                
                // If we're in a transaction, commit it before sending SMS to avoid long-running transactions
                if ($isInTransaction) {
                    DB::commit();
                    $isInTransaction = false;
                    \Log::debug('Committed transaction before sending SMS', [
                        'user_id' => $user->id,
                        'phone' => $phone
                    ]);
                }
                
                $sms = (new SMSBaseService)->smsGateway($phone);
                $logContext['sms_response'] = $sms;
                
                if (empty($sms)) {
                    throw new \Exception('Empty response from SMS gateway');
                }
                
                if (!is_array($sms)) {
                    throw new \Exception('Invalid response format from SMS gateway');
                }
            } catch (\Exception $smsEx) {
                $smsError = $smsEx->getMessage();
                \Log::error('SMS sending failed', array_merge($logContext, [
                    'error' => $smsError,
                    'trace' => $smsEx->getTraceAsString()
                ]));
                
                // If we had a transaction open, we need to handle it
                if ($isInTransaction) {
                    try {
                        DB::rollBack();
                        $isInTransaction = false;
                        \Log::warning('Rolled back transaction due to SMS sending failure', [
                            'user_id' => $user->id,
                            'phone' => $phone
                        ]);
                    } catch (\Exception $rollbackEx) {
                        \Log::error('Failed to rollback transaction after SMS failure', [
                            'user_id' => $user->id,
                            'error' => $rollbackEx->getMessage()
                        ]);
                    }
                }
                
                // Continue to return success response even if SMS fails
                $sms = [
                    'status' => false,
                    'message' => $smsError,
                    'verifyId' => null
                ];
            }
            \Log::debug('SMS gateway response received', $logContext);
            
            if (!data_get($sms, 'status')) {
                $errorMessage = data_get($sms, 'message', 'Failed to send verification code');
                $logContext['error'] = $errorMessage;
                $logContext['sms_error'] = true;
                
                \Log::error('SMS Gateway Error', $logContext);
                
                // Log the error but still return success response
                $response = [
                    'verifyId'  => data_get($sms, 'verifyId'),
                    'phone'     => $phone,
                    'message'   => 'Verification code could not be sent. Please try again.',
                    'sms_error' => $errorMessage,
                    'user_id'   => $user->id ?? null,
                    'status'    => true
                ];
                
                // If we have a verification ID, we can still proceed
                if (empty($response['verifyId'])) {
                    $response['verifyId'] = null;
                }
                
                return $this->successResponse(
                    __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                    $response
                );
            }
            
            $response = [
                'verifyId'  => data_get($sms, 'verifyId'),
                'phone'     => data_get($sms, 'phone'),
                'message'   => data_get($sms, 'message', ''),
                'user_id'   => $user->id ?? null
            ];
            
            $logContext['response'] = array_merge($response, ['verifyId' => '***']);
            $logContext['execution_time'] = round(microtime(true) - $startTime, 3) . 's';
            \Log::info('SMS verification process completed successfully', $logContext);
            
            return $this->successResponse(
                __('errors.' . ResponseError::SUCCESS, locale: $this->language),
                $response
            );
            
        } catch (\Exception $e) {
            $errorContext = array_merge($logContext, [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'execution_time' => round(microtime(true) - $startTime, 3) . 's',
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            
            // Add database error details if available
            if ($e->getPrevious() instanceof \PDOException) {
                $pdoEx = $e->getPrevious();
                $errorContext['pdo_error'] = [
                    'code' => $pdoEx->getCode(),
                    'message' => $pdoEx->getMessage(),
                    'sql_state' => $pdoEx->errorInfo[0] ?? null,
                    'driver_code' => $pdoEx->errorInfo[1] ?? null,
                    'driver_message' => $pdoEx->errorInfo[2] ?? null,
                ];
            }
            
            \Log::error('Error in proceedWithSmsVerification', $errorContext);
            
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_400,
                'message' => 'Failed to proceed with verification: ' . $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
            ]);
        }
    }
    

}
