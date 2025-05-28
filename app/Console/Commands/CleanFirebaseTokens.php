<?php

namespace App\Console\Commands;

use App\Traits\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Google\Client;

class CleanFirebaseTokens extends Command
{
    use Notification;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'firebase:clean-tokens {--test : Run a test notification} {--token= : Firebase token to test with}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up invalid and duplicate Firebase tokens and test functionality';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('test')) {
            return $this->testNotification();
        }
        
        $this->info('Starting Firebase token cleanup...');
        
        try {
            // Step 1: Clean invalid format tokens
            $this->info('Cleaning tokens with invalid format...');
            $invalidFormatCount = \App\Models\User::where('firebase_token', '!=', null)
                ->where(function($query) {
                    $query->whereRaw("firebase_token NOT REGEXP '^[A-Za-z0-9_-]+:APA91[A-Za-z0-9_-]{100,}$'")
                        ->orWhere('firebase_token', '')
                        ->orWhere('firebase_token', '[]')
                        ->orWhere('firebase_token', 'null')
                        ->orWhereRaw('LENGTH(firebase_token) < 100')
                        ->orWhereRaw('LENGTH(firebase_token) > 500')
                        ->orWhere('firebase_token', 'LIKE', '% %') // Contains spaces
                        ->orWhere('firebase_token', 'LIKE', '%\n%') // Contains newlines
                        ->orWhere('firebase_token', 'LIKE', '%\r%'); // Contains carriage returns
                })
                ->update(['firebase_token' => null]);
            
            $this->info("Cleaned $invalidFormatCount tokens with invalid format");

            // Step 2: Clean duplicate tokens (keep only the most recent)
            $this->info('Cleaning duplicate tokens...');
            $duplicates = \App\Models\User::selectRaw('firebase_token, COUNT(*) as count')
                ->whereNotNull('firebase_token')
                ->where('firebase_token', '!=', '')
                ->groupBy('firebase_token')
                ->having('count', '>', 1)
                ->get();

            $duplicatesCleaned = 0;
            foreach ($duplicates as $duplicate) {
                // Keep the most recently updated user's token
                $users = \App\Models\User::where('firebase_token', $duplicate->firebase_token)
                    ->orderBy('updated_at', 'desc')
                    ->get();
                
                // Skip the first (most recent) user
                foreach ($users->skip(1) as $user) {
                    $user->update(['firebase_token' => null]);
                    $duplicatesCleaned++;
                }
            }
            $this->info("Cleaned $duplicatesCleaned duplicate tokens");

            // Step 3: Test remaining tokens in batches
            $this->info('Testing remaining tokens...');
            $users = \App\Models\User::whereNotNull('firebase_token')
                ->where('firebase_token', '!=', '')
                ->select(['id', 'firebase_token'])
                ->get();

            $invalidTokens = 0;
            $processedTokens = 0;
            $totalTokens = $users->count();

            foreach ($users->chunk(50) as $chunk) {
                foreach ($chunk as $user) {
                    try {
                        $processedTokens++;
                        if ($processedTokens % 10 === 0) {
                            $this->info("Progress: $processedTokens/$totalTokens tokens processed");
                        }

                        $response = $this->testTokenValidity($user->firebase_token);
                        if (!$response->successful()) {
                            $error = $response->json()['error']['message'] ?? null;
                            if ($error && (
                                str_contains($error, 'invalid') ||
                                str_contains($error, 'not a valid FCM registration token') ||
                                str_contains($error, 'INVALID_ARGUMENT')
                            )) {
                                $user->update(['firebase_token' => null]);
                                $invalidTokens++;
                                
                                \Log::warning('[FirebaseTokenCleanup] Invalid token removed', [
                                    'user_id' => $user->id,
                                    'token_prefix' => substr($user->firebase_token, 0, 15) . '...',
                                    'error' => $error
                                ]);
                            }
                        }

                        // Add a small delay to avoid rate limiting
                        usleep(100000); // 100ms delay
                    } catch (\Exception $e) {
                        \Log::error('[FirebaseTokenCleanup] Error testing token: ' . $e->getMessage(), [
                            'user_id' => $user->id,
                            'token_prefix' => substr($user->firebase_token, 0, 15) . '...',
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }
            }
            $this->info("Cleaned $invalidTokens invalid tokens through testing");
            $this->info("Total tokens processed: $processedTokens");

            $this->info('Firebase token cleanup completed successfully.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Error cleaning Firebase tokens: ' . $e->getMessage());
            \Log::error('[FirebaseTokenCleanup] Fatal error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
    
    /**
     * Test Firebase notification
     */
    protected function testNotification()
    {
        $this->info('Testing Firebase notification system...');
        
        try {
            // Step 1: Check the service account file
            $serviceAccountPath = storage_path('app/google-service-account.json');
            if (!file_exists($serviceAccountPath)) {
                $this->error("Service account file not found: $serviceAccountPath");
                return 1;
            }
            $this->info("✓ Service account file exists");
            
            // Step 2: Try to read the file
            $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("Failed to parse service account file: " . json_last_error_msg());
                return 1;
            }
            $this->info("✓ Service account file is valid JSON");
            
            // Step 3: Check project ID
            $projectId = $serviceAccount['project_id'] ?? null;
            if (empty($projectId)) {
                $this->info("No project_id in service account, checking settings table...");
                $projectId = $this->projectId();
                if (empty($projectId)) {
                    $this->error("No project_id found in service account or settings");
                    return 1;
                }
            }
            $this->info("✓ Project ID found: $projectId");
            
            // Step 4: Try to get a token
            try {
                $googleClient = new Client;
                $googleClient->setAuthConfig($serviceAccountPath);
                $googleClient->addScope('https://www.googleapis.com/auth/firebase.messaging');
                
                $this->info("Fetching Firebase auth token...");
                $authToken = $googleClient->fetchAccessTokenWithAssertion()['access_token'] ?? null;
                
                if (empty($authToken)) {
                    $this->error("Failed to get Firebase auth token - empty response");
                    return 1;
                }
                $this->info("✓ Successfully got Firebase auth token");
                
                // Step 5: Send a test notification
                $token = $this->option('token');
                if (empty($token)) {
                    // If no token provided via option, try to grab one from the database
                    try {
                        $user = \App\Models\User::whereNotNull('firebase_token')
                            ->where('firebase_token', '!=', '')
                            ->where('firebase_token', '!=', '[]')
                            ->where('firebase_token', '!=', 'null')
                            ->first();
                        
                        if ($user) {
                            $token = $user->firebase_token;
                            $this->info("Using token from user #{$user->id}: " . substr($token, 0, 20) . "...");
                        }
                    } catch (\Exception $e) {
                        $this->warn("Error finding token in database: " . $e->getMessage());
                    }
                    
                    if (empty($token)) {
                        $this->error("No token provided. Please provide a token with --token option");
                        return 1;
                    }
                }
                
                // Check token format
                if (is_array($token)) {
                    $token = $token[0] ?? null;
                    if (empty($token)) {
                        $this->error("Token is an empty array");
                        return 1;
                    }
                    $this->info("Token was an array, using first element");
                }
                
                $this->info("Sending test notification to token: " . substr($token, 0, 20) . "...");
                
                // Format data values as strings for FCM
                $data = [
                    'type' => 'test',
                    'timestamp' => (string)time(),
                    'message' => 'Test message content'
                ];
                
                $notification = [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => 'Test Notification',
                            'body' => 'This is a test notification from your app',
                        ],
                        'data' => $data,
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'sound' => 'default',
                                'channel_id' => 'high_importance_channel'
                            ]
                        ],
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10'
                            ],
                            'payload' => [
                                'aps' => [
                                    'sound' => 'default',
                                    'badge' => 1,
                                    'content-available' => 1
                                ]
                            ]
                        ]
                    ]
                ];
                
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $authToken,
                    'Content-Type' => 'application/json',
                ])->post("https://fcm.googleapis.com/v1/projects/$projectId/messages:send", $notification);
                
                if ($response->successful()) {
                    $this->info("✓ Notification sent successfully!");
                    $this->info("Response: " . $response->body());
                    return 0;
                } else {
                    $this->error("Failed to send notification");
                    $this->error("Status: " . $response->status());
                    $this->error("Response: " . $response->body());
                    return 1;
                }
                
            } catch (\Exception $e) {
                $this->error("Error with Firebase auth: " . $e->getMessage());
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error("Error testing Firebase: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * Test if a token is valid with Firebase
     */
    private function testTokenValidity(string $token)
    {
        // Validate token format before testing
        if (!preg_match('/^[A-Za-z0-9_-]+:APA91[A-Za-z0-9_-]{100,}$/', $token)) {
            throw new \InvalidArgumentException('Invalid token format');
        }

        // Get Firebase auth token
        $googleClient = new \Google\Client;
        $googleClient->setAuthConfig(storage_path('app/google-service-account.json'));
        $googleClient->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $authToken = $googleClient->fetchAccessTokenWithAssertion()['access_token'];

        $projectId = $this->projectId();

        // Send test notification
        return \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . $authToken,
            'Content-Type' => 'application/json',
        ])->post("https://fcm.googleapis.com/v1/projects/$projectId/messages:send", [
            'message' => [
                'token' => $token,
                'data' => [
                    'type' => 'test',
                    'timestamp' => (string)time()
                ]
            ]
        ]);
    }
} 