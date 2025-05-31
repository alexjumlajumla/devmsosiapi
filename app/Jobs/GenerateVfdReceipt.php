<?php

namespace App\Jobs;

use App\Models\VfdReceipt;
use App\Services\Order\VfdReceiptService;
use App\Services\VfdService\VfdService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateVfdReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $receiptData;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int[]
     */
    public $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     *
     * @param array $receiptData
     * @return void
     */
    public function __construct(array $receiptData)
    {
        $this->receiptData = $receiptData;
    }

    /**
     * Execute the job.
     *
     * @param VfdService $vfdService
     * @return void
     */
    public function handle(VfdService $vfdService, VfdReceiptService $receiptService): void
    {
        Log::info('Processing VFD receipt generation job', $this->receiptData);

        try {
            // Generate the receipt
            $result = $vfdService->generateReceipt(
                $this->receiptData['type'],
                $this->receiptData
            );

            if ($result['status'] && $result['data'] instanceof VfdReceipt) {
                $receipt = $result['data'];
                
                Log::info('VFD receipt generated successfully', [
                    'receipt_id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                ]);
                
                // Send SMS notification with receipt link
                if (!empty($receipt->receipt_url) && !empty($receipt->customer_phone)) {
                    $smsSent = $receiptService->sendReceiptSms($receipt);
                    
                    if ($smsSent) {
                        Log::info('SMS notification sent for receipt', [
                            'receipt_id' => $receipt->id,
                            'phone' => $receipt->customer_phone,
                        ]);
                    } else {
                        Log::warning('Failed to send SMS notification for receipt', [
                            'receipt_id' => $receipt->id,
                            'phone' => $receipt->customer_phone,
                        ]);
                    }
                } else {
                    Log::warning('Cannot send SMS: Missing receipt URL or customer phone', [
                        'receipt_id' => $receipt->id,
                        'has_url' => !empty($receipt->receipt_url),
                        'has_phone' => !empty($receipt->customer_phone),
                    ]);
                }
            } else {
                Log::error('Failed to generate VFD receipt', [
                    'error' => $result['message'] ?? 'Unknown error',
                    'data' => $this->receiptData,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception in GenerateVfdReceipt job', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $this->receiptData,
            ]);
            
            // Update receipt status to failed
            if (isset($receipt) && $receipt instanceof VfdReceipt) {
                $receipt->update([
                    'status' => VfdReceipt::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }
            
            // Re-throw the exception to trigger job retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        // Notify admin or log to a dedicated error log
        Log::critical('VFD receipt generation job failed after all attempts', [
            'error' => $exception->getMessage(),
            'receipt_data' => $this->receiptData,
            'attempts' => $this->attempts()
        ]);

        // You could also send an email or notification to the admin here
        // Notification::route('mail', 'admin@example.com')
        //     ->notify(new VfdReceiptFailed($this->receiptData, $exception));
    }
}
