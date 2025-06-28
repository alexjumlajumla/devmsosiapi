<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupFcmTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:cleanup-tokens {--dry-run : Run without making any changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired or invalid FCM tokens for all users';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $totalRemoved = 0;
        $totalUsers = 0;
        $affectedUsers = 0;
        
        $this->info('Starting FCM token cleanup' . ($dryRun ? ' (dry run)' : ''));
        
        // Process users in chunks to avoid memory issues
        User::chunk(200, function ($users) use (&$totalRemoved, &$totalUsers, &$affectedUsers, $dryRun) {
            foreach ($users as $user) {
                $totalUsers++;
                
                // Skip users with no tokens
                if (empty($user->firebase_token)) {
                    continue;
                }
                
                $originalCount = is_countable($user->firebase_token) ? count($user->firebase_token) : 0;
                
                if (!$dryRun) {
                    // Clean up tokens for this user
                    $removed = $user->cleanupFcmTokens();
                } else {
                    // Just count what would be removed in dry run mode
                    $tokens = $user->firebase_token ?? [];
                    $validTokens = array_filter($tokens, function($token) {
                        return is_string($token) && 
                               preg_match('/^[a-zA-Z0-9_\-:]+$/', $token) && 
                               strlen($token) >= 100 && 
                               strlen($token) <= 500;
                    });
                    $removed = count($tokens) - count($validTokens);
                }
                
                if ($removed > 0) {
                    $affectedUsers++;
                    $totalRemoved += $removed;
                    
                    $this->line(sprintf(
                        'User ID %d: Removed %d token(s) (had %d, now %d)',
                        $user->id,
                        $removed,
                        $originalCount,
                        $dryRun ? $originalCount - $removed : (is_countable($user->firebase_token) ? count($user->firebase_token) : 0)
                    ));
                }
                
                // Free up memory
                unset($user);
            }
        });
        
        $this->newLine();
        $this->info('FCM token cleanup completed');
        $this->line(sprintf('Processed %d users', $totalUsers));
        $this->line(sprintf('Affected %d users', $affectedUsers));
        $this->line(sprintf('Removed %d invalid tokens in total', $totalRemoved));
        
        if ($dryRun) {
            $this->warn('This was a dry run. No changes were made to the database.');
            $this->info('To actually remove the tokens, run the command without the --dry-run flag');
        }
        
        Log::info('FCM token cleanup completed', [
            'total_users' => $totalUsers,
            'affected_users' => $affectedUsers,
            'tokens_removed' => $totalRemoved,
            'dry_run' => $dryRun
        ]);
        
        return 0;
    }
}
