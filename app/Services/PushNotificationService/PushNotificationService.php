<?php

namespace App\Services\PushNotificationService;

ini_set('memory_limit', '4G');
set_time_limit(0);

use App\Models\Booking\Table;
use App\Models\PushNotification;
use App\Services\CoreService;
use App\Traits\Notification;
use DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushNotificationService extends CoreService
{
    use Notification;

    protected function getModelClass(): string
    {
        return PushNotification::class;
    }

    /**
     * @param array $data
     * @return mixed
     */
    public function store(array $data): mixed
    {
        return $this->model()->create($data);
    }

    /**
     * @param array $data
     * @return PushNotification|null
     */
    public function restStore(array $data): ?PushNotification
    {
        try {
            $table = Table::with([
                'shopSection:id,shop_id',
                'shopSection.shop:id,user_id',
                'shopSection.shop.seller:id,firebase_token',
                'waiters' => fn($q) => $q->select('id', 'firebase_token')->where('isWork', true),
            ])
                ->find($data['table_id']);

            /** @var Table|null $table */
            $this->sendNotification(
                $table?->shopSection?->shop?->seller?->firebase_token ?? [],
                "New client in table $table->name",
                $table->id,
                ['type' => PushNotification::NEW_IN_TABLE]
            );

			foreach ($table->waiters as $waiter) {
				$this->sendNotification(
					$waiter?->firebase_token ?? [],
					"New client in table $table->name",
					$table->id,
					['type' => PushNotification::NEW_IN_TABLE]
				);
			}

            return PushNotification::create([
                'type'      => PushNotification::NEW_IN_TABLE,
                'title'     => $table->id,
                'body'      => "New client in table $table->name",
                'data'      => ['type' => PushNotification::NEW_IN_TABLE],
                'user_id'   => $table?->shopSection?->shop?->seller?->id
            ])->load(['user']);
        } catch (Throwable $e) {
            $this->error($e);
            return null;
        }
    }

    /**
     * Store multiple notifications for multiple users
     * 
     * @param array $data Notification data
     * @param array $userIds Array of user IDs to send notifications to
     * @return bool True if successful, false otherwise
     */
    public function storeMany(array $data, array $userIds): bool
    {
        if (empty($userIds)) {
            Log::warning('PushNotificationService: No user IDs provided for notification', [
                'notification_data' => $data
            ]);
            return false;
        }

        // Ensure user IDs are unique
        $userIds = array_unique($userIds);
        
        Log::info('PushNotificationService: Storing notifications for users', [
            'user_count' => count($userIds),
            'notification_type' => $data['type'] ?? 'unknown',
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'first_few_user_ids' => array_slice($userIds, 0, 5) // Log first few user IDs for debugging
        ]);

        $chunks = array_chunk($userIds, 50); // Process in chunks of 50 to balance performance and memory usage
        $successCount = 0;
        $errorCount = 0;
        $batchId = uniqid('notif_batch_', true);

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkId = $batchId . '_' . $chunkIndex;
            
            Log::debug("PushNotificationService: Processing chunk $chunkId", [
                'chunk_index' => $chunkIndex,
                'users_in_chunk' => count($chunk),
                'batch_id' => $batchId
            ]);

            $notifications = [];
            $now = now();

            foreach ($chunk as $userId) {
                try {
                    $notificationData = [
                        'type' => $data['type'] ?? 'general',
                        'title' => $data['title'] ?? null,
                        'body' => $data['body'] ?? null,
                        'data' => is_array(data_get($data, 'data')) 
                            ? json_encode($data['data']) 
                            : json_encode([data_get($data, 'data')]),
                        'user_id' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $notifications[] = $notificationData;
                    $successCount++;

                } catch (\Throwable $e) {
                    $errorCount++;
                    Log::error('PushNotificationService: Failed to prepare notification', [
                        'user_id' => $userId,
                        'chunk_id' => $chunkId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }


            // Bulk insert the notifications
            if (!empty($notifications)) {
                try {
                    DB::table('push_notifications')->insert($notifications);
                    Log::debug("PushNotificationService: Inserted chunk $chunkId successfully", [
                        'notifications_inserted' => count($notifications),
                        'chunk_id' => $chunkId
                    ]);
                } catch (\Throwable $e) {
                    $errorCount += count($notifications);
                    $successCount -= count($notifications);
                    Log::error('PushNotificationService: Failed to insert notifications chunk', [
                        'chunk_id' => $chunkId,
                        'error' => $e->getMessage(),
                        'first_notification' => $notifications[0] ?? null,
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        }

        // Final status log
        if ($errorCount > 0) {
            Log::error('PushNotificationService: Completed with errors', [
                'batch_id' => $batchId,
                'total_users' => count($userIds),
                'successful' => $successCount,
                'failed' => $errorCount,
                'success_rate' => round(($successCount / count($userIds)) * 100, 2) . '%'
            ]);
        } else {
            Log::info('PushNotificationService: Successfully stored all notifications', [
                'batch_id' => $batchId,
                'total_notifications' => $successCount,
                'processing_time' => round(microtime(true) - LARAVEL_START, 2) . 's'
            ]);
        }

        return $errorCount === 0;
    }

    /**
     * @param int $id
     * @param int $userId
     * @return PushNotification|null
     */
    public function readAt(int $id, int $userId): ?PushNotification
    {
        $model = $this->model()
            ->with('user')
            ->where('user_id', $userId)
            ->find($id);

        $model?->update([
            'read_at' => now()
        ]);

        return $model;
    }

    /**
     * @param int $userId
     * @return void
     */
    public function readAll(int $userId): void
    {
        dispatch(function () use ($userId) {
            DB::table('push_notifications')
                ->orderBy('id')
                ->where('user_id', $userId)
                ->update([
                    'read_at' => now()
                ]);
        })->afterResponse();
    }

}
