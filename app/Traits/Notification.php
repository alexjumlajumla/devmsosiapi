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


    /**
     * Send push notification to specified FCM tokens and optionally store in database
     * 
     * @param array $receivers Array of FCM tokens to send the notification to
     * @param string|null $message The notification message
     * @param string|null $title The notification title
     * @param mixed $data Additional data to send with the notification
     * @param array $userIds Array of user IDs to store the notification for
     * @param string|null $firebaseTitle Optional title for Firebase notification
     * @return void
     */
    public function sendNotification(
        array   $receivers = [],
        ?string $message = '',
        ?string $title = null,
        mixed   $data = [],
        array   $userIds = [],
        ?string $firebaseTitle = '',
    ): void {
        dispatch(function () use ($receivers, $message, $title, $data, $userIds, $firebaseTitle) {
            $logContext = [
                'receivers_count' => is_array($receivers) ? count($receivers) : 0,
                'title' => $title,
                'message' => $message,
                'data_type' => gettype($data),
                'user_ids_count' => is_array($userIds) ? count($userIds) : 0,
                'firebase_title' => $firebaseTitle
            ];

            try {
                Log::info('Sending push notification', $logContext);

                if (empty($receivers)) {
                    Log::warning('No FCM tokens provided to send notification to');
                    return;
                }

                // Store notification in database if user IDs are provided
                if (is_array($userIds) && count($userIds) > 0) {
                    $notificationType = data_get($data, 'order.type', data_get($data, 'type', 'general'));
                    
                    Log::debug('Storing notification in database', [
                        'user_ids' => $userIds,
                        'type' => $notificationType,
                        'title' => $title,
                        'message' => $message
                    ]);
                    
                    try {
                        (new PushNotificationService)->storeMany([
                            'type' => $notificationType,
                            'title' => $title,
                            'body' => $message,
                            'data' => $data,
                            'sound' => 'default',
                        ], $userIds);
                        
                        Log::info('Successfully stored notification in database', [
                            'user_count' => count($userIds)
                        ]);
                        
                    } catch (\Throwable $e) {
                        Log::error('Failed to store notification in database', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'user_ids' => $userIds
                        ]);
                        // Continue with FCM send even if DB storage fails
                    }
                }

                // Prepare FCM request
                $projectId = $this->projectId();
                $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

                Log::debug('Preparing FCM request', [
                    'url' => $url,
                    'project_id' => $projectId,
                    'receivers_count' => is_array($receivers) ? count($receivers) : 0
                ]);

                $token = $this->updateToken();
                if (empty($token)) {
                    throw new \RuntimeException('Failed to get FCM access token');
                }
                $headers = [
                    'Authorization' => "Bearer $token",
                    'Content-Type'  => 'application/json'
                ];

                $successCount = 0;
                $errorCount = 0;

                foreach ($receivers as $receiver) {
                    try {
                        if (empty($receiver)) {
                            Log::warning('Empty FCM token encountered, skipping');
                            continue;
                        }

                        Log::debug('Sending FCM notification', [
                            'receiver' => substr($receiver, 0, 10) . '...' . substr($receiver, -5),
                            'title' => $firebaseTitle ?? $title,
                            'message' => $message,
                            'data' => [
                                'id' => (string)($data['id'] ?? ''),
                                'status' => (string)($data['status'] ?? ''),
                                'type' => (string)($data['type'] ?? '')
                            ]
                        ]);

                        // Send notification to FCM
                        $response = Http::withHeaders($headers)->post($url, [
                            'message' => [
                                'token' => $receiver,
                                'notification' => [
                                    'title' => $firebaseTitle ?? $title,
                                    'body'  => $message,
                                ],
                                'data' => [
                                    'id'     => (string)($data['id'] ?? ''),
                                    'status' => (string)($data['status'] ?? ''),
                                    'type'   => (string)($data['type'] ?? '')
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

                        Log::debug('FCM response received', [
                            'receiver' => substr($receiver, 0, 10) . '...' . substr($receiver, -5),
                            'status' => $response->status(),
                            'body' => $response->body()
                        ]);

                        if ($response->successful()) {
                            $successCount++;
                        } else {
                            $errorCount++;
                            Log::error('FCM API returned error', [
                                'status' => $response->status(),
                                'body' => $response->body(),
                                'receiver' => substr($receiver, 0, 10) . '...' . substr($receiver, -5)
                            ]);
                        }

                    } catch (\Throwable $e) {
                        $errorCount++;
                        Log::error('Error sending FCM notification', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                            'receiver' => isset($receiver) ? substr($receiver, 0, 10) . '...' . substr($receiver, -5) : 'null'
                        ]);
                    }
                }

                Log::info('FCM notification batch completed', [
                    'total' => count($receivers),
                    'successful' => $successCount,
                    'failed' => $errorCount
                ]);

            } catch (\Throwable $e) {
                Log::error('Error in sendNotification job', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e; // Re-throw to allow job retries if configured
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
