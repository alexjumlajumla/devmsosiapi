<?php

namespace App\Console\Commands;

use App\Models\User;
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
        
        // List recent notifications
        $this->info("\nRecent notifications:");
        $notifications = $user->notifications()
            ->latest()
            ->limit(5)
            ->get();
            
        $this->table(
            ['ID', 'Type', 'Title', 'Status', 'Created At'],
            $notifications->map(function ($n) {
                return [
                    $n->id,
                    $n->type,
                    $n->title,
                    $n->status,
                    $n->created_at->diffForHumans(),
                ];
            })
        );
        
        $this->info('\n✅ Notification system test completed!');
        return 0;
    }
}
