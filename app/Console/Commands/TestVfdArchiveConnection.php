<?php

namespace App\Console\Commands;

use App\Services\Order\VfdReceiptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestVfdArchiveConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vfd:test-archive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the connection to the VFD archive service';

    /**
     * Execute the console command.
     *
     * @param VfdReceiptService $receiptService
     * @return int
     */
    public function handle(VfdReceiptService $receiptService)
    {
        $this->info('Testing connection to VFD archive service...');
        
        $result = $receiptService->testArchiveConnection();
        
        if ($result['success']) {
            $this->info('✅ Connection successful!');
            $this->line('Service Status: ' . ($result['data']['status'] ?? 'N/A'));
            $this->line('Service Version: ' . ($result['data']['version'] ?? 'N/A'));
            return 0;
        }
        
        $this->error('❌ Connection failed: ' . $result['message']);
        
        if (isset($result['exception'])) {
            $this->line('');
            $this->warn('Exception Details:');
            $this->line('Message: ' . $result['exception']['message']);
            $this->line('File: ' . $result['exception']['file']);
            $this->line('Line: ' . $result['exception']['line']);
        }
        
        return 1;
    }
}
