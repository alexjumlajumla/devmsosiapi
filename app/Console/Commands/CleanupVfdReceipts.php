<?php

namespace App\Console\Commands;

use App\Models\VfdReceipt;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupVfdReceipts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vfd:cleanup 
                            {--days=90 : Delete receipts older than this many days} 
                            {--dry-run : List receipts that would be deleted without actually deleting them} 
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old VFD receipts from the database';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        
        $cutoffDate = now()->subDays($days);
        
        $query = VfdReceipt::where('created_at', '<', $cutoffDate);
        
        $count = $query->count();
        
        if ($count === 0) {
            $this->info("No receipts found older than {$days} days.");
            return 0;
        }
        
        $this->warn("Found {$count} receipts older than {$days} days (before {$cutoffDate->format('Y-m-d')}).");
        
        if ($dryRun) {
            $this->info("\nThis is a dry run. No records will be deleted.");
            $this->info("The following receipts would be deleted:");
            
            $receipts = $query->limit(10)->get();
            
            $this->table(
                ['ID', 'Receipt Number', 'Type', 'Amount', 'Status', 'Created At'],
                $receipts->map(function ($receipt) {
                    return [
                        $receipt->id,
                        $receipt->receipt_number,
                        $receipt->receipt_type,
                        number_format($receipt->amount / 100, 2) . ' TZS',
                        $receipt->status,
                        $receipt->created_at->format('Y-m-d H:i:s'),
                    ];
                })
            );
            
            if ($count > 10) {
                $this->info("... and " . ($count - 10) . " more receipts.");
            }
            
            return 0;
        }
        
        if (!$force && !$this->confirm("Are you sure you want to delete {$count} receipts? This action cannot be undone.")) {
            $this->info('Cleanup cancelled.');
            return 0;
        }
        
        $this->line("Deleting {$count} receipts...");
        
        // Use chunking to avoid memory issues with large deletes
        $deleted = 0;
        $query->chunk(1000, function ($receipts) use (&$deleted) {
            $count = $receipts->count();
            $deleted += $count;
            
            // Log the deletion
            Log::info("Deleting {$count} VFD receipts", [
                'oldest' => $receipts->min('created_at'),
                'newest' => $receipts->max('created_at'),
                'count' => $count,
            ]);
            
            // Delete the chunk
            VfdReceipt::whereIn('id', $receipts->pluck('id'))->delete();
            
            $this->line("Deleted {$deleted} receipts so far...");
        });
        
        $this->info("\nSuccessfully deleted {$deleted} receipts older than {$days} days.");
        
        return 0;
    }
}
