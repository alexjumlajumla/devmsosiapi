<?php

namespace App\Traits;

use App\Helpers\ResponseError;
use App\Models\Order;
use App\Models\PushNotification;
use App\Models\Settings;
use App\Models\User;
use App\Services\PushNotificationService\PushNotificationService;
use Cache;
use Google\Client;
use Illuminate\Support\Facades\Http;
use Log;

/**
 * App\Traits\Notification
 *
 * @property string $language
 */
trait Notification
{
	public function sendNotification1(
		array   $receivers = [],
		?string $message = '',
		?string $title = null,
		mixed   $data = [],
		array   $userIds = [],
		?string $firebaseTitle = '',
	): void
	{
		dispatch(function () use ($receivers, $message, $title, $data, $userIds, $firebaseTitle) {
			if (empty($receivers)) {
				return;
			}

			\Log::info('[PushService] Notification Data', [
				'receivers' => $receivers,
				'title' => $title,
				'message' => $message,
				'data' => $data,
				'userIds' => $userIds,
				'firebaseTitle' => $firebaseTitle
			]);


			$type = data_get($data, 'order.type');

			if (is_array($userIds) && count($userIds) > 0) {
				\Log::info('[PushService] Storing Notification for Users', [
					'userIds' => $userIds,
					'type' => $type ?? data_get($data, 'type'),
					'title' => $title,
					'message' => $message,
					'data' => $data
				]);
				(new PushNotificationService)->storeMany([
					'type' 	=> $type ?? data_get($data, 'type'),
					'title' => $title,
					'body' 	=> $message,
					'data' 	=> $data,
					'sound' => 'default',
				], $userIds);
			}

			$url = "https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send";

			$token = $this->updateToken();

			\Log::info('[PushService] Sending Notification to FCM 111', [
				'url' => $url,
				'token' => $token
			]);

			$headers = [
				'Authorization' => "Bearer $token",
				'Content-Type'  => 'application/json'
			];

			foreach ($receivers as $receiver) {
				\Log::info('[PushService] inside', [
					'receiver' => $receiver,
					'firebaseTitle' => $firebaseTitle ?? $title,
					'message' => $message,
					'data' => [
						'id' => (string)($data['id'] ?? ''),
						'status' => (string)($data['status'] ?? ''),
						'type' => (string)($data['type'] ?? '')
					]
				]);
				Http::withHeaders($headers)->post($url, [ // $request =
					'message' => [
						'token' => $receiver,
						'notification' => [
							'title' => $firebaseTitle ?? $title,
							'body' 	=> $message,
						],
						'data' => [
							'id'     => (string)($data['id'] 	 ?? ''),
							'status' => (string)($data['status'] ?? ''),
							'type'   => (string)($data['type'] 	 ?? '')
						],
						'android' => [
							'notification' => [
								'sound' => 'default',
							]
						],
						'apns' => [
							'payload' => [
								'aps' => [
									'sound' => 'default'
								]
							]
						]
					]
				]);
			}

		})->afterResponse();
	}


	public function sendNotification(
		array   $receivers = [],
		?string $message = '',
		?string $title = null,
		mixed   $data = [],
		array   $userIds = [],
		?string $firebaseTitle = '',
	): void
	{
		dispatch(function () use ($receivers, $message, $title, $data, $userIds, $firebaseTitle) {
			if (empty($receivers)) {
				return;
			}
	
			\Log::info('11111 first data', [
				'receivers' => $receivers,
				'title' => $title,
				'message' => $message,
				'data' => $data,
				'userIds' => $userIds,
				'firebaseTitle' => $firebaseTitle
			]);
	
			$type = data_get($data, 'order.type');
	
			if (is_array($userIds) && count($userIds) > 0) {
				\Log::info('22222222', [
					'userIds' => $userIds,
					'type' => $type ?? data_get($data, 'type'),
					'title' => $title,
					'message' => $message,
					'data' => $data
				]);
				(new PushNotificationService)->storeMany([
					'type' 	=> $type ?? data_get($data, 'type'),
					'title' => $title,
					'body' 	=> $message,
					'data' 	=> $data,
					'sound' => 'default',
				], $userIds);
			}
	
			$url = "https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send";
	
			$token = $this->updateToken();
	
			\Log::info('[PushService 3333333', [
				'url' => $url,
				'token' => $token
			]);
	
			$headers = [
				'Authorization' => "Bearer $token",
				'Content-Type'  => 'application/json'
			];
	
			foreach ($receivers as $receiver) {
				\Log::info('[PushService] inside for loop 44444', [
					'receiver' => $receiver,
					'firebaseTitle' => $firebaseTitle ?? $title,
					'message' => $message,
					'data' => [
						'id' => (string)($data['id'] ?? ''),
						'status' => (string)($data['status'] ?? ''),
						'type' => (string)($data['type'] ?? '')
					]
				]);
	
				// Store response from Firebase
				$response = Http::withHeaders($headers)->post($url, [
					'message' => [
						'token' => $receiver,
						'notification' => [
							'title' => $firebaseTitle ?? $title,
							'body' 	=> $message,
						],
						'data' => [
							'id'     => (string)($data['id'] 	 ?? ''),
							'status' => (string)($data['status'] ?? ''),
							'type'   => (string)($data['type'] 	 ?? '')
						],
						'android' => [
							'notification' => [
								'sound' => 'default',
							]
						],
						'apns' => [
							'payload' => [
								'aps' => [
									'sound' => 'default'
								]
							]
						]
					]
				]);
	
				// Log the response
				\Log::info('[PushService] 5555555', [
					'receiver' => $receiver,
					'status' => $response->status(),
					'body' => $response->body()
				]);
			}
	
		})->afterResponse();
	}
	




