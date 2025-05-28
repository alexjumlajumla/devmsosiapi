<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Traits\Notification as NotificationTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestFcmNotification extends Command
{
    use NotificationTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:test-notification 
                            {--user= : User ID to send the notification to}
                            {--token= : Direct FCM token to send the notification to}
                            {--title=Test Notification : Notification title}
                            {--message=This is a test notification : Notification message}
                            {--data=* : Additional data as key=value pairs}'
    ;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a test FCM notification to a user or token';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $userId = $this->option('user');
        $token = $this->option('token');
        $title = $this->option('title');
        $message = $this->option('message');
        $dataInput = $this->option('data');

        // Parse additional data
        $data = [];
        foreach ($dataInput as $item) {
            if (strpos($item, '=') !== false) {
                list($key, $value) = explode('=', $item, 2);
                $data[$key] = $value;
            } else {
                $data[$item] = true;
            }
        }

        // Add timestamp
        $data['timestamp'] = now()->toDateTimeString();

        // Get tokens based on input
        $tokens = [];
        $user = null;
        $userId = null;

        if ($token) {
            $tokens = [$token];
            $this->info("Sending test notification to provided token: " . substr($token, 0, 10) . '...');
        } elseif ($userId) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("User with ID {$userId} not found.");
                return 1;
            }
            $tokens = $user->getFcmTokens();
            if (empty($tokens)) {
                $this->error("User {$user->email} has no FCM tokens.");
                return 1;
            }
            $this->info("Sending test notification to user: {$user->email} (ID: {$user->id})");
            $this->info("Found " . count($tokens) . " FCM token(s) for this user.");
            $userId = $user->id;
        } else {
            $this->error("You must specify either --user or --token option.");
            return 1;
        }

        // Log the test notification
        Log::info('Sending test FCM notification', [
            'user_id' => $userId,
            'tokens_count' => count($tokens),
            'title' => $title,
            'message' => $message,
            'data' => $data
        ]);

        // Send the notification
        $this->info("\nSending notification...");
        $this->info("Title: {$title}");
        $this->info("Message: {$message}");
        $this->info("Data: " . json_encode($data, JSON_PRETTY_PRINT));
        $this->info("\nRecipients: " . count($tokens) . " token(s)");
        
        if ($this->confirm('Do you wish to continue?', true)) {
            $this->sendNotification(
                $tokens,
                $message,
                $title,
                $data,
                $userId ? [$userId] : [],
                $title
            );
            
            $this->info("\nNotification sent successfully!");
            
            if ($user) {
                $this->info("\nUser's current FCM tokens:");
                $this->table(
                    ['Token (truncated)', 'Length'],
                    array_map(function($token) {
                        return [
                            substr($token, 0, 20) . '...' . substr($token, -10),
                            strlen($token) . ' chars'
                        ];
                    }, $tokens)
                );
            }
        } else {
            $this->info("Notification cancelled.");
        }

        return 0;
    }
}
