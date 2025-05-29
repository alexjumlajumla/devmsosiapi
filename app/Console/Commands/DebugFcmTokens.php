<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DebugFcmTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:debug 
                            {user : User ID or email to debug}
                            {--fix : Try to fix any token issues}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debug FCM token issues for a specific user';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $userInput = $this->argument('user');
        $fix = $this->option('fix');
        
        // Find the user by ID or email
        $user = is_numeric($userInput) 
            ? User::find($userInput)
            : User::where('email', $userInput)->first();
            
        if (!$user) {
            $this->error("User not found: {$userInput}");
            return 1;
        }
        
        $this->info("Debugging FCM tokens for user: {$user->email} (ID: {$user->id})");
        $this->line(str_repeat('-', 80));
        
        // Get raw token data
        $rawTokenData = $user->firebase_token;
        $tokenType = gettype($rawTokenData);
        
        $this->info("Raw Token Data:");
        $this->line("Type: " . $tokenType);
        
        if ($tokenType === 'string') {
            $this->line("Length: " . strlen($rawTokenData));
            $this->line("Is JSON: " . (json_decode($rawTokenData) !== null ? 'Yes' : 'No'));
            $this->line("Sample: " . (strlen($rawTokenData) > 50 ? substr($rawTokenData, 0, 50) . '...' : $rawTokenData));
        } elseif ($tokenType === 'array') {
            $this->line("Count: " . count($rawTokenData));
            $this->line("First few items:");
            foreach (array_slice($rawTokenData, 0, 5) as $i => $token) {
                $this->line("  [{$i}]: " . (is_string($token) ? 
                    (strlen($token) > 50 ? substr($token, 0, 50) . '...' : $token) : 
                    gettype($token)));
            }
        } elseif ($tokenType === 'NULL') {
            $this->line("No token data (NULL)");
        } else {
            $this->line("Unhandled token type: " . $tokenType);
        }
        
        $this->line(str_repeat('-', 80));
        
        // Get tokens using the getFcmTokens method
        $this->info("Processed Tokens (via getFcmTokens()):");
        $tokens = $user->getFcmTokens();
        
        if (empty($tokens)) {
            $this->warn("No valid FCM tokens found for this user.");
        } else {
            $this->line("Found " . count($tokens) . " valid tokens:");
            foreach ($tokens as $i => $token) {
                $this->line(sprintf("  [%d] %s (length: %d)", 
                    $i, 
                    substr($token, 0, 20) . '...', 
                    strlen($token)
                ));
            }
        }
        
        $this->line(str_repeat('-', 80));
        
        // Check if the tokens array is valid JSON
        if ($tokenType === 'string' && $fix) {
            $this->info("Attempting to fix JSON-encoded tokens...");
            
            $decoded = json_decode($rawTokenData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $user->firebase_token = $decoded;
                $user->save();
                $this->info("✓ Fixed JSON-encoded tokens.");
                $this->line("New token type: " . gettype($user->firebase_token));
            } else {
                $this->warn("Could not decode JSON: " . json_last_error_msg());
            }
        }
        
        // Check for empty arrays or invalid data
        if (empty($tokens) && $fix) {
            $this->info("No valid tokens found. Attempting to fix...");
            
            // If we have a string that looks like a token, try to use it
            if ($tokenType === 'string' && strlen($rawTokenData) > 50) {
                $user->firebase_token = [$rawTokenData];
                $user->save();
                $this->info("✓ Converted single token string to array.");
            }
        }
        
        // Log the debug information
        Log::info('FCM Token Debug', [
            'user_id' => $user->id,
            'email' => $user->email,
            'raw_token_type' => $tokenType,
            'token_count' => is_array($tokens) ? count($tokens) : 0,
            'is_json' => $tokenType === 'string' && json_decode($rawTokenData) !== null,
            'is_empty' => empty($tokens),
            'tokens_sample' => is_array($tokens) ? array_slice($tokens, 0, 3) : []
        ]);
        
        $this->line(str_repeat('-', 80));
        $this->info("Debug information has been logged to the Laravel log file.");
        
        return 0;
    }
}
