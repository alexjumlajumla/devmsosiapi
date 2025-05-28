<?php

namespace App\Http\Controllers\API\v1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\FcmTokenRequest;
use App\Services\FCM\FcmTokenService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class FcmTokenController extends Controller
{
    use ApiResponse;

    /**
     * @var FcmTokenService
     */
    protected $fcmTokenService;

    /**
     * Create a new controller instance.
     *
     * @param FcmTokenService $fcmTokenService
     * @return void
     */
    public function __construct(FcmTokenService $fcmTokenService)
    {
        $this->fcmTokenService = $fcmTokenService;
        $this->middleware('auth:sanctum');
    }

    /**
     * Add or update FCM token for the authenticated user
     * 
     * @param FcmTokenRequest $request
     * @return JsonResponse
     */
    public function update(FcmTokenRequest $request): JsonResponse
    {
        $user = Auth::user();
        $token = $request->input('token');
        
        $result = $this->fcmTokenService->addToken($user, $token);
        
        if ($result['status']) {
            return $this->successResponse(
                $result['message'],
                $result['data'] ?? []
            );
        }
        
        return $this->onErrorResponse([
            'code' => $result['code'] ?? 500,
            'message' => $result['message']
        ]);
    }
    
    /**
     * Remove FCM token for the authenticated user
     * 
     * @param FcmTokenRequest $request
     * @return JsonResponse
     */
    public function destroy(FcmTokenRequest $request): JsonResponse
    {
        $user = Auth::user();
        $token = $request->input('token');
        
        $result = $this->fcmTokenService->removeToken($user, $token);
        
        if ($result['status']) {
            return $this->successResponse(
                $result['message'],
                $result['data'] ?? []
            );
        }
        
        return $this->onErrorResponse([
            'code' => $result['code'] ?? 500,
            'message' => $result['message']
        ], $result['code'] ?? 500);
    }
    
    /**
     * Clear all FCM tokens for the authenticated user
     * 
     * @return JsonResponse
     */
    public function clear(): JsonResponse
    {
        $user = Auth::user();
        
        $result = $this->fcmTokenService->clearTokens($user);
        
        if ($result['status']) {
            return $this->successResponse(
                $result['message'],
                $result['data'] ?? []
            );
        }
        
        return $this->onErrorResponse([
            'code' => $result['code'] ?? 500,
            'message' => $result['message']
        ]);
    }
}
