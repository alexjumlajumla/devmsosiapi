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
        bool $retryOnFailure = true,
        bool $isWebPush = false
    ): array {
        $notificationType = data_get($data, 'type', data_get($data, 'order.type', 'general'));
        $logContext = [
            'receivers_count' => count($receivers),
            'title' => $title,
            'message' => $message,
            'data_type' => gettype($data),
            'user_ids_count' => count($userIds),
            'firebase_title' => $firebaseTitle,
            'notification_type' => $notificationType,
            'is_web_push' => $isWebPush,
        ];

        try {
            Log::info('Sending push notification', $logContext);

            // If no receivers but we have user IDs, try to get tokens for those users
            if (empty($receivers) && !empty($userIds)) {
                $receivers = $this->getTokensForUserIds($userIds, $isWebPush);
                if (empty($receivers)) {
                    $error = 'No FCM tokens found for the provided user IDs';
                    Log::warning($error, ['user_ids' => $userIds, 'is_web_push' => $isWebPush]);
                    return $this->notificationErrorResponse($error, 'Not found', 404);
                }
            } elseif (empty($receivers)) {
                $error = 'No FCM tokens or user IDs provided';
                Log::warning($error, ['is_web_push' => $isWebPush]);
                return $this->notificationErrorResponse($error, 'Bad request', 400);
            }
            
            // Add web push specific data if this is a web push notification
            if ($isWebPush) {
                $data['is_web_push'] = true;
                $data['click_action'] = $data['click_action'] ?? 'FLUTTER_NOTIFICATION_CLICK';
                $data['icon'] = $data['icon'] ?? url('/images/logo.png');
                $data['badge'] = $data['badge'] ?? '/images/badge.png';
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
        $validatedTokens = [];
        $allowTestTokens = filter_var(env('FIREBASE_ALLOW_TEST_TOKENS', 'false'), FILTER_VALIDATE_BOOLEAN);
        $isTestEnv = app()->environment('local', 'staging', 'development');
        
        Log::debug('Validating and filtering FCM tokens', [
            'total_tokens' => count($tokens),
            'allow_test_tokens' => $allowTestTokens ? 'true' : 'false',
            'is_test_environment' => $isTestEnv ? 'true' : 'false'
        ]);
        
        foreach ($tokens as $token) {
            if (empty($token) || !is_string($token)) {
                Log::debug('Skipping invalid token', [
                    'token' => $token,
                    'reason' => 'Empty or not a string'
                ]);
                continue;
            }
            
            // Check if it's a test token
            if (str_starts_with($token, 'test_fcm_token_') || str_starts_with($token, 'test_')) {
                if ($allowTestTokens || $isTestEnv) {
                    Log::debug('Accepting test token', [
                        'token_prefix' => substr($token, 0, 15) . '...',
                        'length' => strlen($token),
                        'user_id' => str_replace('test_fcm_token_', '', $token),
                        'FIREBASE_ALLOW_TEST_TOKENS' => $allowTestTokens ? 'true' : 'false'
                    ]);
                    $validatedTokens[] = $token;
                } else {
                    Log::debug('Skipping test token (not allowed in this environment)', [
                        'token_prefix' => substr($token, 0, 15) . '...',
                        'length' => strlen($token),
                        'user_id' => str_replace('test_fcm_token_', '', $token),
                        'FIREBASE_ALLOW_TEST_TOKENS' => 'false'
                    ]);
                }
                continue;
            }
            
            if ($this->fcmService()->isValidToken($token)) {
                $validatedTokens[] = $token;
            } else {
                Log::debug('Skipping invalid FCM token', [
                    'token_prefix' => substr($token, 0, 10) . '...',
                    'length' => strlen($token)
                ]);
            }
        }
        
        Log::debug('Finished validating FCM tokens', [
            'valid_tokens' => count($validatedTokens),
            'total_processed' => count($tokens)
        ]);
        
        return array_values(array_unique($validatedTokens));
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
        $isTestEnv = app()->environment('local', 'staging', 'development');
        $allowTestTokens = filter_var(env('FIREBASE_ALLOW_TEST_TOKENS', 'true'), FILTER_VALIDATE_BOOLEAN);
        
        // Log the notification attempt
        Log::info('Sending FCM notification', [
            'title' => $title,
            'message' => $message,
            'data' => $data,
            'token_count' => count($tokens),
            'user_ids' => $userIds,
            'environment' => app()->environment(),
            'allow_test_tokens' => $allowTestTokens,
            'is_test_environment' => $isTestEnv,
            'notification_type' => data_get($data, 'type', data_get($data, 'order.type', 'general'))
        ]);
        $notificationType = data_get($data, 'type', data_get($data, 'order.type', 'general'));
        $title = $firebaseTitle ?: $title ?: config('app.name');
        
        // Add additional data for tracking
        $fcmData = array_merge($data, [
            'type' => $notificationType,
            'timestamp' => now()->toDateTimeString(),
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            'environment' => app()->environment(),
            'is_test_notification' => $isTestEnv,
            'notification_id' => (string) Str::uuid(),
        ]);
        
        // Clean up any sensitive data that shouldn't be in the notification
        unset($fcmData['password'], $fcmData['password_confirmation'], $fcmData['token']);
        
        try {
            // If we have user IDs, we can use Laravel's notification system
            if (!empty($userIds)) {
                $users = User::whereIn('id', $userIds)->get();
                $successCount = 0;
                $errors = [];
                
                Log::info('Sending notifications to users', [
                    'user_count' => $users->count(),
                    'user_ids' => $userIds
                ]);
                
                foreach ($users as $user) {
                    try {
                        $user->notify(
                            new FcmNotification($title, $message, $fcmData, $notificationType)
                        );
                        $successCount++;
                    } catch (\Exception $e) {
                        Log::error('Failed to send notification to user', [
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                            'notification_type' => $notificationType
                        ]);
                        $errors[] = [
                            'user_id' => $user->id,
                            'error' => $e->getMessage()
                        ];
                    }
                }
                
                $result = [
                    'status' => $successCount > 0 ? 'partial' : 'error',
                    'message' => $successCount > 0 
                        ? 'Sent to ' . $successCount . ' of ' . count($users) . ' users' 
                        : 'Failed to send to all users',
                    'user_count' => $users->count(),
                    'success_count' => $successCount,
                    'failed_count' => count($users) - $successCount,
                    'notification_type' => $notificationType,
                ];
                
                if (!empty($errors)) {
                    $result['errors'] = $errors;
                }
                
                return $result;
            }
            
            // If we only have tokens, we need to use the FCM service directly
            $fcmService = app(FcmTokenService::class);
            $responses = [];
            $sentCount = 0;
            $failedCount = 0;
            $invalidTokens = [];
            
            // Check if we're in a test environment with test tokens
            $isTestEnv = app()->environment('local', 'staging', 'development');
            $allowTestTokens = filter_var(env('FIREBASE_ALLOW_TEST_TOKENS', 'true'), FILTER_VALIDATE_BOOLEAN);
            $hasTestTokens = false;
            
            // Check for test tokens
            foreach ($tokens as $token) {
                if (str_starts_with($token, 'test_fcm_token_') || str_starts_with($token, 'test_')) {
                    $hasTestTokens = true;
                    break;
                }
            }
            
            // In test environment with test tokens, simulate success without hitting FCM
            if ($isTestEnv && $allowTestTokens && $hasTestTokens) {
                Log::info('Test environment with test tokens detected, simulating success', [
                    'token_count' => count($tokens),
                    'test_token_count' => array_reduce($tokens, function($carry, $token) {
                        return $carry + (str_starts_with($token, 'test_fcm_token_') || str_starts_with($token, 'test_') ? 1 : 0);
                    }, 0),
                    'notification_type' => $notificationType
                ]);
                
                // Simulate successful responses for test tokens
                $simulatedResponses = [];
                foreach ($tokens as $token) {
                    $isTestToken = str_starts_with($token, 'test_fcm_token_') || str_starts_with($token, 'test_');
                    $simulatedResponses[] = [
                        'success' => true,
                        'message' => $isTestToken 
                            ? 'Test token processed (not sent to FCM)' 
                            : 'Token would be processed in production',
                        'token' => $token,
                        'is_test' => $isTestToken,
                        'message_id' => $isTestToken ? 'test:' . uniqid() : null
                    ];
                }
                
                // Return simulated successful response
                return [
                    'status' => 'success',
                    'message' => 'Test notifications processed successfully',
                    'user_count' => count($tokens),
                    'sent_count' => count($tokens),
                    'failed_count' => 0,
                    'notification_type' => $notificationType,
                    'is_test' => true,
                    'responses' => $simulatedResponses
                ];
            }
            
            // Process in chunks to avoid hitting FCM limits (only for real tokens in production)
            $chunks = array_chunk($tokens, 100);
            
            Log::info('Sending notifications to FCM tokens', [
                'token_count' => count($tokens),
                'chunk_count' => count($chunks),
                'notification_type' => $notificationType,
                'is_test_environment' => $isTestEnv,
                'has_test_tokens' => $hasTestTokens
            ]);
            
            foreach ($chunks as $chunkIndex => $chunk) {
                try {
                    $cloudMessage = CloudMessage::new()
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
                    
                    Log::debug('Sending FCM chunk', [
                        'chunk_index' => $chunkIndex,
                        'chunk_size' => count($chunk),
                        'first_token' => substr(reset($chunk), 0, 10) . '...',
                        'is_test_environment' => $isTestEnv
                    ]);
                    
                    $response = $fcmService->sendToTokens($chunk, $cloudMessage);
                    $responses[] = $response;
                    
                    // Update counters
                    $sentCount += $response['sent'] ?? 0;
                    $failedCount += $response['failed'] ?? 0;
                    
                    if (!empty($response['invalid_tokens'])) {
                        $invalidTokens = array_merge($invalidTokens, $response['invalid_tokens']);
                    }
                    
                } catch (\Exception $e) {
                    Log::error('Error sending FCM chunk', [
                        'chunk_index' => $chunkIndex,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'is_test_environment' => $isTestEnv
                    ]);
                    $failedCount += count($chunk);
                }
            }
            
            // Log the overall result
            $result = [
                'status' => $failedCount === 0 ? 'success' : ($sentCount > 0 ? 'partial' : 'error'),
                'message' => $failedCount === 0 
                    ? 'All notifications sent successfully' 
                    : ($sentCount > 0 
                        ? "$sentCount sent, $failedCount failed" 
                        : 'All notifications failed to send'),
                'user_count' => count($tokens),
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'notification_type' => $notificationType,
                'invalid_token_count' => count($invalidTokens),
            ];
            
            if (!empty($invalidTokens)) {
                $result['invalid_token_samples'] = array_slice($invalidTokens, 0, 5);
            }
            
            Log::info('FCM notification batch completed', $result);
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Error sending FCM notification: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
                'notification_type' => $notificationType,
                'title' => $title,
                'message' => $message
            ]);
            
            return [
                'status' => 'error',
                'message' => 'Failed to send notification: ' . $e->getMessage(),
                'notification_type' => $notificationType,
                'error' => $e->getMessage()
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
            // Store notification for each user
            $notifications = [];
            $now = now();
            
            // Ensure data is properly encoded as JSON
            $jsonData = is_array($data) ? json_encode($data) : (is_string($data) ? $data : '');
            
            foreach ($userIds as $userId) {
                $notifications[] = [
                    'user_id' => $userId,
                    'type' => $notificationType,
                    'title' => $title ?? '',
                    'body' => $message,
                    'data' => $jsonData,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            
            if (!empty($notifications)) {
                \DB::table('push_notifications')->insert($notifications);
            }
            
        } catch (\Exception $e) {
            Log::error('Error storing notification in database: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * Send notification via legacy FCM API
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
                }
            } catch (\Exception $e) {
                $failureCount += count($chunk);
                Log::error('Exception while sending legacy FCM notification', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'payload' => $logPayload,
                    'message' => $message,
                    'data' => $data,
                    'user_ids' => $userIds,
                    'firebase_title' => $firebaseTitle
                ]);
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
