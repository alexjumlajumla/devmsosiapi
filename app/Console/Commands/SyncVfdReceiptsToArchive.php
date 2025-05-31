<?php

namespace App\Console\Commands;

use App\Models\VfdReceipt;
use App\Services\Order\VfdReceiptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVfdReceiptsToArchive extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vfd:sync-archive 
                            {--days=7 : Sync receipts from the last N days} 
                            {--limit=100 : Maximum number of receipts to sync in one run} 
                            {--retry-failed : Include previously failed sync attempts} 
                            {--dry-run : List receipts that would be synced without actually syncing them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync VFD receipts to an external archive service';

    /**
     * The VFD receipt service instance.
     *
     * @var VfdReceiptService
     */
    protected $receiptService;

    /**
     * Create a new command instance.
     *
     * @param VfdReceiptService $receiptService
     * @return void
     */
    public function __construct(VfdReceiptService $receiptService)
    {
        parent::__construct();
        $this->receiptService = $receiptService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!config('services.vfd.archive_enabled')) {
            $this->error('Archive service is not enabled. Please set VFD_ARCHIVE_ENABLED=true in your .env file.');
            return 1;
        }
        
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $retryFailed = $this->option('retry-failed');
        $dryRun = $this->option('dry-run');
        
        $query = VfdReceipt::where('created_at', '>=', now()->subDays($days))
            ->where('status', 'generated')
            ->where(function($q) use ($retryFailed) {
                $q->whereNull('synced_to_archive_at');
                
                if ($retryFailed) {
                    $q->orWhere('sync_error', '!=', null);
                }
            })
            ->orderBy('created_at', 'asc')
            ->limit($limit);
        
        $receipts = $query->get();
        
        if ($receipts->isEmpty()) {
            $this->info('No receipts found to sync.');
            return 0;
        }
        
        $this->info("Found {$receipts->count()} receipts to sync to archive.");
        
        if ($dryRun) {
            $this->info("\nThis is a dry run. No records will be synced.");
            $this->info("The following receipts would be synced:");
            
            $this->table(
                ['ID', 'Receipt Number', 'Type', 'Amount', 'Created At', 'Last Sync Attempt'],
                $receipts->map(function ($receipt) {
                    return [
                        $receipt->id,
                        $receipt->receipt_number,
                        $receipt->receipt_type,
                        number_format($receipt->amount / 100, 2) . ' TZS',
                        $receipt->created_at->format('Y-m-d H:i:s'),
                        $receipt->sync_error ? 'Failed: ' . substr($receipt->sync_error, 0, 30) . '...' : 'Never',
                    ];
                })
            );
            
            return 0;
        }
        
        $bar = $this->output->createProgressBar($receipts->count());
        $bar->start();
        
        $successCount = 0;
        $failedCount = 0;
        
        foreach ($receipts as $receipt) {
            try {
                $result = $this->receiptService->syncToArchive($receipt);
                
                if ($result['success']) {
                    $successCount++;
                    $this->line("\nSynced receipt {$receipt->receipt_number} to archive");
                } else {
                    $error = $result['message'];
                    $failedCount++;
                    $this->line("\n<error>Failed to sync receipt {$receipt->receipt_number}: {$error}</error>");
                    
                    Log::error('Failed to sync receipt to archive', [
                        'receipt_id' => $receipt->id,
                        'error' => $error,
                        'exception' => $result['exception'] ?? null,
                    ]);
                }
                
            } catch (\Exception $e) {
                $error = $e->getMessage();
                
                $receipt->update([
                    'sync_error' => $error,
                ]);
                
                $failedCount++;
                $this->line("\n<error>Exception syncing receipt {$receipt->receipt_number}: {$error}</error>");
                
                Log::error('Exception syncing receipt to archive', [
                    'receipt_id' => $receipt->id,
                    'error' => $error,
                    'trace' => $e->getTraceAsString(),
                ]);
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        
        $this->newLine(2);
        $this->info("Sync completed. Success: {$successCount}, Failed: {$failedCount}");
        
        if ($failedCount > 0) {
            $this->warn("Some receipts failed to sync. Use --retry-failed to include them in the next sync.");
        }
        
        return $failedCount > 0 ? 1 : 0;
    }
    
    /**
     * Get the VFD receipt service instance.
     *
     * @return VfdReceiptService
     */
    public function getReceiptService(): VfdReceiptService
    {
        return $this->receiptService;
    }
}
