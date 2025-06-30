<?php

namespace App\Http\Controllers\API\v1;

use App\Http\Controllers\API\v1\BaseController;
use App\Models\User;
use App\Services\FCM\FcmTokenService;
use App\Services\OrderNotificationService\OrderNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TestController extends BaseController
{
    private FcmTokenService $fcmTokenService;
    private OrderNotificationService $orderNotificationService;

    public function __construct(
        FcmTokenService $fcmTokenService,
        OrderNotificationService $orderNotificationService
    ) {
        $this->fcmTokenService = $fcmTokenService;
        $this->orderNotificationService = $orderNotificationService;
        $this->middleware('auth:sanctum');
    }

    /**
     * Send a test notification to the authenticated user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendTestNotification(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $title = $request->input('title', 'Test Notification');
            $body = $request->input('body', 'This is a test notification');
            $data = $request->input('data', ['type' => 'test']);

            Log::info('[TestController] Sending test notification', [
                'user_id' => $user->id,
                'title' => $title,
                'body' => $body,
                'data' => $data
            ]);

            // Get user's FCM tokens
            $tokens = $user->getFcmTokens();
            
            Log::info('[TestController] User FCM tokens', [
                'user_id' => $user->id,
                'token_count' => count($tokens),
                'tokens' => array_map(function($token) {
                    return substr($token, 0, 10) . '...' . substr($token, -5);
                }, $tokens)
            ]);

            if (empty($tokens)) {
                return $this->errorResponse(
                    'No FCM tokens found for user. Please register a token first.',
                    404,
                    [
                        'user_id' => $user->id,
                        'token_count' => 0
                    ]
                );
            }

            // Send test notification using the notification service
            $result = $this->orderNotificationService->sendToUser(
                $user,
                $body,
                $title,
                $data
            );

            Log::info('[TestController] Test notification result', [
                'user_id' => $user->id,
                'result' => $result
            ]);

            return $this->successResponse(
                'Test notification sent successfully',
                [
                    'user_id' => $user->id,
                    'tokens_sent_to' => count($tokens),
                    'notification_result' => $result
                ]
            );

        } catch (\Exception $e) {
            Log::error('[TestController] Error sending test notification', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Failed to send test notification: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get FCM token status for the authenticated user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTokenStatus(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $tokens = $user->getFcmTokens();
            $rawTokens = $user->firebase_token;

            Log::info('[TestController] Getting token status', [
                'user_id' => $user->id,
                'token_count' => count($tokens),
                'raw_token_type' => gettype($rawTokens),
                'raw_token_is_null' => is_null($rawTokens),
                'raw_token_is_array' => is_array($rawTokens)
            ]);

            $tokenDetails = [];
            foreach ($tokens as $index => $token) {
                $tokenDetails[] = [
                    'index' => $index,
                    'length' => strlen($token),
                    'prefix' => substr($token, 0, 10) . '...',
                    'suffix' => '...' . substr($token, -5),
                    'valid_format' => $this->fcmTokenService->isValidFcmToken($token)
                ];
            }

            return $this->successResponse(
                'Token status retrieved successfully',
                [
                    'user_id' => $user->id,
                    'total_tokens' => count($tokens),
                    'raw_token_type' => gettype($rawTokens),
                    'raw_token_is_null' => is_null($rawTokens),
                    'raw_token_is_array' => is_array($rawTokens),
                    'tokens' => $tokenDetails
                ]
            );

        } catch (\Exception $e) {
            Log::error('[TestController] Error getting token status', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Failed to get token status: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Manually register an FCM token for testing
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function registerTestToken(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $token = $request->input('token');

            if (empty($token)) {
                return $this->errorResponse('Token is required', 400);
            }

            Log::info('[TestController] Registering test token', [
                'user_id' => $user->id,
                'token_length' => strlen($token),
                'token_prefix' => substr($token, 0, 10) . '...',
                'token_suffix' => '...' . substr($token, -5)
            ]);

            // Validate token format
            if (!$this->fcmTokenService->isValidFcmToken($token)) {
                return $this->errorResponse(
                    'Invalid FCM token format',
                    400,
                    [
                        'token_length' => strlen($token),
                        'token_prefix' => substr($token, 0, 10) . '...'
                    ]
                );
            }

            // Add token using the service
            $result = $this->fcmTokenService->addToken($user, $token);

            if ($result) {
                // Refresh user to get updated tokens
                $user->refresh();
                $updatedTokens = $user->getFcmTokens();

                Log::info('[TestController] Test token registered successfully', [
                    'user_id' => $user->id,
                    'new_token_count' => count($updatedTokens)
                ]);

                return $this->successResponse(
                    'Test token registered successfully',
                    [
                        'user_id' => $user->id,
                        'token_added' => true,
                        'total_tokens' => count($updatedTokens),
                        'token_prefix' => substr($token, 0, 10) . '...'
                    ]
                );
            } else {
                return $this->errorResponse(
                    'Failed to register test token',
                    500
                );
            }

        } catch (\Exception $e) {
            Log::error('[TestController] Error registering test token', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Failed to register test token: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Clear all FCM tokens for the authenticated user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function clearTokens(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $tokenCount = count($user->getFcmTokens());

            Log::info('[TestController] Clearing all tokens', [
                'user_id' => $user->id,
                'tokens_to_clear' => $tokenCount
            ]);

            $user->firebase_token = null;
            $user->save();

            Log::info('[TestController] Tokens cleared successfully', [
                'user_id' => $user->id,
                'tokens_cleared' => $tokenCount
            ]);

            return $this->successResponse(
                'All FCM tokens cleared successfully',
                [
                    'user_id' => $user->id,
                    'tokens_cleared' => $tokenCount
                ]
            );

        } catch (\Exception $e) {
            Log::error('[TestController] Error clearing tokens', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Failed to clear tokens: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * Get system FCM configuration status
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSystemStatus(Request $request): JsonResponse
    {
        try {
            $config = [
                'firebase_project_id' => config('fcm.project_id'),
                'firebase_api_key' => config('fcm.api_key') ? 'Present' : 'Missing',
                'firebase_sender_id' => config('fcm.sender_id'),
                'firebase_vapid_key' => config('fcm.vapid_key') ? 'Present' : 'Missing',
                'fcm_server_key' => config('fcm.server_key') ? 'Present' : 'Missing',
                'app_environment' => app()->environment(),
                'allow_test_tokens' => filter_var(env('FIREBASE_ALLOW_TEST_TOKENS', 'false'), FILTER_VALIDATE_BOOLEAN),
                'firebase_service_account' => file_exists(storage_path('app/google-service-account.json')) ? 'Present' : 'Missing'
            ];

            // Check if Firebase services are properly configured
            $firebaseServices = [];
            try {
                $firebaseServices['messaging'] = app('firebase.messaging') ? 'Available' : 'Not available';
            } catch (\Exception $e) {
                $firebaseServices['messaging'] = 'Error: ' . $e->getMessage();
            }

            try {
                $firebaseServices['auth'] = app('firebase.auth') ? 'Available' : 'Not available';
            } catch (\Exception $e) {
                $firebaseServices['auth'] = 'Error: ' . $e->getMessage();
            }

            return $this->successResponse(
                'System status retrieved successfully',
                [
                    'config' => $config,
                    'firebase_services' => $firebaseServices,
                    'current_user_id' => $request->user()->id ?? null
                ]
            );

        } catch (\Exception $e) {
            Log::error('[TestController] Error getting system status', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Failed to get system status: ' . $e->getMessage(),
                500
            );
        }
    }
} 