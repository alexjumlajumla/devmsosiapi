<?php

namespace App\Console\Commands;

use App\Models\VfdReceipt;
use App\Services\Notification\VfdReceiptNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResendFailedVfdReceipts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vfd:resend-failed {--limit=10 : Maximum number of failed receipts to process} {--status=failed : Status to filter by (failed, generated, all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resend SMS notifications for VFD receipts';

    /**
     * The notification service instance.
     *
     * @var VfdReceiptNotificationService
     */
    protected $notificationService;

    /**
     * Create a new command instance.
     *
     * @param VfdReceiptNotificationService $notificationService
     * @return void
     */
    public function __construct(VfdReceiptNotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $limit = (int) $this->option('limit');
        $statusFilter = $this->option('status');
        
        $query = VfdReceipt::query()
            ->where(function($q) {
                $q->whereNull('receipt_url')
                  ->orWhereNull('customer_phone');
            })
            ->orWhere('status', 'failed')
            ->orderBy('created_at', 'asc')
            ->limit($limit);
            
        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }
        
        $receipts = $query->get();

        if ($receipts->isEmpty()) {
            $this->info('No receipts found matching the criteria.');
            return 0;
        }

        $this->info("Found {$receipts->count()} receipts to process.");
        
        $bar = $this->output->createProgressBar($receipts->count());
        $bar->start();

        $successCount = 0;
        $failedCount = 0;
        
        foreach ($receipts as $receipt) {
            try {
                // Skip if missing required data
                if (empty($receipt->receipt_url) || empty($receipt->customer_phone)) {
                    $this->warn("Skipping receipt {$receipt->receipt_number}: Missing URL or phone number");
                    $bar->advance();
                    continue;
                }
                
                // Send the SMS
                $result = $this->notificationService->sendReceiptSms($receipt);
                
                if ($result) {
                    $successCount++;
                    $this->info("\nSent SMS for receipt: {$receipt->receipt_number} to {$receipt->customer_phone}");
                } else {
                    $failedCount++;
                    $this->error("\nFailed to send SMS for receipt: {$receipt->receipt_number}");
                }
                
                // Update receipt status
                $receipt->update([
                    'status' => $result ? 'generated' : 'failed',
                    'error_message' => $result ? null : 'Failed to send SMS notification',
                ]);
                
            } catch (\Exception $e) {
                $failedCount++;
                
                // Log the error
                Log::error('Failed to resend VFD receipt notification', [
                    'receipt_id' => $receipt->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                $this->error("\nError processing receipt {$receipt->receipt_number}: " . $e->getMessage());
                
                // Update receipt with error
                $receipt->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        
        $this->newLine(2);
        $this->info("Completed processing {$receipts->count()} receipts.");
        $this->info("Successfully sent: {$successCount}");
        $this->error("Failed to send: {$failedCount}");
        
        return 0;
    }
}
