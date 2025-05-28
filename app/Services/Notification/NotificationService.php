<?php

namespace App\Services\Notification;

use App\Models\PushNotification;
use App\Models\User;
use App\Traits\Notification as NotificationTrait;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    use NotificationTrait;

    protected $firebaseTokenService;

    public function __construct(FirebaseTokenService $firebaseTokenService)
    {
        $this->firebaseTokenService = $firebaseTokenService;
    }

    /**
     * Send notification to a single user
     */
    public function sendToUser(
        User $user,
        string $title,
        string $message,
        string $type,
        array $data = [],
        bool $saveToDatabase = true
    ): ?PushNotification {
        try {
            DB::beginTransaction();

            // Clean up invalid tokens
            $this->firebaseTokenService->cleanupInvalidTokens($user);

            // Create notification record
            $notification = null;
            if ($saveToDatabase) {
                $notification = PushNotification::create([
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $message,
                    'data' => $data,
                    'status' => PushNotification::STATUS_PENDING,
                ]);
            }

            // Get valid tokens
            $tokens = $user->firebase_token ?? [];
            $tokens = is_array($tokens) ? $tokens : [$tokens];
            $tokens = array_filter($tokens);

            if (empty($tokens)) {
                Log::warning("No valid Firebase tokens for user: {$user->id}");
                return $notification;
            }

            // Send notification
            $this->sendNotification(
                $tokens,
                array_merge($data, [
                    'title' => $title,
                    'body' => $message,
                    'type' => $type,
                    'id' => $notification ? $notification->id : null,
                ]),
                [$user->id]
            );

            // Update notification status
            if ($notification) {
                $notification->update([
                    'status' => PushNotification::STATUS_SENT,
                    'sent_at' => now(),
                ]);
            }

            DB::commit();
            return $notification;

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to send notification: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'type' => $type,
                'exception' => $e
            ]);

            if (isset($notification)) {
                $notification->update([
                    'status' => PushNotification::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }


            return null;
        }
    }

    /**
     * Mark notification as delivered
     */
    public function markAsDelivered(int $notificationId, ?int $userId = null): bool
    {
        $query = PushNotification::where('id', $notificationId);
        
        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->update([
            'status' => PushNotification::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]) > 0;
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notificationId, ?int $userId = null): bool
    {
        $query = PushNotification::where('id', $notificationId);
        
        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->update([
            'status' => PushNotification::STATUS_READ,
            'read_at' => now(),
        ]) > 0;
    }

    /**
     * Get user's unread notifications count
     */
    public function getUnreadCount(int $userId): int
    {
        return PushNotification::where('user_id', $userId)
            ->where('status', '!=', PushNotification::STATUS_READ)
            ->count();
    }
}
