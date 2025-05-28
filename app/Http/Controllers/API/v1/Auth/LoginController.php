<?php

namespace App\Http\Controllers\API\v1\Auth;

use App\Helpers\ResponseError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgetPasswordRequest;
use App\Http\Requests\Auth\PhoneVerifyRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ProvideLoginRequest;
use App\Http\Requests\Auth\ReSendVerifyRequest;
use App\Http\Requests\FilterParamsRequest;
use App\Http\Resources\UserResource;
use App\Models\Notification;
use App\Models\User;
use App\Services\AuthService\AuthByMobilePhone;
use App\Services\EmailSettingService\EmailSendService;
use App\Services\UserServices\UserWalletService;
use App\Traits\ApiResponse;
use DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use Psr\SimpleCache\InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LoginController extends Controller
{
    use ApiResponse, \App\Traits\Notification {
        // Use onErrorResponse from ApiResponse instead of errorResponse from Notification
        ApiResponse::errorResponse insteadof \App\Traits\Notification;
    }

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            if ($request->input('phone')) {
                return $this->loginByPhone($request);
            }

            \Log::info('Login attempt', [
                'email' => $request->input('email'),
                'has_firebase_token' => $request->has('firebase_token'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            if (!auth()->attempt($request->only(['email', 'password']))) {
                \Log::warning('Login failed: Invalid credentials', [
                    'email' => $request->input('email'),
                    'ip' => $request->ip()
                ]);
                return $this->onErrorResponse([
                    'code'    => ResponseError::ERROR_102,
                    'message' => __('errors.' . ResponseError::ERROR_102, locale: $this->language)
                ]);
            }

            $user = auth()->user();
            \Log::info('User authenticated', [
                'user_id' => $user->id,
                'email' => $user->email,
                'has_firebase_token' => $request->has('firebase_token')
            ]);
            
            // Add FCM token if provided
            if ($request->has('firebase_token')) {
                try {
                    $firebaseToken = $request->input('firebase_token');
                    \Log::debug('Adding FCM token', [
                        'user_id' => $user->id,
                        'token_prefix' => substr($firebaseToken, 0, 10) . '...',
                        'token_length' => strlen($firebaseToken)
                    ]);
                    
                    $user->addFcmToken($firebaseToken);
                    $user->save();
                    \Log::info('FCM token added successfully', ['user_id' => $user->id]);
                } catch (\Exception $e) {
                    \Log::error('Failed to add FCM token: ' . $e->getMessage(), [
                        'user_id' => $user->id,
                        'exception' => $e
                    ]);
                    // Continue with login even if FCM token fails
                }
            }    
            \Log::info('[FirebaseToken] Token added during login', [
                'user_id' => $user->id,
                'token_prefix' => substr($request->input('firebase_token'), 0, 10) . '...',
                'token_count' => count($user->getFcmTokens())
            ]);

            $token = $user->createToken('api_token')->plainTextToken;
            \Log::info('Login successful', [
                'user_id' => $user->id,
                'token_created' => true
            ]);

            try {
                // Create a minimal user response
                $userData = [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'created_at' => $user->created_at?->toIso8601String(),
                    'updated_at' => $user->updated_at?->toIso8601String(),
                ];
                
                // Format the response to match frontend expectations
                $response = [
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'user' => $userData,
                ];

                // Log the complete response structure
                \Log::debug('Login response prepared', [
                    'user_id' => $user->id,
                    'response_keys' => array_keys($response),
                    'response_data' => $response
                ]);

                // Convert to JSON and log the result
                $jsonResponse = json_encode($response, JSON_UNESCAPED_SLASHES);
                \Log::debug('JSON response prepared', [
                    'user_id' => $user->id,
                    'json_response' => $jsonResponse,
                    'json_last_error' => json_last_error(),
                    'json_last_error_msg' => json_last_error_msg()
                ]);

                if ($jsonResponse === false) {
                    throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
                }

                // Bypass Laravel's response handling and send raw output
                header('Content-Type: application/json');
                header('Content-Length: ' . strlen($jsonResponse));
                http_response_code(200);
                echo $jsonResponse;
                exit(0);
                
            } catch (\Exception $e) {
                \Log::error('Error preparing login response', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'user_id' => $user->id
                ]);
                
                return response()->json([
                    'status' => false,
                    'message' => 'Error preparing user data',
                    'error' => config('app.debug') ? $e->getMessage() : null
                ], 500);
            }

        } catch (\Exception $e) {
            \Log::error('Login error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
                'request' => [
                    'email' => $request->input('email'),
                    'has_firebase_token' => $request->has('firebase_token'),
                    'ip' => $request->ip()
                ]
            ]);

            return response()->json([
                'status' => false,
                'code' => ResponseError::ERROR_400,
                'message' => 'An error occurred during login. Please try again.',
                'debug' => config('app.debug') ? [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ] : null
            ], 500);
        }
    }

    protected function loginByPhone($request): JsonResponse
    {
        try {
            \Log::info('Phone login attempt', [
                'phone' => $request->input('phone'),
                'has_firebase_token' => $request->has('firebase_token'),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            // First try standard auth attempt
            if (!auth()->attempt($request->only('phone', 'password'))) {
                // If standard auth fails, try mobile phone authentication
                $user = (new AuthByMobilePhone)->authentication($request->all());
                
                if (!($user instanceof User)) {
                    \Log::error('AuthByMobilePhone returned invalid user', [
                        'returned_type' => is_object($user) ? get_class($user) : gettype($user),
                        'phone' => $request->input('phone')
                    ]);
                    return $this->onErrorResponse([
                        'code'    => ResponseError::ERROR_102,
                        'message' => __('errors.' . ResponseError::ERROR_102, locale: $this->language)
                    ]);
                }
                
                // Log the user in
                auth()->login($user);
            } else {
                $user = auth()->user();
            }

            \Log::info('Phone authentication successful', [
                'user_id' => $user->id,
                'phone' => $user->phone,
                'has_firebase_token' => $request->has('firebase_token')
            ]);

            // Add FCM token if provided
            if ($request->has('firebase_token')) {
                try {
                    $firebaseToken = $request->input('firebase_token');
                    \Log::debug('Adding FCM token during phone login', [
                        'user_id' => $user->id,
                        'token_prefix' => substr($firebaseToken, 0, 10) . '...',
                        'token_length' => strlen($firebaseToken)
                    ]);
                    
                    $user->addFcmToken($firebaseToken);
                    $user->save();
                    
                    \Log::info('[FirebaseToken] Token added during phone login', [
                        'user_id' => $user->id,
                        'provider' => 'phone',
                        'token_prefix' => substr($firebaseToken, 0, 10) . '...',
                        'token_count' => count($user->getFcmTokens())
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Failed to add FCM token during phone login: ' . $e->getMessage(), [
                        'user_id' => $user->id,
                        'exception' => $e
                    ]);
                    // Continue with login even if FCM token fails
                }
            }

            $token = $user->createToken('api_token')->plainTextToken;
            \Log::info('Phone login successful', [
                'user_id' => $user->id,
                'token_created' => true
            ]);

            try {
                // Create a minimal user response
                $userData = [
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'created_at' => $user->created_at?->toIso8601String(),
                    'updated_at' => $user->updated_at?->toIso8601String(),
                ];
                
                // Format the response to match frontend expectations
                $response = [
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'user' => $userData,
                ];

                // Log the complete response structure
                \Log::debug('Phone login response prepared', [
                    'user_id' => $user->id,
                    'response_keys' => array_keys($response),
                    'response_data' => $response
                ]);

                // Convert to JSON and log the result
                $jsonResponse = json_encode($response, JSON_UNESCAPED_SLASHES);
                \Log::debug('Phone login JSON response prepared', [
                    'user_id' => $user->id,
                    'json_response' => $jsonResponse,
                    'json_last_error' => json_last_error(),
                    'json_last_error_msg' => json_last_error_msg()
                ]);

                if ($jsonResponse === false) {
                    throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
                }

                // Bypass Laravel's response handling and send raw output
                header('Content-Type: application/json');
                header('Content-Length: ' . strlen($jsonResponse));
                http_response_code(200);
                echo $jsonResponse;
                exit(0);
                
            } catch (\Exception $e) {
                \Log::error('Error preparing phone login response', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'user_id' => $user->id
                ]);
                
                return response()->json([
                    'status' => false,
                    'message' => 'Error preparing user data',
                    'error' => config('app.debug') ? $e->getMessage() : null
                ], 500);
            }

        } catch (\Exception $e) {
            \Log::error('Phone login error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
                'request' => [
                    'phone' => $request->input('phone'),
                    'has_firebase_token' => $request->has('firebase_token'),
                    'ip' => $request->ip()
                ]
            ]);

            return response()->json([
                'status' => false,
                'code' => ResponseError::ERROR_400,
                'message' => 'An error occurred during phone login. Please try again.',
                'debug' => config('app.debug') ? [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ] : null
            ], 500);
        }
    }

    /**
     * Obtain the user information from Provider.
     *
     * @param $provider
     * @param ProvideLoginRequest $request
     * @return JsonResponse
     */
    public function handleProviderCallback($provider, ProvideLoginRequest $request): JsonResponse
    {
        $validated = $this->validateProvider($request->input('id'), $provider);

        if (!empty($validated)) {
            return $validated;
        }

        try {
            $result = DB::transaction(function () use ($request, $provider) {

                @[$firstname, $lastname] = explode(' ', $request->input('name'));

                $user = User::withTrashed()->updateOrCreate(['email' => $request->input('email')], [
                    'email'             => $request->input('email'),
                    'email_verified_at' => now(),
                    'referral'          => $request->input('referral'),
                    'active'            => true,
                    'firstname'         => !empty($firstname) ? $firstname : $request->input('email'),
                    'lastname'          => $lastname,
                    'deleted_at'        => null,
                ]);

				if ($request->input('avatar')) {
					$user->update(['img' => $request->input('avatar')]);
				}

				$user->socialProviders()->updateOrCreate([
					'provider'      => $provider,
					'provider_id'   => $request->input('id'),
				], [
					'avatar' => $request->input('avatar')
				]);

                if (!$user->hasAnyRole(Role::query()->pluck('name')->toArray())) {
                    $user->syncRoles('user');
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

				if (empty($user->wallet?->uuid)) {
					$user = (new UserWalletService)->create($user);
				}
                
                // Add FCM token if provided
                if ($request->has('firebase_token')) {
                    $user->addFcmToken($request->input('firebase_token'));
                    $user->save();
                    
                    \Log::info('[FirebaseToken] Token added during social login', [
                        'user_id' => $user->id,
                        'provider' => $provider,
                        'token_prefix' => substr($request->input('firebase_token'), 0, 10) . '...',
                        'token_count' => count($user->getFcmTokens())
                    ]);
                }

                return [
                    'token' => $user->createToken('api_token')->plainTextToken,
                    'user'  => UserResource::make($user),
                ];
            });

            return $this->successResponse('User successfully login', [
                'access_token'  => data_get($result, 'token'),
                'token_type'    => 'Bearer',
                'user'          => data_get($result, 'user'),
            ]);
        } catch (Throwable $e) {
            $this->error($e);
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::USER_IS_BANNED, locale: $this->language)
            ]);
        }
    }

	/**
	 * @param FilterParamsRequest $request
	 * @return JsonResponse
	 */
	public function checkPhone(FilterParamsRequest $request): JsonResponse
	{
		$user = User::with('shop')
			->where('phone', $request->input('phone'))
			->first();

		if (!$user) {
			return $this->onErrorResponse([
				'code'    => ResponseError::ERROR_102,
				'message' => __('errors.' . ResponseError::ERROR_102, locale: $this->language)
			]);
		}

		// Add FCM token if provided
		if ($request->has('firebase_token')) {
			$user->addFcmToken($request->input('firebase_token'));
			$user->save();
			
			\Log::info('[FirebaseToken] Token added during phone check', [
				'user_id' => $user->id,
				'token_prefix' => substr($request->input('firebase_token'), 0, 10) . '...',
				'token_count' => count($user->getFcmTokens())
			]);
		}

		$token = $user->createToken('api_token')->plainTextToken;

		return $this->successResponse('User successfully logged in', [
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'user'         => UserResource::make($user->loadMissing(['shop', 'model'])),
		]);
	}

    public function logout(): JsonResponse
    {
        try {
            /** @var User $user */
            /** @var PersonalAccessToken $current */
            $user = auth('sanctum')->user();
            
            // Clear all FCM tokens on logout
            $user->clearFcmTokens();
            $user->save();
            
            \Log::info('[FirebaseToken] All tokens cleared on logout', [
                'user_id' => $user->id
            ]);

            try {
                $token = str_replace('Bearer ', '', request()->header('Authorization'));
                $current = PersonalAccessToken::findToken($token);
                $current->delete();
            } catch (Throwable $e) {
                $this->error($e);
            }
        } catch (Throwable $e) {
            $this->error($e);
        }

        return $this->successResponse('User successfully logout');
    }

    /**
     * @param $idToken
     * @param $provider
     * @return JsonResponse|void
     */
    protected function validateProvider($idToken, $provider)
    {
//        $serverKey = Settings::where('key', 'api_key')->first()?->value;
//        $clientId  = Settings::where('key', 'client_id')->first()?->value;
//
//        $response  = Http::get("https://oauth2.googleapis.com/tokeninfo?id_token=$idToken");

//        dd($response->json(), $clientId, $serverKey);

//        $response = Http::withHeaders([
//            'Content-Type' => 'application/x-www-form-urlencoded',
//        ])
//            ->post('http://your-laravel-app.com/oauth/token');

        if (!in_array($provider, ['facebook', 'github', 'google', 'apple'])) { //$response->ok()
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_107,
                'http'    => Response::HTTP_UNAUTHORIZED,
                'message' =>  __('errors.' . ResponseError::INCORRECT_LOGIN_PROVIDER, locale: $this->language)
            ]);
        }

    }

    public function forgetPassword(ForgetPasswordRequest $request): JsonResponse
    {
        return (new AuthByMobilePhone)->authentication($request->validated());
    }

    public function forgetPasswordEmail(ReSendVerifyRequest $request): JsonResponse
    {
        $user = User::withTrashed()->where('email', $request->input('email'))->first();

        if(!$user) {
            return $this->onErrorResponse([
                'code'      => ResponseError::ERROR_404,
                'message'   => __('errors.' . ResponseError::ERROR_404, locale: $this->language),
            ]);
        }

        $token = mb_substr((string)time(), -6, 6);

        Cache::put($token, $token, 900);

		$result = (new EmailSendService)->sendEmailPasswordReset($user, $token);

		if (!data_get($result, 'status')) {
			return $this->onErrorResponse($result);
		}

		$user->update([
			'verify_token' => $token
		]);

        return $this->successResponse('Verify code send');
    }

    public function forgetPasswordVerifyEmail(int $hash): JsonResponse
    {
        $token = Cache::get($hash);

        if (!$token) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_215,
                'message' => __('errors.' . ResponseError::ERROR_215, locale: $this->language)
            ]);
        }

        $user = User::withTrashed()->where('verify_token', $token)->first();

        if (!$user) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_404,
                'message' => __('errors.' . ResponseError::USER_NOT_FOUND, locale: $this->language)
            ]);
        }

        if (!$user->hasAnyRole(Role::query()->pluck('name')->toArray())) {
            $user->syncRoles('user');
        }

        $token = $user->createToken('api_token')->plainTextToken;

        $user->update([
            'active'       => true,
            'deleted_at'   => null,
			'verify_token' => null
		]);

		try {
			Cache::delete($hash);
		} catch (InvalidArgumentException $e) {}

        return $this->successResponse('User successfully login', [
            'token' => $token,
            'user'  => UserResource::make($user),
        ]);
    }

    /**
     * @param PhoneVerifyRequest $request
     * @return JsonResponse
     */
    public function forgetPasswordVerify(PhoneVerifyRequest $request): JsonResponse
    {
        return (new AuthByMobilePhone)->forgetPasswordVerify($request->validated());
    }


}
