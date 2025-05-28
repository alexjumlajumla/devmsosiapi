<?php

namespace App\Jobs;

use App\Models\PushNotification;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RetryFailedNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $maxExceptions = 3;
    public $timeout = 60;
    public $failOnTimeout = true;

    protected $maxRetryHours = 24; // Don't retry notifications older than 24 hours
    protected $maxRetryAttempts = 3; // Maximum number of retry attempts

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        $cutoffTime = now()->subHours($this->maxRetryHours);
        
        // Get failed notifications that are still within the retry window
        $failedNotifications = PushNotification::query()
            ->where('status', PushNotification::STATUS_FAILED)
            ->where('created_at', '>=', $cutoffTime)
            ->where(function($query) {
                $query->whereNull('retry_attempts')
                      ->orWhere('retry_attempts', '<', $this->maxRetryAttempts);
            })
            ->with('user')
            ->get();

        if ($failedNotifications->isEmpty()) {
            Log::info('No failed notifications to retry.');
            return;
        }

        Log::info("Retrying {$failedNotifications->count()} failed notifications.");

        foreach ($failedNotifications as $notification) {
            try {
                if (!$notification->user) {
                    Log::warning("No user found for notification #{$notification->id}");
                    continue;
                }

                // Update retry attempt count
                $retryAttempts = ($notification->retry_attempts ?? 0) + 1;
                
                $notification->update([
                    'retry_attempts' => $retryAttempts,
                    'last_retry_at' => now(),
                ]);

                // Resend the notification
                $result = $notificationService->sendToUser(
                    user: $notification->user,
                    title: $notification->title,
                    message: $notification->body,
                    type: $notification->type,
                    data: $notification->data ?: [],
                    saveToDatabase: false // Don't create a new notification
                );

                if ($result) {
                    Log::info("Successfully resent notification #{$notification->id} (attempt {$retryAttempts})");
                } else {
                    Log::warning("Failed to resend notification #{$notification->id} (attempt {$retryAttempts})");
                }

            } catch (\Throwable $e) {
                Log::error("Error retrying notification #{$notification->id}: " . $e->getMessage(), [
                    'exception' => $e,
                    'notification_id' => $notification->id,
                ]);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('RetryFailedNotifications job failed: ' . $exception->getMessage(), [
            'exception' => $exception,
        ]);
    }
}
