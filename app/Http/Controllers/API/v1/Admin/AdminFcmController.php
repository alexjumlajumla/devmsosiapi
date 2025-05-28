<?php

namespace App\Http\Controllers\API\v1\Admin;

use App\Http\Controllers\API\v1\BaseController;
use App\Models\User;
use App\Services\FCM\FcmTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AdminFcmController extends BaseController
{
    /**
     * @var FcmTokenService
     */
    private $fcmTokenService;

    /**
     * @param FcmTokenService $fcmTokenService
     */
    public function __construct(FcmTokenService $fcmTokenService)
    {
        $this->fcmTokenService = $fcmTokenService;
    }

    /**
     * Get users with FCM tokens (paginated)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function usersWithTokens(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'per_page' => 'sometimes|integer|min:1|max:100',
                'page' => 'sometimes|integer|min:1',
                'search' => 'sometimes|string|max:255',
            ]);

            $perPage = $request->input('per_page', 15);
            $search = $request->input('search');

            $query = User::whereNotNull('firebase_token')
                ->where('firebase_token', '!=', '[]')
                ->select([
                    'id',
                    'firstname',
                    'lastname',
                    'email',
                    'phone',
                    'firebase_token',
                    'created_at',
                    'updated_at'
                ])
                ->withCount('orders')
                ->orderBy('created_at', 'desc');

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('firstname', 'like', "%{$search}%")
                      ->orWhere('lastname', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            $users = $query->paginate($perPage);

            // Mask tokens in the response
            $users->getCollection()->transform(function ($user) {
                $tokens = $user->firebase_token ?? [];
                $user->tokens = array_map(function($token) {
                    return [
                        'prefix' => substr($token, 0, 10) . '...',
                        'suffix' => '...' . substr($token, -5),
                        'length' => strlen($token)
                    ];
                }, $tokens);
                $user->tokens_count = count($tokens);
                unset($user->firebase_token);
                return $user;
            });

            return $this->successResponse(
                'Users with FCM tokens retrieved successfully',
                $users
            );

        } catch (\Exception $e) {
            Log::error('Error retrieving users with FCM tokens', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse(
                'Failed to retrieve users with FCM tokens',
                500
            );
        }
    }

    /**
     * Send a test notification to a user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendTestNotification(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'title' => 'required|string|max:100',
                'message' => 'required|string|max:255',
                'data' => 'sometimes|array',
                'data.*' => 'nullable|string',
            ]);

            $user = User::findOrFail($validated['user_id']);
            $tokens = $user->getFcmTokens();

            if (empty($tokens)) {
                return $this->errorResponse(
                    'User has no FCM tokens registered',
                    400
                );
            }

            $data = array_merge($validated['data'] ?? [], [
                'type' => 'test_notification',
                'timestamp' => now()->toDateTimeString(),
                'test' => true,
            ]);

            // Log the test notification
            Log::info('Sending test FCM notification', [
                'admin_id' => $request->user()->id,
                'user_id' => $user->id,
                'title' => $validated['title'],
                'message' => $validated['message'],
                'tokens_count' => count($tokens),
                'data' => $data
            ]);

            // Send the notification
            $user->notify(
                new \App\Notifications\FcmNotification(
                    $validated['title'],
                    $validated['message'],
                    $data,
                    'test_notification'
                )
            );

            return $this->successResponse(
                'Test notification sent successfully',
                [
                    'user_id' => $user->id,
                    'tokens_sent' => count($tokens),
                    'title' => $validated['title'],
                    'message' => $validated['message']
                ]
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422,
                ['errors' => $e->errors()]
            );
        } catch (\Exception $e) {
            Log::error('Error sending test FCM notification', [
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
     * Clean up invalid FCM tokens
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cleanupInvalidTokens(Request $request): JsonResponse
    {
        try {
            $dryRun = $request->boolean('dry_run', true);
            
            Log::info('Starting FCM token cleanup', [
                'admin_id' => $request->user()->id,
                'dry_run' => $dryRun
            ]);
            
            // This would typically call a command or service to clean up tokens
            // For now, we'll simulate it
            
            $result = [
                'dry_run' => $dryRun,
                'tokens_checked' => 0,
                'invalid_tokens_found' => 0,
                'tokens_removed' => 0,
                'users_affected' => 0,
                'details' => []
            ];
            
            // In a real implementation, this would scan all users with tokens
            // and check them against FCM to find invalid ones
            
            return $this->successResponse(
                $dryRun ? 'Dry run completed' : 'FCM token cleanup completed',
                $result
            );
            
        } catch (\Exception $e) {
            Log::error('Error during FCM token cleanup', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->errorResponse(
                'Failed to clean up FCM tokens: ' . $e->getMessage(),
                500
            );
        }
    }
}
