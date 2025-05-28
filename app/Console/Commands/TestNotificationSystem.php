<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\PushNotification;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestNotificationSystem extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the notification system';

    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService)
    {
        $this->info('Starting notification system test...');
        
        // Get a user with a Firebase token
        $user = User::whereNotNull('firebase_token')
            ->where('firebase_token', '!=', '[]')
            ->first();
            
        if (!$user) {
            $this->error('No users with Firebase tokens found.');
            return 1;
        }
        
        $this->info("Testing notification for user: {$user->email} (ID: {$user->id})");
        
        // Test sending a notification
        $this->info('\nSending test notification...');
        try {
            $notification = $notificationService->sendToUser(
                user: $user,
                title: 'Test Notification',
                message: 'This is a test notification from the system.',
                type: 'test',
                data: [
                    'test_key' => 'test_value',
                    'timestamp' => now()->toDateTimeString(),
                ]
            );
            
            if ($notification) {
                $this->info("✅ Notification sent successfully!");
                $this->info("   ID: {$notification->id}");
                $this->info("   Status: {$notification->status}");
                $this->info("   Created at: {$notification->created_at}");
            } else {
                $this->error('❌ Failed to send notification');
            }
        } catch (\Throwable $e) {
            $this->error("❌ Error sending notification: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
        
        // Test getting unread count
        $unreadCount = $notificationService->getUnreadCount($user->id);
        $this->info("\nUnread notifications: {$unreadCount}");
        
        // List recent push notifications with better formatting
        $this->info("\nRecent push notifications (newest first):");
        $notifications = PushNotification::where('user_id', $user->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($notification) {
                return [
                    'ID' => $notification->id,
                    'Type' => $notification->type ?: 'N/A',
                    'Title' => $notification->title ?: 'N/A',
                    'Status' => $notification->status ?: 'N/A',
                    'Created' => $notification->created_at->diffForHumans(),
                    'Data' => json_encode($notification->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ];
            });
            
        if ($notifications->isNotEmpty()) {
            $this->table(
                array_keys($notifications->first()),
                $notifications->toArray()
            );
        } else {
            $this->info('No push notifications found for this user.');
        }
        
        // Show notification stats
        $stats = [
            'Total' => PushNotification::where('user_id', $user->id)->count(),
            'Unread' => PushNotification::where('user_id', $user->id)
                ->where('status', '!=', PushNotification::STATUS_READ)
                ->count(),
            'Sent' => PushNotification::where('user_id', $user->id)
                ->where('status', PushNotification::STATUS_SENT)
                ->count(),
            'Delivered' => PushNotification::where('user_id', $user->id)
                ->where('status', PushNotification::STATUS_DELIVERED)
                ->count(),
            'Read' => PushNotification::where('user_id', $user->id)
                ->where('status', PushNotification::STATUS_READ)
                ->count(),
            'Failed' => PushNotification::where('user_id', $user->id)
                ->where('status', PushNotification::STATUS_FAILED)
                ->count(),
        ];
        
        $this->info("\nPush Notification Statistics:");
        $this->table(
            ['Metric', 'Count'],
            collect($stats)->map(function ($value, $key) {
                return ['Metric' => $key, 'Count' => $value];
            })
        );
        
        $this->info('\n✅ Notification system test completed!');
        return 0;
    }
}
