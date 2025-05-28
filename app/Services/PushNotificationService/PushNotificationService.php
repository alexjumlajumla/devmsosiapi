<?php

namespace App\Services\PushNotificationService;

ini_set('memory_limit', '4G');
set_time_limit(0);

use App\Models\Booking\Table;
use App\Models\PushNotification;
use App\Services\CoreService;
use App\Traits\Notification;
use DB;
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

        Log::info('PushNotificationService: Storing notifications for users', [
            'user_count' => count($userIds),
            'notification_type' => $data['type'] ?? 'unknown',
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
        ]);

        $chunks = array_chunk($userIds, 2); // Process in chunks of 2 to avoid memory issues
        $successCount = 0;
        $errorCount = 0;

        foreach ($chunks as $chunkIndex => $chunk) {
            foreach ($chunk as $userId) {
                try {
                    $notificationData = $data; // Create a copy to avoid modifying the original
                    $notificationData['data'] = is_array(data_get($data, 'data')) 
                        ? $data['data'] 
                        : [data_get($data, 'data')];
                    
                    $notificationData['user_id'] = $userId;

                    Log::debug('PushNotificationService: Creating notification', [
                        'user_id' => $userId,
                        'type' => $notificationData['type'] ?? 'unknown',
                        'title' => $notificationData['title'] ?? null,
                    ]);

                    $this->model()->create($notificationData);
                    $successCount++;

                } catch (\Throwable $e) {
                    $errorCount++;
                    Log::error('PushNotificationService: Failed to create notification', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    // Continue with other users even if one fails
                    continue;
                }
            }
        }


        if ($errorCount > 0) {
            Log::error('PushNotificationService: Completed with errors', [
                'total_users' => count($userIds),
                'successful' => $successCount,
                'failed' => $errorCount
            ]);
        } else {
            Log::info('PushNotificationService: Successfully stored all notifications', [
                'total_users' => $successCount
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
