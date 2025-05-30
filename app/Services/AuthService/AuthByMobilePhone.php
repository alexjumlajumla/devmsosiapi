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
use Illuminate\Support\Facades\DB;
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
        $startTime = microtime(true);
        $logContext = [
            'phone' => data_get($array, 'phone'),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_data' => array_merge($array, ['phone' => '***'])
        ];
        
        try {
            \Log::debug('Starting authentication', $logContext);
            
            $phone = preg_replace('/\D/', '', data_get($array, 'phone'));
            $phone = str_contains($phone, '+') ? substr($phone, 1) : $phone;
            
            if (empty($phone)) {
                throw new \Exception('Phone number is required');
            }
            
            // Update log context with cleaned phone
            $logContext['phone_cleaned'] = $phone;
            \Log::debug('Phone number cleaned', $logContext);
            
            // Check database connection
            try {
                $dbCheck = \DB::connection()->getPdo();
                $logContext['db_connection'] = $dbCheck ? 'Connected' : 'Failed';
                \Log::debug('Database connection check', $logContext);
            } catch (\Exception $e) {
                $logContext['db_error'] = $e->getMessage();
                \Log::error('Database connection failed', $logContext);
            }
            
            // Check if user exists
            $user = $this->model()->withTrashed()->where('phone', $phone)->first();
            $logContext['user_found'] = (bool)$user;
            $logContext['user_id'] = $user ? $user->id : null;
            $logContext['user_active'] = $user ? $user->active : null;
            $logContext['user_deleted'] = $user ? $user->trashed() : null;
            
            \Log::debug('User lookup result', $logContext);
            
            if ($user) {
                // Update existing user
                $updateData = [
                    'phone'         => $phone,
                    'ip_address'    => request()->ip(),
                    'auth_type'     => "phone"
                ];
                
                // Only update firstname if it doesn't already exist
                if (empty($user->firstname)) {
                    $updateData['firstname'] = $phone;
                }
                
                $user->update($updateData);
                \Log::debug('Updated existing user', ['user_id' => $user->id]);
                
                try {
                    // Check if user has any role, if not assign 'user' role
                    $roles = Role::pluck('name')->toArray();
                    \Log::debug('Available roles', ['roles' => $roles]);
                    
                    if (empty($roles)) {
                        throw new \Exception('No roles found in the database');
                    }
                    
                    if (!$user->hasAnyRole($roles)) {
                        $this->assignUserRole($user);
                    }
                } catch (\Exception $e) {
                    \Log::error('Error in role assignment: ' . $e->getMessage(), [
                        'user_id' => $user->id,
                        'trace' => $e->getTraceAsString()
                    ]);
                    $this->assignUserRole($user);
                }
            } else {
                // Create new user with default 'user' role
                $userData = [
                    'firstname'     => $phone,
                    'phone'         => $phone,
                    'ip_address'    => request()->ip(),
                    'auth_type'     => "phone",
                    'active'        => true,
                    'phone_verified_at' => now()
                ];
                
                try {
                    // Log the attempt to create a new user
                    \Log::info('Attempting to create new user', [
                        'phone' => $phone,
                        'data' => array_merge($userData, ['password' => '***'])
                    ]);
                    
                    // Try to create the user with detailed error handling
                    try {
                        // First, check if user with this phone already exists (race condition check)
                        $existingUser = $this->model()->withTrashed()->where('phone', $phone)->first();
                        
                        if ($existingUser) {
                            // If user was soft-deleted, restore them
                            if ($existingUser->trashed()) {
                                $existingUser->restore();
                                $existingUser->update([
                                    'active' => true,
                                    'phone_verified_at' => now()
                                ]);
                                \Log::info('Restored soft-deleted user', [
                                    'user_id' => $existingUser->id,
                                    'phone' => $phone,
                                    'was_soft_deleted' => true
                                ]);
                            } else {
                                \Log::warning('User with this phone number already exists', [
                                    'phone' => $phone,
                                    'user_id' => $existingUser->id,
                                    'active' => $existingUser->active,
                                    'phone_verified_at' => $existingUser->phone_verified_at
                                ]);
                            }
                            
                            // Update user with latest data
                            $existingUser->update([
                                'ip_address' => request()->ip(),
                                'auth_type' => 'phone',
                                'active' => true,
                                'phone_verified_at' => $existingUser->phone_verified_at ?? now()
                            ]);
                            
                            $user = $existingUser;
                            
                            // Ensure user has a role
                            $this->ensureUserHasRole($user);
                            
                            return $this->proceedWithSmsVerification($user, $phone);
                        }
                        
                        // If we get here, it's a new user
                        \Log::info('Attempting to create new user', [
                            'phone' => $phone,
                            'data' => array_merge($userData, ['password' => '***'])
                        ]);
                        
                        // Try to create the user
                        $user = new $this->model();
                        $logContext['user_model'] = get_class($user);
                        
                        // Log before filling
                        \Log::debug('Filling user model with data', array_merge($logContext, [
                            'user_data' => array_merge($userData, ['password' => '***'])
                        ]));
                        
                        $user->fill($userData);
                        
                        // Log after filling
                        \Log::debug('User model filled', array_merge($logContext, [
                            'user_attributes' => $user->getAttributes(),
                            'original' => $user->getOriginal(),
                            'changes' => $user->getChanges()
                        ]));
                        
                        // Save and check for errors
                        \Log::debug('Attempting to save user', $logContext);
                        $saved = $user->save();
                        
                        if (!$saved) {
                            $errors = method_exists($user, 'getErrors') ? $user->getErrors() : 'Unknown error';
                            $errorMessage = is_array($errors) ? json_encode($errors) : (string) $errors;
                            
                            \Log::error('Failed to save user model', [
                                'phone' => $phone,
                                'errors' => $errorMessage,
                                'user_data' => $userData,
                                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
                            ]);
                            
                            // Try to find the user again in case of race condition
                            $existingUser = $this->model()->withTrashed()->where('phone', $phone)->first();
                            if ($existingUser) {
                                \Log::info('Found user after race condition', [
                                    'phone' => $phone,
                                    'user_id' => $existingUser->id,
                                    'was_soft_deleted' => $existingUser->trashed()
                                ]);
                                
                                if ($existingUser->trashed()) {
                                    $existingUser->restore();
                                }
                                
                                $existingUser->update([
                                    'active' => true,
                                    'phone_verified_at' => $existingUser->phone_verified_at ?? now()
                                ]);
                                
                                $this->ensureUserHasRole($existingUser);
                                return $this->proceedWithSmsVerification($existingUser, $phone);
                            }
                            
                            throw new \Exception('Failed to save user: ' . $errorMessage);
                        }
                        
                        // Verify the user was saved
                        if (!$user->exists || !$user->id) {
                            $errorMsg = 'User model was not saved correctly. ';
                            $errorMsg .= 'Exists: ' . ($user->exists ? 'Yes' : 'No') . ', ';
                            $errorMsg .= 'ID: ' . ($user->id ?? 'null');
                            
                            \Log::error($errorMsg, array_merge($logContext, [
                                'user_exists' => $user->exists,
                                'user_id' => $user->id,
                                'user_attributes' => $user->getAttributes(),
                                'original' => $user->getOriginal(),
                                'changes' => $user->getChanges()
                            ]));
                            
                            throw new \Exception($errorMsg);
                        }
                        
                        // Ensure user has a role
                        $this->ensureUserHasRole($user);
                        
                        \Log::info('User created successfully', [
                            'user_id' => $user->id,
                            'phone' => $user->phone,
                            'created_at' => $user->created_at
                        ]);
                        
                    } catch (\Exception $createEx) {
                        // Log detailed error information
                        $errorMessage = $createEx->getMessage();
                        $errorInfo = array_merge($logContext, [
                            'error' => $errorMessage,
                            'data' => $userData,
                            'trace' => $createEx->getTraceAsString(),
                            'db_error' => $createEx instanceof \PDOException ? $createEx->getMessage() : 'Not a database error',
                            'exception_class' => get_class($createEx),
                            'execution_time' => round(microtime(true) - $startTime, 3) . 's',
                            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB'
                        ]);
                        
                        // Add database query log if available
                        if (config('app.debug')) {
                            $errorInfo['queries'] = \DB::getQueryLog();
                        }
                        
                        // Check for database errors
                        if ($createEx->getPrevious() instanceof \PDOException) {
                            $pdoEx = $createEx->getPrevious();
                            $errorInfo['pdo_error'] = [
                                'code' => $pdoEx->getCode(),
                                'message' => $pdoEx->getMessage(),
                                'sql_state' => $pdoEx->errorInfo[0] ?? null,
                                'driver_code' => $pdoEx->errorInfo[1] ?? null,
                                'driver_message' => $pdoEx->errorInfo[2] ?? null,
                            ];
                            
                            // Check for duplicate entry error
                            if ($pdoEx->getCode() == '23000' || str_contains(strtolower($pdoEx->getMessage()), 'duplicate entry')) {
                                // Try to get the existing user
                                $existingUser = $this->model()->withTrashed()->where('phone', $phone)->first();
                                if ($existingUser) {
                                    \Log::info('Found existing user after duplicate error', [
                                        'phone' => $phone,
                                        'user_id' => $existingUser->id,
                                        'was_soft_deleted' => $existingUser->trashed()
                                    ]);
                                    
                                    if ($existingUser->trashed()) {
                                        $existingUser->restore();
                                    }
                                    
                                    $existingUser->update([
                                        'active' => true,
                                        'phone_verified_at' => $existingUser->phone_verified_at ?? now()
                                    ]);
                                    
                                    $this->ensureUserHasRole($existingUser);
                                    return $this->proceedWithSmsVerification($existingUser, $phone);
                                }
                            }
                        }
                        
                        \Log::error('Detailed user creation error', $errorInfo);
                        
                        // One final attempt to find the user
                        $existingUser = $this->model()->withTrashed()->where('phone', $phone)->first();
                        if ($existingUser) {
                            \Log::info('Found user in final recovery attempt', [
                                'phone' => $phone,
                                'user_id' => $existingUser->id,
                                'was_soft_deleted' => $existingUser->trashed()
                            ]);
                            
                            if ($existingUser->trashed()) {
                                $existingUser->restore();
                            }
                            
                            $this->ensureUserHasRole($existingUser);
                            return $this->proceedWithSmsVerification($existingUser, $phone);
                        }
                        
                        // If we get here, we couldn't recover the user
                        throw new \Exception('Failed to create user. Please try again or contact support if the problem persists.');
                    }
                    
                    // Verify the user was actually saved
                    if (!$user->exists) {
                        throw new \Exception('User model exists() returned false after creation');
                    }
                    
                    // Refresh the model to ensure we have all database defaults
                    $user->refresh();
                    
                    \Log::info('Successfully created new user', [
                        'user_id' => $user->id,
                        'phone' => $user->phone,
                        'created_at' => $user->created_at
                    ]);
                    
                    try {
                        // Assign default 'user' role
                        $this->assignUserRole($user);
                    } catch (\Exception $roleException) {
                        // Log but don't fail the entire registration if role assignment fails
                        \Log::error('Role assignment failed after user creation', [
                            'user_id' => $user->id,
                            'error' => $roleException->getMessage(),
                            'trace' => $roleException->getTraceAsString()
                        ]);
                    }
                    
                    // Return success response with user data
                    return $this->successResponse(__('User successfully created', [], $this->language), [
                        'user' => $user,
                        'status' => true
                    ]);
                    
                } catch (\Exception $e) {
                    // Log detailed error information
                    $errorContext = [
                        'phone' => $phone,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                        'trace' => $e->getTraceAsString(),
                        'user_data' => $userData
                    ];
                    
                    // Check database connection
                    try {
                        $errorContext['db_connection'] = \DB::connection()->getPdo() ? 'Connected' : 'Not connected';
                    } catch (\Exception $dbEx) {
                        $errorContext['db_connection'] = 'Connection failed: ' . $dbEx->getMessage();
                    }
                    
                    \Log::error('User creation failed', $errorContext);
                    
                    // Re-throw with more context but without exposing sensitive data
                    throw new \Exception('Failed to create user. Please try again or contact support if the problem persists.');
                }
            }
            
            if (!$user) {
                \Log::error('Failed to create or update user', [
                    'phone' => $phone,
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
                ]);
                
                // Check if user exists but couldn't be retrieved
                $existingUser = $this->model()->withTrashed()->where('phone', $phone)->first();
                if ($existingUser) {
                    \Log::warning('Found existing user after creation failure', [
                        'phone' => $phone,
                        'user_id' => $existingUser->id,
                        'deleted_at' => $existingUser->deleted_at
                    ]);
                    
                    // If user was soft-deleted, restore them
                    if ($existingUser->trashed()) {
                        $existingUser->restore();
                        \Log::info('Restored soft-deleted user', [
                            'user_id' => $existingUser->id,
                            'phone' => $phone
                        ]);
                    }
                    
                    $user = $existingUser;
                } else {
                    throw new \Exception('Failed to create or update user. Please try again later.');
                }
            }
            
            // Log successful authentication
            $logContext['execution_time'] = round(microtime(true) - $startTime, 3) . 's';
            $logContext['memory_usage'] = round(memory_get_usage() / 1024 / 1024, 2) . 'MB';
            \Log::info('Authentication successful, proceeding with SMS verification', $logContext);
            
            // Proceed with SMS verification
            return $this->proceedWithSmsVerification($user, $phone);
            
        } catch (\Exception $e) {
            $errorContext = array_merge($logContext, [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'execution_time' => round(microtime(true) - $startTime, 3) . 's',
                'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . 'MB',
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
            
            \Log::error('Authentication Error', $errorContext);
            
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_400,
                'message' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null
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
                } catch (\Exception $e) {
                    \Log::error('Error looking up user', [
                        'phone' => $data->phone,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw new \Exception('Error verifying your account. Please try again.');
                }
                
                if (!$user) {
                    // Create new user with minimal required fields
                    try {
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
                        
                    } catch (\Exception $e) {
                        \Log::error('Error creating user in confirmOPTCode', [
                            'phone' => $data->phone,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        throw new \Exception('Failed to create user account. Please try again.');
                    }
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
                } catch (\Exception $e) {
                    \Log::error('Error in Firebase user lookup', [
                        'phone' => $phone,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw new \Exception('Error verifying your account. Please try again.');
                }
                
                if (!$user) {
                    // Create new user with provided data
                    try {
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
                        
                        \Log::info('New user created during Firebase OTP verification', [
                            'user_id' => $user->id,
                            'phone' => $user->phone
                        ]);
                        
                    } catch (\Exception $e) {
                        \Log::error('Error creating Firebase user', [
                            'phone' => $phone,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        throw new \Exception('Failed to create your account. Please try again.');
                    }
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
            'user_agent' => request()->userAgent()
        ];
        
        try {
            \Log::debug('Starting SMS verification process', $logContext);
            
            // Ensure user has a role
            $this->ensureUserHasRole($user);
            \Log::debug('User role verified', $logContext);
            
            // Send SMS with verification code
            \Log::debug('Sending SMS via SMSBaseService', $logContext);
            $sms = (new SMSBaseService)->smsGateway($phone);
            
            $logContext['sms_response'] = $sms;
            \Log::debug('SMS gateway response received', $logContext);
            
            if (!data_get($sms, 'status')) {
                $errorMessage = data_get($sms, 'message', 'Failed to send verification code');
                $logContext['error'] = $errorMessage;
                $logContext['sms_error'] = true;
                
                \Log::error('SMS Gateway Error', $logContext);
                
                // Still return success but with a flag indicating SMS wasn't sent
                return $this->successResponse(
                    __('errors.' . ResponseError::SUCCESS, locale: $this->language), 
                    [
                        'verifyId'  => null,
                        'phone'     => $phone,
                        'message'   => 'Verification code could not be sent. Please try again.',
                        'sms_error' => $errorMessage,
                        'user_id'   => $user->id ?? null
                    ]
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
    
    /**
     * Ensure the user has a default role assigned
     * 
     * @param \App\Models\User $user
     * @return void
     * @throws \Exception
     */
    /**
     * Ensure the user has a default role assigned
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
            
            // Re-throw with more context
            throw new \Exception(sprintf(
                'Failed to ensure user %s has role: %s',
                $user->id ?? 'unknown',
                $e->getMessage()
            ), 0, $e);
        }
    }
}
