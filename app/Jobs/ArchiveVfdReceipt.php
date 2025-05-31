<?php

namespace App\Jobs;

use App\Models\VfdReceipt;
use App\Services\Order\VfdReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ArchiveVfdReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int
     */
    public $maxExceptions = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public $backoff = [60, 300, 900]; // 1 min, 5 mins, 15 mins

    /**
     * The VFD receipt instance.
     *
     * @var VfdReceipt
     */
    protected $receipt;

    /**
     * Create a new job instance.
     *
     * @param VfdReceipt $receipt
     * @return void
     */
    public function __construct(VfdReceipt $receipt)
    {
        $this->receipt = $receipt->withoutRelations();
    }

    /**
     * Execute the job.
     *
     * @param VfdReceiptService $receiptService
     * @return void
     */
    public function handle(VfdReceiptService $receiptService)
    {
        // Reload the receipt to ensure we have the latest data
        $receipt = VfdReceipt::findOrFail($this->receipt->id);
        
        // Skip if already synced
        if ($receipt->synced_to_archive_at) {
            Log::info('Skipping already archived receipt', [
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
            ]);
            return;
        }
        
        // Skip if not in a syncable state
        if ($receipt->status !== VfdReceipt::STATUS_GENERATED) {
            Log::warning('Skipping non-generated receipt for archiving', [
                'receipt_id' => $receipt->id,
                'status' => $receipt->status,
            ]);
            return;
        }
        
        // Sync the receipt to the archive
        $result = $receiptService->syncToArchive($receipt);
        
        if (!$result['success']) {
            Log::error('Failed to archive VFD receipt', [
                'receipt_id' => $receipt->id,
                'error' => $result['message'],
                'exception' => $result['exception'] ?? null,
            ]);
            
            // This will trigger a retry
            throw new \Exception($result['message']);
        }
        
        Log::info('Successfully archived VFD receipt', [
            'receipt_id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
        ]);
    }
    
    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        // Mark the receipt with the error
        if ($this->receipt) {
            $receipt = VfdReceipt::find($this->receipt->id);
            
            if ($receipt) {
                $receipt->update([
                    'sync_error' => $exception->getMessage(),
                ]);
                
                Log::error('VFD receipt archiving job failed', [
                    'receipt_id' => $receipt->id,
                    'error' => $exception->getMessage(),
                    'attempts' => $this->attempts(),
                ]);
            }
        }
    }
    
    /**
     * The job's unique ID.
     *
     * @return string
     */
    public function uniqueId()
    {
        return 'vfd-archive-' . $this->receipt->id;
    }
}
