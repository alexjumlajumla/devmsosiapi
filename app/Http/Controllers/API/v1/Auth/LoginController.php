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
    use ApiResponse, \App\Traits\Notification;

    public function login(LoginRequest $request): JsonResponse
    {
       // $t = ["GtoZfioKgutKha5ItiG8rD6CQp88Ro6eAu4fNxo1tz34iKttxuAVk1yW18IjOLzKdfdjXU48DrjKrjCqiFi_21Nbn89PWCVhBoo5L1028Kjfg","eNAjAUGISWGrysLRGImMRp:APA91bEpcvm6jpQDx8UZZf8WdmyHnBX2xddENs532Y8lJFSHQ0SeIvR24mGcT8PDJBFqj1L2hUs3f1-2MCol8USp4C64Dl5ayQNv6ghoC6EQE4jhKiVjywU","cR5mYAySRpiWzkQUs9eEvE:APA91bGLKxPMaavVwKGLqA1uvAbMnyGqZ2oS-Hk96poy4gyJkMQeBFE-Z53WsbYNMSG6BZvYXZbHtj_iVXadDtoDn8txxrAK778jw9gJZCnIL_sZLh84C78","f70ivAIiakmugu20diCFjH:APA91bFG_ZgTLDz_HvnOJgWecldIc9P89jj8Wnuo5s3IGjs7fQTQZEhfAVIv2bKIstbM-J_IUaEi-8yMjszSdGXg_KvMhq8jPPYobR-liOEyjK4Y22GKt88","eEeMo0rADk-evXKnFGLag9:APA91bHX037S8ETf2IBcb324orfSpWLrLGA9yHPWZPPhaiQXUMfj1yU-8JVbyx-zNVwWvEv6wGGPwpDcY8S3nsLMM7tkNAVl4HtpMdOkqu_GvbQZGWjHHAI","eEM3bjibSUyIjmE8lgJOmH:APA91bFGHqRvSRFL1nTECh7ep-YTI5wDvRM9oS4bYaISjCGBcIg67cz5ilHNtM6QJiu6zxE1VQc0GPBaJ6PuAtSgGiz9cJ2TZhxV88DAA5_3bDGNR1Z4Irc","eBa1pVO-QheZoIfKOKIsCm:APA91bHtqVlLnUwINJHGolKprKGeA-7aPHBdlAchyMbVmIuZrZVw_IE0-8TIl9rB9l3SCKZXJCW3LZnCFwBztIBmbTqwCtTk8HKe1Ak1qveoNJBARveyUXQ","eBa1pVO-QheZoIfKOKIsCm:APA91bEGfI6oxX9JPXgZM1HpHpPAiZirlS0aEdEIjYILsFAP6KQD-2hoLOldhfZTPQhi_Y4as91YtceDQb7mMQuRTIWz3sKKE_2WNEz5k1L6Je00w01a-Qc","dCGDURSJQ3O5NziBj9goLM:APA91bF7br-m4rRO3nGDis1KBXO3xLfHq-a-MG17_gahNbPkjjzY5IAI5lou3Q7qpCtZSZJ_-ujr7g_puro24bkB1voSw4m7IHz8qopVjYhZzicoR55KgC0","ezqrt0NZTvmhZ8bASuT_sh:APA91bHaSJGfjjwWq_PSsFJNFC2v_G6qxXY3kWBshZ0eepbqV1tQl4gmnlKk5rG46IWixFLsrZkaTaH3g9qb9pCU0H-P6QJz1ZisVXsA8nFF7_Z4DfjPidc","fGXB1XV_Rm-uXWBNCwcVwA:APA91bFuQo8wf_lrYT3Lj1SXpeZsl-Og8EYtyzj3BKY3USl9amusgdk8mBGhSy71OTw75g9bWQCbXip7QVP8xzX8N0KRZh4X-9CeK8XBhizD1JlXIhsxMm0","dm9r09NGREyeJaHn-WZfXp:APA91bFngn2vnlV0CvsiP2HlpLmVRoBFB45gECiAOhZ9BnaZnoszwkJqbE3_R7RTP3Skwd9KFXi5JrFqJrDGph_tymz1F4skxtspjhLqLLhLfs3CetOQT7M","dTPcqc7NTn-QDWFZeLBc4e:APA91bEpxWbSAtJ21jBKnkwwdPDCDWh1HXvoyWTFhAKBkgh2zYSPa8htmziG7C-3mfj2zAQCR-mMt7gFfzT8rNi4_WDRyxAdoWjDbfgKnzbtqB-gV5cNMH8","eOZwY9IIQECEldhaL4H6wP:APA91bGvVEmthe1Ao55uDdnC6zLUVan1aPSf39pSdL2mmNcXMsgibggAa_L1WnIzSFPkoLDhQyb1yJCAsQBoiyT4a1qvd-jZ-xB6Cc_6jcrSkzj6-gotXek","eBa1pVO-QheZoIfKOKIsCm:APA91bFEdmc9Sc3uLnEfIG1bxK942e9C7KKhYKrhR8f-kuts4JT9Gm2h8Y5G3BWd6k8ygmkouZdZmm_ndlLsfo7yi0Ggw6wO8PEjfb3RCg8kq0r7LHt6poc","eW033AnlR0e557ppaJydnu:APA91bEIPiJMDNPH3PIXiFaCy2JNR-R1Lm-05PsIHczjf1mlQrEsBFgdxzWx1LpAn2cxFshojcG2w3EdcDW2zYxwYHvT9Mty5tbMmAHWTVtJx5d1TESuefw","eXZZeiEgTrGk9we9QCMwB-:APA91bHMBKzDQBRvqaObfgbKqq7gvyBpGXhZhODxF9CsvmUwHH7KZxFMoP8wpLvhYpsDQ50L1C7Nqq5wbD44Uux8IOaRWnk603mfuF5vs0GRFnfBo4skQZw","f3zWigTyS2WC-WsxyNo85n:APA91bGSJoqdwurr_cfwwcPlVHkL_JC5RWXGKGCnuC-Ah6sbW6F_bNFGso1Qx_6ZU-P_2lJIK4_GCtrxxgF_2pXCC_cXnWOrBYSojEJXiyYeohFDElvAg5M","d_tO3FV9TV6xavo0ckjWHN:APA91bE0_zEwXkMYqOSRJVEZSyWmE0z7AISdV6dOnAT0FGHTTT_CwIIx65AXXQzIE81qtOpQGtG9Y0zZs8qWx2jSki9MOxBopQxudcN_Dkeh7gWbXrs4bpE","d1UyoshmTsCWr3XsBuMPPO:APA91bE9t3QX2SXn1h-IpTZ6yN2yYY5bu5KplGHtX3OpyD1sZ5JjLgx6ZwtQt6KEMdmjIxK6IU_qPSJhnK-loJdV4TdbC9ZQWWK0PMGKrv-010_HVN_XjVU","c7tQmfLGIEvAv0s-chwHfQ:APA91bFCtkLnOqvGP767rlpba2kjGuX2oyVdImgDnX6jGwg-N9xGeH1ER0BLqq4JNvTCzykIFtILHoRJL6km1KQWh3gNVoeqggqvxhy9jy5ZaaCz1XVWicQ","drfRu3jeQGWHfkPzfunOOF:APA91bGK6P8LIjXkC_VsqwSfKRuFCZ4TjjaKQx9Dk4W5G1FK6_ZD3a_uB40FA9mCr7nAK93zOG7Msxj48P4e5gnqix6sh2SN5mPjPD4cZDo6XM76Oh6VTDo","ccXFDmToQECAhlsSMvD8Te:APA91bEHSN64Ch7pmJhK15OeXTLuza7S77K9_UEOfBvmbmDa7eJFsGK344mDTfbKmYoUBKngMDR4GSwIe1TLaYW4ve_6yfYnakZeEsr95x-ASIc1hup-S0s","d1UyoshmTsCWr3XsBuMPPO:APA91bFyLIX5VsW0KrNvk1X08fSlp00fFIHa7gJflmTly5T3Rb_isNSmo04dd_rOO4FuY-SSDGloiFIakbpnvGAtOcwHZbhWgxZqAGifutJoPdfRgvcMeJg","dRTtZiTPTj2IjxUxtV1NJJ:APA91bGSlKEZzLkOtrTeghh2Y2Ad6druDWfvHHGrLgRYXXTCo7HoCc8VpnXl6ejZQAoveXJRIfrY6SISMIzQmzoEFvemjwoQCIGmHk14j61Yga9_x2woLEw"];
        // $this->sendNotification($t, 'This is a testing push Notification', 'Greetings', [], []);
        
        
        if ($request->input('phone')) {
            return $this->loginByPhone($request);
        }

        if (!auth()->attempt($request->only(['email', 'password']))) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_102,
                'message' => __('errors.' . ResponseError::ERROR_102, locale: $this->language)
            ]);
        }

        $token = auth()->user()->createToken('api_token')->plainTextToken;

        return $this->successResponse('User successfully login', [
            'access_token'  => $token,
            'token_type'    => 'Bearer',
            'user'          => UserResource::make(auth('sanctum')->user()->loadMissing(['shop', 'model'])),
        ]);
    }

    protected function loginByPhone($request): JsonResponse
    {
        if (!auth()->attempt($request->only('phone', 'password'))) {
            return $this->onErrorResponse([
                'code'    => ResponseError::ERROR_102,
                'message' => __('errors.' . ResponseError::ERROR_102, locale: $this->language)
            ]);
        }

        $token = auth()->user()->createToken('api_token')->plainTextToken;

        return $this->successResponse('User successfully login', [
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => UserResource::make(auth('sanctum')->user()->loadMissing(['shop', 'model'])),
        ]);

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

		$token = $user->createToken('api_token')->plainTextToken;

		return $this->successResponse('User successfully login', [
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'user'         => UserResource::make($user),
		]);
	}

    public function logout(): JsonResponse
    {
        try {
            /** @var User $user */
            /** @var PersonalAccessToken $current */
            $user = auth('sanctum')->user();
            
            // Clear the firebase token on logout
            $user->update([
                'firebase_token' => null
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
