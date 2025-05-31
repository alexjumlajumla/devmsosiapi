<?php

namespace App\Console\Commands;

use App\Jobs\GenerateVfdReceipt;
use App\Models\VfdReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryFailedVfdReceipts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vfd:retry-failed {--limit=10 : Maximum number of failed receipts to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry generating VFD receipts that previously failed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $limit = (int) $this->option('limit');
        
        $failedReceipts = VfdReceipt::where('status', VfdReceipt::STATUS_FAILED)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($failedReceipts->isEmpty()) {
            $this->info('No failed VFD receipts found.');
            return 0;
        }

        $this->info("Found {$failedReceipts->count()} failed VFD receipts to retry.");
        
        $bar = $this->output->createProgressBar($failedReceipts->count());
        $bar->start();

        $successCount = 0;
        
        foreach ($failedReceipts as $receipt) {
            try {
                // Prepare receipt data from the failed receipt
                $receiptData = [
                    'type' => $receipt->receipt_type,
                    'model_id' => $receipt->model_id,
                    'model_type' => $receipt->model_type,
                    'amount' => $receipt->amount,
                    'payment_method' => $receipt->payment_method,
                    'customer_name' => $receipt->customer_name,
                    'customer_phone' => $receipt->customer_phone,
                    'customer_email' => $receipt->customer_email,
                ];

                // Dispatch a new job to retry the receipt generation
                GenerateVfdReceipt::dispatch($receiptData);
                $successCount++;
                
            } catch (\Exception $e) {
                Log::error('Failed to queue retry for VFD receipt: ' . $e->getMessage(), [
                    'receipt_id' => $receipt->id,
                    'trace' => $e->getTraceAsString()
                ]);
                
                $this->error("Error processing receipt ID {$receipt->id}: " . $e->getMessage());
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine(2);
        
        $this->info("Successfully queued {$successCount} failed VFD receipts for retry.");
        
        return 0;
    }
}
