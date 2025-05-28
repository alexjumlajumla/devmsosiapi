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
                            {--data=* : Additional data as key=value pairs}
                            {--test-invalid : Test with an invalid token}
                            {--test-batch : Send to multiple tokens at once}
                            {--list-users : List users with FCM tokens}';

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
        if ($this->option('list-users')) {
            return $this->listUsersWithTokens();
        }

        $token = $this->option('token');
        $userId = $this->option('user');
        $title = $this->option('title');
        $message = $this->option('message');
        $dataInput = $this->option('data');
        $testInvalid = $this->option('test-invalid');
        $testBatch = $this->option('test-batch');

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

        // Add test identifier
        $data['test_id'] = 'test_' . now()->timestamp;
        $data['timestamp'] = now()->toDateTimeString();

        // Handle test cases
        if ($testInvalid) {
            return $this->testInvalidToken($title, $message, $data);
        }

        if ($testBatch) {
            return $this->testBatchNotifications($title, $message, $data);
        }

        // Get tokens based on input
        $tokens = [];
        $user = null;
        $userIds = [];

        if ($token) {
            $tokens = [$token];
            $this->info("Sending test notification to provided token: " . substr($token, 0, 10) . '...');
        } elseif ($userId) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("User with ID {$userId} not found.");
                return 1;
            }
            $tokens = $user->fcm_tokens ?? [];
            if (empty($tokens)) {
                $this->error("User {$user->email} has no FCM tokens.");
                return 1;
            }
            $userIds = [$user->id];
            $this->info("Sending test notification to user: {$user->email} (ID: {$user->id})");
            $this->info("Found " . count($tokens) . " FCM token(s) for this user.");
        } else {
            $this->error("You must specify either --user or --token option.");
            return 1;
        }

        // Send the notification
        $this->info("\nSending notification...");
        $this->info("Title: {$title}");
        $this->info("Message: {$message}");
        $this->info("Data: " . json_encode($data, JSON_PRETTY_PRINT));
        $this->info("Tokens: " . count($tokens));

        // Log the test notification
        Log::info('Sending test FCM notification', [
            'user_ids' => $userIds,
            'tokens_count' => count($tokens),
            'title' => $title,
            'message' => $message,
            'data' => $data
        ]);

        try {
            $response = $this->sendNotification(
                $tokens,
                $message,
                $title,
                $data,
                $userIds
            );

            $this->info("\nNotification sent successfully!");
            $this->info("Response: " . json_encode($response, JSON_PRETTY_PRINT));
            return 0;
        } catch (\Exception $e) {
            $this->error("Error sending notification: " . $e->getMessage());
            Log::error('FCM test notification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Test sending to an invalid token
     */
    protected function testInvalidToken($title, $message, $data)
    {
        $this->info("Testing with invalid FCM token...");
        
        $invalidToken = 'invalid-token-' . time();
        
        $this->info("Using token: {$invalidToken}");
        
        try {
            $response = $this->sendNotification(
                [$invalidToken],
                $message,
                $title,
                $data
            );
            
            $this->info("Response: " . json_encode($response, JSON_PRETTY_PRINT));
            
            if (isset($response['success']) && $response['success'] === false) {
                $this->info("Test passed: Properly handled invalid token");
                return 0;
            } else {
                $this->warn("Unexpected response for invalid token");
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("Error with invalid token test: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Test sending to multiple tokens at once
     */
    protected function testBatchNotifications($title, $message, $data)
    {
        $this->info("Testing batch notifications...");
        
        // Get first 3 users with tokens for testing
        $users = User::whereNotNull('firebase_token')
            ->where('firebase_token', '!=', '[]')
            ->take(3)
            ->get();
            
        if ($users->isEmpty()) {
            $this->error("No users with FCM tokens found");
            return 1;
        }
        
        $tokens = [];
        $userIds = [];
        
        foreach ($users as $user) {
            $userTokens = $user->firebase_token ?? [];
            $tokens = array_merge($tokens, $userTokens);
            $userIds[] = $user->id;
            $this->info("Including user: {$user->email} (ID: {$user->id}) with " . count($userTokens) . " token(s)");
        }
        
        if (empty($tokens)) {
            $this->error("No valid tokens found");
            return 1;
        }
        
        $this->info("\nSending to " . count($tokens) . " tokens across " . $users->count() . " users...");
        
        try {
            $response = $this->sendNotification(
                $tokens,
                $message,
                $title,
                $data,
                $userIds
            );
            
            $this->info("\nBatch notification sent!");
            $this->info("Response: " . json_encode($response, JSON_PRETTY_PRINT));
            return 0;
            
        } catch (\Exception $e) {
            $this->error("Error in batch test: " . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * List users with FCM tokens
     */
    protected function listUsersWithTokens()
    {
        $users = User::whereNotNull('firebase_token')
            ->where('firebase_token', '!=', '[]')
            ->get(['id', 'email', 'firebase_token']);
            
        if ($users->isEmpty()) {
            $this->info("No users with FCM tokens found.");
            return 0;
        }
        
        $headers = ['ID', 'Email', 'Tokens Count'];
        $rows = [];
        
        foreach ($users as $user) {
            $tokenCount = is_array($user->firebase_token) ? count($user->firebase_token) : 0;
            $rows[] = [
                $user->id,
                $user->email,
                $tokenCount
            ];
        }
        
        $this->table($headers, $rows);
        return 0;
    }
}
