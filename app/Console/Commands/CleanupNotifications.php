<?php

namespace App\Console\Commands;

use App\Models\PushNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:cleanup 
                            {--days=30 : Delete notifications older than this many days} 
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        
        $cutoffDate = now()->subDays($days);
        
        $query = PushNotification::where('created_at', '<', $cutoffDate);
        
        $count = $query->count();
        
        if ($count === 0) {
            $this->info("No notifications older than {$days} days found.");
            return 0;
        }
        
        $this->info("Found {$count} notifications older than {$days} days.");
        
        if ($dryRun) {
            $this->info('This is a dry run. No records will be deleted.');
            return 0;
        }
        
        if ($this->confirm("Are you sure you want to delete {$count} notifications?")) {
            $deleted = 0;
            
            // Delete in chunks to avoid memory issues
            $query->chunkById(1000, function ($notifications) use (&$deleted) {
                $count = $notifications->count();
                $deleted += $count;
                
                PushNotification::whereIn('id', $notifications->pluck('id'))->delete();
                
                $this->info("Deleted {$count} notifications. Total: {$deleted}");
            });
            
            $this->info("Successfully deleted {$deleted} old notifications.");
        } else {
            $this->info('Operation cancelled.');
        }
        
        return 0;
    }
}