	public function sendAllNotification(?string $title = null, mixed $data = [], ?string $firebaseTitle = ''): void
	{
		dispatch(function () use ($title, $data, $firebaseTitle) {

			User::select([
				'id',
				'deleted_at',
				'active',
				'email_verified_at',
				'phone_verified_at',
				'firebase_token',
			])
				->where('active', 1)
				->where(fn($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))
				->whereNotNull('firebase_token')
				->orderBy('id')
				->chunk(100, function ($users) use ($title, $data, $firebaseTitle) {

					$firebaseTokens = $users?->pluck('firebase_token', 'id')?->toArray();

					$receives = [];

					Log::error('firebaseTokens ', [
						'count' => !empty($firebaseTokens) ? count($firebaseTokens) : $firebaseTokens
					]);

					foreach ($firebaseTokens as $firebaseToken) {

						if (empty($firebaseToken)) {
							continue;
						}

						$receives[] = array_filter($firebaseToken, fn($item) => !empty($item));
					}

					$receives = array_merge(...$receives);

					Log::error('count rece ' . count($receives));

					$this->sendNotification(
						$receives,
						$title,
						data_get($data, 'id'),
						$data,
						array_keys(is_array($firebaseTokens) ? $firebaseTokens : []),
						$firebaseTitle
					);

				});

		})->afterResponse();

	}

	private function updateToken(): string
	{
		$googleClient = new Client;
		$googleClient->setAuthConfig(storage_path('app/google-service-account.json'));
		$googleClient->addScope('https://www.googleapis.com/auth/firebase.messaging');

		$token = $googleClient->fetchAccessTokenWithAssertion()['access_token'];
		return $token;
		// return Cache::remember('firebase_auth_token', 300, fn() => $token);
	}

	public function newOrderNotification(Order $order): void
	{
		$adminFirebaseTokens = User::with(['roles' => fn($q) => $q->where('name', 'admin')])
			->whereHas('roles', fn($q) => $q->where('name', 'admin'))
			->whereNotNull('firebase_token')
			->pluck('firebase_token', 'id')
			->toArray();

		$sellersFirebaseTokens = User::with([
			'shop' => fn($q) => $q->where('id', $order->shop_id)
		])
			->whereHas('shop', fn($q) => $q->where('id', $order->shop_id))
			->whereNotNull('firebase_token')
			->pluck('firebase_token', 'id')
			->toArray();

		$aTokens = [];
		$sTokens = [];

		foreach ($adminFirebaseTokens as $adminToken) {
			$aTokens = array_merge($aTokens, is_array($adminToken) ? array_values($adminToken) : [$adminToken]);
		}

		foreach ($sellersFirebaseTokens as $sellerToken) {
			$sTokens = array_merge($sTokens, is_array($sellerToken) ? array_values($sellerToken) : [$sellerToken]);
		}

		$this->sendNotification(
			array_values(array_unique(array_merge($aTokens, $sTokens))),
			__('errors.' . ResponseError::NEW_ORDER, ['id' => $order->id], $this->language),
            $order->id,
            $order->setAttribute('type', PushNotification::NEW_ORDER)?->only(['id', 'status', 'delivery_type']),
			array_merge(array_keys($adminFirebaseTokens), array_keys($sellersFirebaseTokens))
		);

	}

	private function projectId()
	{
		return Settings::where('key', 'project_id')->value('value');
	}
}
