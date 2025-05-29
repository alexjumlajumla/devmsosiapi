<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InspectFcmToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:inspect-token {userId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect raw FCM token data for a specific user';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $userId = $this->argument('userId');
        
        // Get user with raw token data
        $user = User::select(['id', 'email', 'firebase_token'])
            ->where('id', $userId)
            ->first();
            
        if (!$user) {
            $this->error("User with ID {$userId} not found.");
            return 1;
        }
        
        $this->info("Inspecting FCM token for user: {$user->email} (ID: {$user->id})");
        $this->line(str_repeat('-', 80));
        
        // Get raw token data directly from database
        $rawData = DB::table('users')
            ->where('id', $userId)
            ->value('firebase_token');
            
        $this->info("Raw database value:");
        $this->line(json_encode($rawData, JSON_PRETTY_PRINT));
        $this->line(str_repeat('-', 80));
        
        // Get processed tokens
        $tokens = $user->getFcmTokens();
        $this->info("Processed tokens (" . count($tokens) . "):");
        
        foreach ($tokens as $i => $token) {
            $this->line("Token #" . ($i + 1) . ":");
            $this->line("  Length: " . strlen($token));
            $this->line("  Prefix: " . substr($token, 0, 10) . '...');
            $this->line("  Valid: " . ($user->isValidFcmToken($token) ? 'Yes' : 'No'));
            $this->line("  Sample: " . substr($token, 0, 50) . (strlen($token) > 50 ? '...' : ''));
            $this->line(str_repeat('-', 80));
        }
        
        // Check if the token is valid
        if (empty($tokens)) {
            $this->warn("No valid FCM tokens found for this user.");
        } else {
            $this->info("Found " . count($tokens) . " valid FCM token(s).");
        }
        
        return 0;
    }
}
