<?php

namespace Tests\Feature;

use App\Jobs\GenerateVfdReceipt;
use App\Models\Order;
use App\Models\User;
use App\Models\VfdReceipt;
use App\Services\Order\VfdReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VfdServiceTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock HTTP client to avoid real API calls
        Http::fake();
        
        // Mock job dispatching
        Bus::fake();
    }

    /** @test */
    public function it_generates_a_receipt_for_an_order()
    {
        // Create a test order with delivery fee
        $order = Order::factory()->create([
            'delivery_fee' => 5000, // 50.00
            'status' => 'delivered',
        ]);
        
        $service = app(VfdReceiptService::class);
        
        // Generate receipt
        $result = $service->generateForOrder($order, 'card');
        
        // Assert the receipt was generated successfully
        $this->assertTrue($result['status']);
        $this->assertEquals('Receipt generation queued', $result['message']);
        
        // Assert the receipt was created in the database
        $this->assertDatabaseHas('vfd_receipts', [
            'model_id' => $order->id,
            'model_type' => Order::class,
            'amount' => 5000,
            'payment_method' => 'card',
            'status' => 'pending',
        ]);
    }
    
    /** @test */
    public function it_does_not_generate_duplicate_receipts()
    {
        // Create a test order with an existing receipt
        $order = Order::factory()->create([
            'delivery_fee' => 5000,
            'status' => 'delivered',
        ]);
        
        // Create an existing receipt
        VfdReceipt::create([
            'receipt_number' => 'VFD-' . time() . '-1234',
            'receipt_type' => 'delivery',
            'model_id' => $order->id,
            'model_type' => Order::class,
            'amount' => 5000,
            'payment_method' => 'card',
            'status' => 'generated',
        ]);
        
        $service = app(VfdReceiptService::class);
        
        // Try to generate another receipt
        $result = $service->generateForOrder($order, 'card');
        
        // Assert it returns the existing receipt
        $this->assertTrue($result['status']);
        $this->assertEquals('Receipt already generated', $result['message']);
    }
    
    /** @test */
    public function it_dispatches_generate_vfd_receipt_job()
    {
        // Create a test order
        $order = Order::factory()->create([
            'delivery_fee' => 5000,
            'status' => 'delivered',
        ]);
        
        $service = app(VfdReceiptService::class);
        
        // Generate receipt
        $service->generateForOrder($order, 'card');
        
        // Assert the job was dispatched
        Bus::assertDispatched(GenerateVfdReceipt::class, function ($job) use ($order) {
            return $job->receiptData['model_id'] === $order->id;
        });
    }
    
    /** @test */
    public function it_handles_zero_delivery_fee()
    {
        // Create a test order with no delivery fee
        $order = Order::factory()->create([
            'delivery_fee' => 0,
            'status' => 'delivered',
        ]);
        
        $service = app(VfdReceiptService::class);
        
        // Try to generate receipt
        $result = $service->generateForOrder($order, 'cash');
        
        // Assert no receipt was created
        $this->assertFalse($result['status']);
        $this->assertEquals('No delivery fee for this order', $result['message']);
    }
    
    /** @test */
    public function it_returns_receipt_for_order()
    {
        // Create a test order with a receipt
        $order = Order::factory()->create([
            'delivery_fee' => 5000,
            'status' => 'delivered',
        ]);
        
        $receipt = VfdReceipt::create([
            'receipt_number' => 'VFD-' . time() . '-1234',
            'receipt_type' => 'delivery',
            'model_id' => $order->id,
            'model_type' => Order::class,
            'amount' => 5000,
            'payment_method' => 'card',
            'status' => 'generated',
            'receipt_url' => 'https://example.com/receipt/123',
        ]);
        
        $service = app(VfdReceiptService::class);
        
        // Get receipt for order
        $result = $service->getForOrder($order);
        
        // Assert the correct receipt is returned
        $this->assertNotNull($result);
        $this->assertEquals($receipt->id, $result->id);
        $this->assertEquals('https://example.com/receipt/123', $result->receipt_url);
    }
}
