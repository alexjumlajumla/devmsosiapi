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
     * Verify OTP code and authenticate user
     * 
     * @param array $array Request data containing verifyId and verifyCode
     * @return JsonResponse
     * @throws \Exception On critical errors
     */
    public function confirmOPTCode(array $array): JsonResponse
    {
        $verifyId = data_get($array, 'verifyId');
        $verifyCode = data_get($array, 'verifyCode');
        $isFirebase = data_get($array, 'type') === 'firebase';
        
        // Input validation
        if (!$verifyId || !$verifyCode) {
            return $this->onErrorResponse([
                'code' => ResponseError::ERROR_400,
                'message' => 'Verification ID and code are required.'
            ]);
        }
        
        // Start transaction for atomic operations
        \DB::beginTransaction();
        
        try {
            $logContext = [
                'verifyId' => $verifyId,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ];
            
            \Log::debug('Starting OTP verification', $logContext);
            
            // Try to get OTP data from cache first
            $cacheKey = 'otp_' . $verifyId;
            $otpData = \Cache::get($cacheKey);
            $source = 'cache';
            
            // If not in cache, try database with lock to prevent race conditions
            if (!$otpData) {
                $source = 'database';
                $otpRecord = \App\Models\SmsCode::where('verifyId', $verifyId)
                    ->lockForUpdate()
                    ->first();
                
                $otpData = $otpRecord ? $otpRecord->toArray() : null;
            }
            
            \Log::debug('OTP lookup result', array_merge($logContext, [
                'source' => $source,
                'found' => !empty($otpData),
                'expired' => $otpData ? now()->gt(\Carbon\Carbon::parse($otpData['expiredAt'])) : null
            ]));
            
            // Check if OTP exists
            if (empty($otpData)) {
                \Log::warning('OTP not found', $logContext);
                \DB::rollBack();
                return $this->onErrorResponse([
                    'code' => ResponseError::ERROR_404,
                    'message' => 'Verification code not found or expired. Please request a new one.'
                ]);
            }
            
            // Parse expiration time
            $expiredAt = $otpData['expiredAt'] instanceof \DateTime 
                ? $otpData['expiredAt'] 
                : \Carbon\Carbon::parse($otpData['expiredAt']);
            
            // Check if OTP is expired
            if (now()->gt($expiredAt)) {
                // Clean up expired OTP
                $this->cleanupOTP($verifyId, $otpData['id'] ?? null);
                
                \Log::warning('OTP code expired', array_merge($logContext, [
                    'expiredAt' => $expiredAt,
                    'now' => now()
                ]));
                
                return $this->onErrorResponse([
                    'code' => ResponseError::ERROR_203,
                    'message' => 'Verification code has expired. Please request a new one.'
                ]);
            }
            
            // Verify OTP code (type-safe comparison)
            if ((string)$otpData['OTPCode'] !== (string)$verifyCode) {
                \Log::warning('Invalid OTP code', array_merge($logContext, [
                    'expected' => $otpData['OTPCode'],
                    'received' => $verifyCode,
                    'type_expected' => gettype($otpData['OTPCode']),
                    'type_received' => gettype($verifyCode)
                ]));
                
                return $this->onErrorResponse([
                    'code' => ResponseError::ERROR_201,
                    'message' => 'Invalid verification code. Please try again.'
                ]);
            }
            
            // Get or create user
            $phone = $otpData['phone'];
            
            // Start transaction to ensure data consistency
            DB::beginTransaction();
            
            try {
                // Log the start of user retrieval/creation
                \Log::debug('Starting user retrieval/creation', ['phone' => $phone]);
                
                $user = $this->model()->where('phone', $phone)->first();
                
                if (!$user) {
                    // Prepare user data
                    $userData = [
                        'firstname' => $phone,
                        'ip_address' => request()->ip(),
                        'auth_type' => $isFirebase ? 'firebase' : 'phone',
                        'active' => true,
                        'phone_verified_at' => now(),
                    ];
                    
                    // Add Firebase-specific fields if needed
                    if ($isFirebase) {
                        $userData = array_merge($userData, [
                            'email' => data_get($array, 'email'),
                            'firstname' => data_get($array, 'firstname', $phone),
                            'lastname' => data_get($array, 'lastname', ''),
                        ]);
                    }
                    
                    // Create the user
                    $user = $this->createNewUser($phone, $userData);
                    \Log::info('New user created successfully', ['user_id' => $user->id]);
                } else {
                    \Log::info('Existing user found', ['user_id' => $user?->id]);
                }
                
                // Ensure user has a role
                try {
                    $this->ensureUserHasRole($user);
                    \Log::debug('Role assignment successful', ['user_id' => $user?->id]);
                } catch (\Exception $e) {
                    \Log::error('Failed to assign role to user', [
                        'user_id' => $user?->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw new \Exception('Failed to assign user role');
                }
                
                // Only clean up OTP after successful user retrieval/creation and role assignment
                $this->cleanupOTP($verifyId, $otpData['id'] ?? null);
                \Log::debug('OTP cleaned up after successful verification', ['verifyId' => $verifyId]);
                
                // Commit the transaction
                DB::commit();
                \Log::info('OTP verification transaction committed successfully', ['user_id' => $user?->id]);
                
                // Log successful verification
                if ($user->wasRecentlyCreated) {
                    \Log::info('New user created during OTP verification', [
                        'user_id' => $user->id,
                        'phone' => $user->phone,
                        'auth_type' => $user->auth_type
                    ]);
                } else {
                    \Log::info('User logged in via OTP verification', [
                        'user_id' => $user->id,
                        'phone' => $user->phone,
                        'auth_type' => $user->auth_type
                    ]);
                }
                
                // At this point, we have a valid user with a role
                // Now generate the authentication token
                $token = $user->createToken('api_token')->plainTextToken;
                
                // Return success response with user data and token
                return $this->successResponse('Successfully logged in', [
                    'token' => $token,
                    'user' => UserResource::make($user)
                ]);
                
            } catch (\Exception $e) {
                // Rollback the transaction in case of any error
                DB::rollBack();
                \Log::error('Error during OTP verification transaction', [
                    'phone' => $phone,
                    'verifyId' => $verifyId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Re-throw the exception to be handled by the outer try-catch
                throw $e;
            }
        } catch (\Exception $e) {
            \DB::rollBack();
            \Log::error('OTP verification failed', [
                'verifyId' => $verifyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->onErrorResponse([
                'code' => ResponseError::ERROR_400,
                'message' => 'An error occurred during verification. Please try again.'
            ]);
        }
    }
    
    /**
     * Clean up OTP data from cache and database
     * 
     * @param string $verifyId
     * @param int|null $otpId
     * @return void
     */
    /**
     * Clean up OTP data from cache and database
     * 
     * @param string $verifyId The verification ID
     * @param int|null $otpId Optional OTP record ID from database
     * @return void
     */
    protected function cleanupOTP(string $verifyId, ?int $otpId = null): void
    {
        $startTime = microtime(true);
        $success = false;
        
        try {
            // Log the cleanup attempt
            \Log::debug('Starting OTP cleanup', [
                'verifyId' => $verifyId, 
                'otpId' => $otpId,
                'source' => 'cleanupOTP'
            ]);
            
            // Remove from cache first
            $cacheKey = 'otp_' . $verifyId;
            $cacheCleared = \Cache::forget($cacheKey);
            
            // Remove from database if ID is provided
            $dbCleared = false;
            if ($otpId) {
                $dbCleared = (bool) \App\Models\SmsCode::where('id', $otpId)->delete();
            } else {
                // If no OTP ID provided, try to clean up by verifyId
                $dbCleared = \App\Models\SmsCode::where('verifyId', $verifyId)->delete() > 0;
            }
            
            $success = $cacheCleared || $dbCleared;
            
            // Log the result
            \Log::debug('OTP cleanup completed', [
                'verifyId' => $verifyId,
                'otpId' => $otpId,
                'cache_cleared' => $cacheCleared,
                'db_cleared' => $dbCleared,
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            
        } catch (\Exception $e) {
            // Log detailed error but don't fail the main operation
            \Log::error('Error during OTP cleanup', [
                'verifyId' => $verifyId,
                'otpId' => $otpId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);
            
            // If we failed to clean up, schedule a background job to retry
            if (app()->environment('production')) {
                dispatch(function () use ($verifyId, $otpId) {
                    try {
                        $this->cleanupOTP($verifyId, $otpId);
                    } catch (\Exception $e) {
                        \Log::error('Background OTP cleanup failed', [
                            'verifyId' => $verifyId,
                            'otpId' => $otpId,
                            'error' => $e->getMessage()
                        ]);
                    }
                })->delay(now()->addSeconds(30));
            }
        }
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
     * Create a new user with the given phone number and additional data
     * 
     * @param string $phone
     * @param array $additionalData
     * @return \App\Models\User
     */
    protected function createNewUser(string $phone, array $additionalData = [])
    {
        $userData = array_merge([
            'firstname' => $phone,
            'phone' => $phone,
            'ip_address' => request()->ip(),
            'auth_type' => 'phone',
            'active' => true,
            'phone_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ], $additionalData);
        
        // Create user directly using query builder to avoid model events
        $userId = DB::table('users')->insertGetId($userData);
        $user = $this->model()->findOrFail($userId);
        
        \Log::info('New user created', [
            'user_id' => $user->id,
            'phone' => $phone,
            'auth_type' => $userData['auth_type']
        ]);
        
        return $user;
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
