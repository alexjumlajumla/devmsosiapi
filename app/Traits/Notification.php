<?php

namespace App\Traits;

use App\Helpers\ResponseError;
use App\Models\Order;
use App\Models\PushNotification;
use App\Models\Settings;
use App\Models\User;
use App\Notifications\FcmNotification;
use App\Services\FCM\FcmTokenService;
use App\Services\PushNotificationService\PushNotificationService;
use Cache;
use Exception;
use Google\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmMessageNotification;
use Kreait\Firebase\Messaging\WebPushConfig;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;

/**
 * App\Traits\Notification
 *
 * @property string $language
 */
trait Notification
{
    protected ?FcmTokenService $fcmTokenService = null;

    /**
     * Get the FCM token service instance
     * @return FcmTokenService
     */
    protected function fcmService(): FcmTokenService
    {
        if (!$this->fcmTokenService) {
            $this->fcmTokenService = app(FcmTokenService::class);
        }
        return $this->fcmTokenService;
    }
    
    /**
     * Send push notification to specified FCM tokens and optionally store in database
     * @param array $receivers Array of FCM tokens to send the notification to
     * @param string|null $message The notification message
     * @param string|null $title The notification title
     * @param mixed $data Additional data to send with the notification
     * @param array $userIds Array of user IDs to store the notification for
     * @param string|null $firebaseTitle Optional title for Firebase notification
     * @param bool $retryOnFailure Whether to retry on failure
     * @return array Array of responses or error information
     */
    public function sendNotification(
        array $receivers = [],
        ?string $message = '',
        ?string $title = null,
        mixed $data = [],
        array $userIds = [],
        ?string $firebaseTitle = '',
        bool $retryOnFailure = true
    ): array {
        $logContext = [
            'receivers_count' => count($receivers),
            'title' => $title,
            'message' => $message,
            'data_type' => gettype($data),
            'user_ids_count' => count($userIds),
            'firebase_title' => $firebaseTitle,
            'notification_type' => data_get($data, 'type', data_get($data, 'order.type', 'general')),
        ];

        try {
            Log::info('Sending push notification', $logContext);

            // If no receivers but we have user IDs, try to get tokens for those users
            if (empty($receivers) && !empty($userIds)) {
                $receivers = $this->getTokensForUserIds($userIds);
                if (empty($receivers)) {
                    $error = 'No FCM tokens found for the provided user IDs';
                    Log::warning($error, ['user_ids' => $userIds]);
                    return $this->notificationErrorResponse($error, 'Not found', 404);
                }
            } elseif (empty($receivers)) {
                $error = 'No FCM tokens or user IDs provided';
                Log::warning($error);
                return $this->notificationErrorResponse($error, 'Bad request', 400);
            }

            // Ensure receivers is an array of valid tokens
            $receivers = $this->validateAndFilterTokens((array) $receivers);
            if (empty($receivers)) {
                $error = 'No valid FCM tokens provided after validation';
                Log::warning($error);
                return $this->notificationErrorResponse($error, 'Bad request', 400);
            }

            // Store notification in database if user IDs are provided
            if (!empty($userIds)) {
                $this->storeNotificationInDatabase($userIds, $title, $message, $data);
            }

            // Use the new FCM notification system
            return $this->sendViaNewFcmSystem(
                $receivers,
                $firebaseTitle ?: $title,
                $message,
                $data,
                $userIds
            );

        } catch (\Exception $e) {
            $errorContext = array_merge($logContext, [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            
            Log::error('Failed to send notification', $errorContext);
            
            // Retry on temporary errors if enabled
            if ($retryOnFailure && $this->isTemporaryError($e)) {
                Log::info('Retrying notification after temporary error', $errorContext);
                return $this->sendNotification(
                    $receivers,
                    $message,
                    $title,
                    $data,
                    $userIds,
                    $firebaseTitle,
                    false // Prevent infinite retries
                );
            }
            
            return $this->notificationErrorResponse(
                $e->getCode() ?: 500,
                'Failed to send notification: ' . $e->getMessage(),
                500
            );
        }
    }
    
    /**
     * Validate and filter FCM tokens
     */
    protected function validateAndFilterTokens(array $tokens): array
    {
        return array_values(array_filter(
            array_unique($tokens),
            fn($token) => !empty($token) && 
                         is_string($token) && 
                         $this->fcmService()->isValidToken($token)
        ));
    }
    
    /**
     * Check if an error is temporary and can be retried
     */
    protected function isTemporaryError(\Exception $e): bool
    {
        // List of HTTP status codes that indicate temporary errors
        $temporaryStatusCodes = [408, 429, 500, 502, 503, 504];
        
        // Check if it's a Guzzle HTTP exception with a status code
        if (method_exists($e, 'getCode') && in_array($e->getCode(), $temporaryStatusCodes, true)) {
            return true;
        }
        
        // Check for common temporary error messages
        $temporaryErrors = [
            'timeout', 'timed out', 'connection', 'unavailable', 'retry',
            'quota', 'limit exceeded', 'too many requests', 'server error'
        ];
        
        $message = strtolower($e->getMessage());
        
        foreach ($temporaryErrors as $error) {
            if (str_contains($message, $error)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Create a standardized error response for notifications
     * 
     * @param string $statusCode
     * @param string $message
     * @param int $httpCode
     * @return array
     */
    protected function notificationErrorResponse(string $statusCode, string $message = '', int $httpCode = 500): array
    {
        return [
            'timestamp' => now(),
            'status' => false,
            'statusCode' => $statusCode,
            'message' => $message
        ];
    }
    
    /**
     * Send notification using the new FCM notification system
     * 
     * @param array $tokens
     * @param string|null $title
     * @param string $message
     * @param array $data
     * @param array $userIds
     * @param string|null $firebaseTitle
     * @return array
     */
    protected function sendViaNewFcmSystem(
        array $tokens,
        ?string $title,
        string $message,
        array $data,
        array $userIds = [],
        ?string $firebaseTitle = null
    ): array {
        $notificationType = data_get($data, 'type', data_get($data, 'order.type', 'general'));
        $title = $firebaseTitle ?: $title ?: config('app.name');
        
        $fcmData = array_merge($data, [
            'type' => $notificationType,
            'timestamp' => now()->toDateTimeString(),
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ]);
        
        try {
            // If we have user IDs, we can use Laravel's notification system
            if (!empty($userIds)) {
                $users = User::whereIn('id', $userIds)->get();
                
                foreach ($users as $user) {
                    $user->notify(
                        new FcmNotification($title, $message, $fcmData, $notificationType)
                    );
                }
                
                return [
                    'status' => 'success',
                    'message' => 'Notification sent via new FCM system',
                    'user_count' => $users->count(),
                    'notification_type' => $notificationType,
                ];
            }
            
            // If we only have tokens, we need to use the FCM service directly
            $fcmService = app(FcmTokenService::class);
            $responses = [];
            
            // Process in chunks to avoid hitting FCM limits
            $chunks = array_chunk($tokens, 500);
            
            foreach ($chunks as $chunk) {
                $message = CloudMessage::new()
                    ->withNotification(FcmMessageNotification::create($title, $message))
                    ->withData($fcmData)
                    ->withDefaultSounds()
                    ->withHighPriority()
                    ->withApnsConfig([
                        'payload' => [
                            'aps' => [
                                'sound' => 'default',
                                'badge' => 1,
                                'mutable-content' => 1,
                            ],
                        ],
                    ]);
                
                $responses[] = $fcmService->sendToTokens($chunk, $message);
            }
            
            return [
                'status' => 'success',
                'message' => 'Notification sent via new FCM system',
                'token_count' => count($tokens),
                'notification_type' => $notificationType,
                'responses' => $responses,
            ];
            
        } catch (MessagingException $e) {
            Log::error('FCM messaging error', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'notification_type' => $notificationType,
            ]);
            
            return [
                'status' => 'error',
                'message' => 'FCM messaging error: ' . $e->getMessage(),
                'code' => $e->getCode(),
                'notification_type' => $notificationType,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send FCM notification', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'notification_type' => $notificationType,
            ]);
            
            return [
                'status' => 'error',
                'message' => 'Failed to send FCM notification: ' . $e->getMessage(),
                'code' => $e->getCode(),
                'notification_type' => $notificationType,
            ];
        }
    }
    
    /**
     * Store notification in the database
     * 
     * @param array $userIds
     * @param string|null $title
     * @param string $message
     * @param array $data
     * @return void
     */
    protected function storeNotificationInDatabase(array $userIds, ?string $title, string $message, array $data): void
    {
        $notificationType = data_get($data, 'type', data_get($data, 'order.type', 'general'));
        
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
        }
    }
    
    /**
            
            return array_unique($tokens);
            
        } catch (\Exception $e) {
            Log::error('Failed to get FCM tokens for user IDs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_ids' => $userIds,
            ]);
            return [];
        }
    }
    
    /**
     * Legacy FCM implementation for backward compatibility
     * 
     * @param array $receivers
     * @param string|null $title
     * @param string $message
     * @param array $data
     * @param array $userIds
     * @param string|null $firebaseTitle
     * @param bool $retryOnFailure
     * @return array
     */
    protected function sendViaLegacyFcm(
        array $receivers,
        ?string $title,
        string $message,
        array $data,
        array $userIds,
        ?string $firebaseTitle,
        bool $retryOnFailure
    ): array {
        $projectId = $this->projectId();
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        Log::debug('Using legacy FCM implementation', ['url' => $url]);

        $http = Http::withToken($this->getAccessToken())
            ->timeout(10) // 10 second timeout
            ->retry(2, 100) // Retry twice with 100ms delay
            ->withHeaders([
                'Content-Type' => 'application/json',
            ]);

        $responses = [];
        $invalidTokens = [];
        $successCount = 0;
        $failureCount = 0;
        
        // Process tokens in chunks to respect FCM limits
        $chunks = array_chunk($receivers, 500); // FCM allows up to 500 tokens per batch

        foreach ($chunks as $chunk) {
            $payload = [
                'message' => [
                    'notification' => [
                        'title' => $firebaseTitle ?: $title,
                        'body' => $message,
                    ],
                    'data' => $data,
                    'tokens' => $chunk,
                ]
            ];

            $logPayload = $payload;
            // Don't log the full tokens in production
            if (app()->environment('production')) {
                $logPayload['message']['tokens'] = array_map(
                    fn($t) => substr($t, 0, 10) . '...', 
                    $logPayload['message']['tokens']
                );
            }

            Log::debug('Sending legacy FCM request', ['payload' => $logPayload]);

            try {
                $response = $http->post($url, $payload);
                $responseData = $response->json();
                $responses[] = $responseData;
                
                // Log the response without exposing tokens in production
                $logResponse = $responseData;
                if (isset($logResponse['results']) && is_array($logResponse['results'])) {
                    $logResponse['results'] = array_map(function($result) {
                        unset($result['message_id']);
                        return $result;
                    }, $logResponse['results']);
                }
                
                Log::debug('Legacy FCM response', [
                    'status' => $response->status(),
                    'response' => $logResponse
                ]);

                if ($response->successful()) {
                    // Check for invalid tokens in the response
                    if (isset($responseData['results']) && is_array($responseData['results'])) {
                        foreach ($responseData['results'] as $index => $result) {
                            if (isset($result['error'])) {
                                $failureCount++;
                                $token = $chunk[$index] ?? null;
                                if ($token) {
                                    $invalidTokens[] = [
                                        'token' => $token,
                                        'error' => $result['error']
                                    ];
                                    
                                    Log::warning('Legacy FCM token error', [
                                        'token_prefix' => substr($token, 0, 10) . '...',
                                        'error' => $result['error']
                                    ]);
                                    
                                    // Remove invalid token if we have user context
                                    if (!empty($userIds) && class_exists(FcmTokenService::class)) {
                                        try {
                                            $fcmService = app(FcmTokenService::class);
                                            $fcmService->removeInvalidTokens($userIds, [$token]);
                                        } catch (\Exception $e) {
                                            Log::error('Failed to remove invalid FCM token', [
                                                'error' => $e->getMessage(),
                                                'token_prefix' => substr($token, 0, 10) . '...',
                                            ]);
                                        }
                                    }
                                }
                            } else {
                                $successCount++;
                            }
                        }
                    }
                } else {
                    $failureCount += count($chunk);
                    Log::error('Failed to send legacy FCM notification', [
                        'status' => $response->status(),
                        'response' => $responseData,
                        'payload' => $logPayload
                    ]);
                    
                    // If it's an authentication error, refresh the token and retry once
                    if ($response->status() === 401 && $retryOnFailure) {
                        Log::info('Refreshing FCM access token and retrying...');
                        Cache::forget('fcm_access_token');
                        return $this->sendViaLegacyFcm(
                            $receivers, 
                            $title, 
                            $message, 
                            $data, 
                            $userIds, 
                            $firebaseTitle,
                            false // Prevent infinite retry loop
                        );
                    }
                }
                
            } catch (\Exception $e) {
                $failureCount += count($chunk);
                Log::error('Exception sending legacy FCM notification', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'chunk_size' => count($chunk),
                ]);
                
                // If we have a connection error, we can retry once
                if (str_contains($e->getMessage(), 'cURL error 28') && $retryOnFailure) {
                    Log::info('Retrying FCM notification after connection timeout...');
                    return $this->sendViaLegacyFcm(
                        $receivers, 
                        $title, 
                        $message, 
                        $data, 
                        $userIds, 
                        $firebaseTitle,
                        false // Prevent infinite retry loop
                    );
                }
            }
        }
        
        // Log the overall result
        $result = [
            'status' => $failureCount === 0 ? 'success' : ($successCount > 0 ? 'partial' : 'failed'),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'invalid_tokens' => $invalidTokens,
            'responses' => $responses,
        ];

        Log::info('FCM notification result', $result);
        return $result;
    }

    /**
     * Get FCM access token
     * 
     * @return string
     * @throws \Exception
     */
    protected function getAccessToken(): string
    {
        $cacheKey = 'fcm_access_token';
        
        return Cache::remember($cacheKey, 3600, function () { // Cache for 1 hour
            $client = new Client();
            $client->useApplicationDefaultCredentials();
            $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
            
            try {
                $token = $client->fetchAccessTokenWithAssertion();
                return $token['access_token'];
            } catch (\Exception $e) {
                Log::error('Failed to get FCM access token', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        });
    }
    
    /**
     * Get Firebase project ID
     * 
     * @return string
     */
    protected function projectId(): string
    {
        return config('services.firebase.project_id') ?: 
               env('FIREBASE_PROJECT_ID') ?: 
               env('GOOGLE_CLOUD_PROJECT_ID', '');
    }


    /**
     * Send push notification to specified FCM tokens and optionally store in database
     * 
     * @param string|null $title The notification title
     * @param mixed $data Additional data to send with the notification
     * @param string|null $firebaseTitle Optional title for Firebase notification
     * @return void
     */
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

                    Log::debug('Processing batch of users with Firebase tokens', [
                        'user_count' => $users->count(),
                        'has_tokens' => !empty($firebaseTokens)
                    ]);

                    foreach ($firebaseTokens as $firebaseToken) {
                        if (empty($firebaseToken)) {
                            continue;
                        }
                        $receives[] = array_filter((array)$firebaseToken, fn($item) => !empty($item));
                    }

                    if (!empty($receives)) {
                        $receives = array_merge(...$receives);
                        
                        Log::debug('Sending notifications to batch of users', [
                            'token_count' => count($receives),
                            'user_count' => count($firebaseTokens)
                        ]);

                        $this->sendNotification(
                            $receives,
                            $title,
                            data_get($data, 'id'),
                            $data,
                            array_keys($firebaseTokens),
                            $firebaseTitle
                        );
                    }
                });
        })->afterResponse();
    }

    private function updateToken(): string
    {
        $googleClient = new Client();
        $googleClient->setAuthConfig(storage_path('app/google-service-account.json'));
        $googleClient->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $token = $googleClient->fetchAccessTokenWithAssertion()['access_token'];
        return $token;
        // return Cache::remember('firebase_auth_token', 300, fn() => $token);
    }

    public function newOrderNotification(Order $order): void
    {
        Log::info('Starting newOrderNotification', [
            'order_id' => $order->id,
            'shop_id' => $order->shop_id
        ]);

        // Get admin tokens
        $adminUsers = User::with(['roles' => fn($q) => $q->where('name', 'admin')])
            ->whereHas('roles', fn($q) => $q->where('name', 'admin'))
            ->whereNotNull('firebase_token')
            ->get(['id', 'firebase_token']);

        $adminFirebaseTokens = $adminUsers->pluck('firebase_token', 'id')->toArray();

        Log::debug('Admin tokens fetched', [
            'admin_count' => $adminUsers->count(),
            'admin_ids' => $adminUsers->pluck('id')->toArray()
        ]);

        // Get seller tokens for the specific shop
        $sellerUsers = User::with([
                'shop' => fn($q) => $q->where('id', $order->shop_id)
            ])
            ->whereHas('shop', fn($q) => $q->where('id', $order->shop_id))
            ->whereNotNull('firebase_token')
            ->get(['id', 'firebase_token']);

        $sellersFirebaseTokens = $sellerUsers->pluck('firebase_token', 'id')->toArray();

        Log::debug('Seller tokens fetched', [
            'seller_count' => $sellerUsers->count(),
            'seller_ids' => $sellerUsers->pluck('id')->toArray()
        ]);

        $aTokens = [];
        $sTokens = [];

        // Process admin tokens
        foreach ($adminFirebaseTokens as $adminId => $adminToken) {
            $tokens = is_array($adminToken) ? array_values($adminToken) : [$adminToken];
            $aTokens = array_merge($aTokens, $tokens);
            
            Log::debug('Admin token processed', [
                'user_id' => $adminId,
                'token_count' => count($tokens),
                'token_sample' => !empty($tokens) ? substr($tokens[0], 0, 10) . '...' : 'none'
            ]);
        }

        // Process seller tokens
        foreach ($sellersFirebaseTokens as $sellerId => $sellerToken) {
            $tokens = is_array($sellerToken) ? array_values($sellerToken) : [$sellerToken];
            $sTokens = array_merge($sTokens, $tokens);
            
            Log::debug('Seller token processed', [
                'user_id' => $sellerId,
                'token_count' => count($tokens),
                'token_sample' => !empty($tokens) ? substr($tokens[0], 0, 10) . '...' : 'none'
            ]);
        }
        $allTokens = array_values(array_unique(array_merge($aTokens, $sTokens)));
        $allUserIds = array_merge(array_keys($adminFirebaseTokens), array_keys($sellersFirebaseTokens));

        Log::info('Sending notification', [
            'total_tokens' => count($allTokens),
            'total_users' => count($allUserIds),
            'notification_type' => PushNotification::NEW_ORDER,
            'order_id' => $order->id
        ]);

        $this->sendNotification(
            $allTokens,
            __('errors.' . ResponseError::NEW_ORDER, ['id' => $order->id], $this->language),
            $order->id,
            $order->setAttribute('type', PushNotification::NEW_ORDER)->only(['id', 'status', 'delivery_type']),
            $allUserIds
        );
    }
}
