<?php

namespace App\Http\Controllers\API\v1\User;

use App\Http\Controllers\API\v1\BaseController;
use App\Http\Requests\FcmTokenRequest;
use App\Services\FCM\FcmTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FcmTokenController extends BaseController
{
    private FcmTokenService $fcmTokenService;

    public function __construct(FcmTokenService $fcmTokenService)
    {
        $this->fcmTokenService = $fcmTokenService;
        $this->middleware('auth:sanctum');
    }

    /**
     * Add or update FCM token
     *
     * @param FcmTokenRequest $request
     * @return JsonResponse
     */
    public function update(FcmTokenRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $token = $request->input('token');
            
            $result = $this->fcmTokenService->addToken($user, $token);
            
            Log::info('FCM token update request', [
                'user_id' => $user->id,
                'token_prefix' => substr($token, 0, 10) . '...' . substr($token, -5),
                'success' => $result
            ]);
            
            if ($result) {
                return $this->successResponse(
                    'FCM token updated successfully',
                    [
                        'total_tokens' => count($user->refresh()->getFcmTokens())
                    ]
                );
            }
            
            return $this->errorResponse(
                'Failed to update FCM token. The token may be invalid or already exists.',
                400
            );
            
        } catch (\Exception $e) {
            Log::error('Error updating FCM token', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(
                'An error occurred while updating the FCM token',
                500
            );
        }
    }

    /**
     * Remove FCM token
     *
     * @param FcmTokenRequest $request
     * @return JsonResponse
     */
    public function remove(FcmTokenRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $token = $request->input('token');
            
            $result = $this->fcmTokenService->removeToken($user, $token);
            
            Log::info('FCM token removal request', [
                'user_id' => $user->id,
                'token_prefix' => substr($token, 0, 10) . '...' . substr($token, -5),
                'success' => $result
            ]);
            
            if ($result) {
                return $this->successResponse(
                    'FCM token removed successfully',
                    [
                        'remaining_tokens' => count($user->refresh()->getFcmTokens())
                    ]
                );
            }
            
            return $this->errorResponse(
                'FCM token not found',
                404
            );
            
        } catch (\Exception $e) {
            Log::error('Error removing FCM token', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(
                'An error occurred while removing the FCM token',
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
    public function clear(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $tokenCount = count($user->getFcmTokens());
            
            $result = $this->fcmTokenService->clearTokens($user);
            
            Log::info('FCM tokens clear request', [
                'user_id' => $user->id,
                'tokens_cleared' => $tokenCount,
                'success' => $result
            ]);
            
            if ($result) {
                return $this->successResponse(
                    'All FCM tokens cleared successfully',
                    ['tokens_cleared' => $tokenCount]
                );
            }
            
            return $this->errorResponse(
                'No FCM tokens to clear',
                404
            );
            
        } catch (\Exception $e) {
            Log::error('Error clearing FCM tokens', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(
                'An error occurred while clearing FCM tokens',
                500
            );
        }
    }
    
    /**
     * Get the current user's FCM tokens
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getTokens(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $tokens = $user->getFcmTokens();
            
            // Don't expose full tokens in the response
            $maskedTokens = array_map(function($token) {
                return [
                    'prefix' => substr($token, 0, 10) . '...',
                    'suffix' => '...' . substr($token, -5),
                    'length' => strlen($token)
                ];
            }, $tokens);
            
            return $this->successResponse(
                'FCM tokens retrieved successfully',
                [
                    'total_tokens' => count($tokens),
                    'tokens' => $maskedTokens
                ]
            );
            
        } catch (\Exception $e) {
            Log::error('Error retrieving FCM tokens', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(
                'An error occurred while retrieving FCM tokens',
                500
            );
        }
    }
}
