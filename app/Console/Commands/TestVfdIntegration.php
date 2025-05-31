<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\VfdService\VfdService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestVfdIntegration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vfd:test {orderId : The ID of the order to test with}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the VFD service integration with a specific order';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $orderId = $this->argument('orderId');
        
        // Find the order
        $order = Order::find($orderId);
        
        if (!$order) {
            $this->error("Order with ID {$orderId} not found.");
            return 1;
        }
        
        $this->info("Testing VFD integration with Order #{$order->id}");
        $this->line("Amount: " . number_format($order->delivery_fee / 100, 2) . " TZS");
        $this->line("Customer: {$order->username} ({$order->phone})");
        
        // Initialize the VFD service
        $vfdService = app(VfdService::class);
        
        // Test connection
        $this->info("\nTesting VFD API connection...");
        
        try {
            $response = $vfdService->testConnection();
            
            if ($response['success']) {
                $this->info("✅ VFD API connection successful");
                $this->line("Service Status: " . ($response['data']['status'] ?? 'N/A'));
                $this->line("Service Version: " . ($response['data']['version'] ?? 'N/A'));
            } else {
                $this->warn("⚠️  VFD API connection issue: " . ($response['message'] ?? 'Unknown error'));
            }
        } catch (\Exception $e) {
            $this->error("❌ VFD API connection failed: " . $e->getMessage());
            return 1;
        }
        
        // Generate a test receipt
        $this->info("\nGenerating test receipt...");
        
        try {
            $receiptData = [
                'type' => 'delivery',
                'model_id' => $order->id,
                'model_type' => Order::class,
                'amount' => $order->delivery_fee,
                'payment_method' => 'card',
                'customer_name' => $order->username,
                'customer_phone' => $order->phone,
                'customer_email' => $order->email,
            ];
            
            $result = $vfdService->generateReceipt('delivery', $receiptData);
            
            if ($result['status']) {
                $this->info("✅ Receipt generated successfully");
                $this->line("Receipt Number: " . $result['data']->receipt_number);
                $this->line("Status: " . $result['data']->status);
                
                if (!empty($result['data']->receipt_url)) {
                    $this->line("Receipt URL: " . $result['data']->receipt_url);
                }
                
                $this->line("\nReceipt data:");
                $this->line(json_encode($result['data']->toArray(), JSON_PRETTY_PRINT));
            } else {
                $this->error("❌ Failed to generate receipt: " . ($result['message'] ?? 'Unknown error'));
                
                if (!empty($result['error'])) {
                    $this->line("Error details: " . (is_string($result['error']) ? $result['error'] : json_encode($result['error'])));
                }
                
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("❌ Exception while generating receipt: " . $e->getMessage());
            $this->line("Stack trace: " . $e->getTraceAsString());
            return 1;
        }
        
        return 0;
    }
}
