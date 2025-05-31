<?php

namespace Tests\Feature;

use App\Jobs\ArchiveVfdReceipt;
use App\Models\VfdReceipt;
use App\Services\Order\VfdReceiptService;
use App\Services\VfdService\VfdArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VfdArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_syncs_receipt_to_archive()
    {
        // Mock the HTTP client
        Http::fake([
            'archive.example.com/*' => Http::response(['status' => 'success'], 200),
        ]);

        // Create a test receipt
        $receipt = VfdReceipt::factory()->create([
            'status' => VfdReceipt::STATUS_GENERATED,
            'synced_to_archive_at' => null,
        ]);

        // Create the service with test config
        config([
            'services.vfd.archive_enabled' => true,
            'services.vfd.archive_endpoint' => 'https://archive.example.com/api/receipts',
            'services.vfd.archive_api_key' => 'test-api-key',
        ]);

        $service = new VfdArchiveService();
        $result = $service->syncReceipt($receipt);

        // Assert the sync was successful
        $this->assertTrue($result['success']);
        $this->assertNotNull($receipt->fresh()->synced_to_archive_at);
        $this->assertNull($receipt->fresh()->sync_error);
    }

    /** @test */
    public function it_handles_failed_sync()
    {
        // Mock a failed HTTP response
        Http::fake([
            'archive.example.com/*' => Http::response(['error' => 'Invalid API key'], 401),
        ]);

        // Create a test receipt
        $receipt = VfdReceipt::factory()->create([
            'status' => VfdReceipt::STATUS_GENERATED,
            'synced_to_archive_at' => null,
        ]);

        // Create the service with test config
        config([
            'services.vfd.archive_enabled' => true,
            'services.vfd.archive_endpoint' => 'https://archive.example.com/api/receipts',
            'services.vfd.archive_api_key' => 'invalid-api-key',
        ]);

        $service = new VfdArchiveService();
        $result = $service->syncReceipt($receipt);

        // Assert the sync failed
        $this->assertFalse($result['success']);
        $this->assertNull($receipt->fresh()->synced_to_archive_at);
        $this->assertNotNull($receipt->fresh()->sync_error);
    }

    /** @test */
    public function it_dispatches_archive_job_when_receipt_created()
    {
        Queue::fake();

        // Enable archiving
        config([
            'services.vfd.archive_enabled' => true,
        ]);

        // Create a receipt which should dispatch the job
        $receipt = VfdReceipt::factory()->create([
            'status' => VfdReceipt::STATUS_GENERATED,
        ]);

        // Assert the job was dispatched
        Queue::assertPushed(ArchiveVfdReceipt::class, function ($job) use ($receipt) {
            return $job->receipt->id === $receipt->id;
        });
    }

    /** @test */
    public function it_does_not_dispatch_job_when_archiving_disabled()
    {
        Queue::fake();

        // Disable archiving
        config([
            'services.vfd.archive_enabled' => false,
        ]);

        // Create a receipt
        $receipt = VfdReceipt::factory()->create([
            'status' => VfdReceipt::STATUS_GENERATED,
        ]);

        // Assert no job was dispatched
        Queue::assertNotPushed(ArchiveVfdReceipt::class);
    }

    /** @test */
    public function it_tests_archive_connection()
    {
        // Mock a successful health check
        Http::fake([
            'archive.example.com/health' => Http::response(['status' => 'ok', 'version' => '1.0.0'], 200),
        ]);

        // Create the service with test config
        config([
            'services.vfd.archive_endpoint' => 'https://archive.example.com',
            'services.vfd.archive_api_key' => 'test-api-key',
        ]);

        $service = new VfdArchiveService();
        $result = $service->testConnection();

        // Assert the connection was successful
        $this->assertTrue($result['success']);
        $this->assertEquals('Connection successful', $result['message']);
        $this->assertEquals('1.0.0', $result['data']['version'] ?? null);
    }

    /** @test */
    public function it_handles_failed_connection_test()
    {
        // Mock a failed health check
        Http::fake([
            'archive.example.com/health' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        // Create the service with test config
        config([
            'services.vfd.archive_endpoint' => 'https://archive.example.com',
            'services.vfd.archive_api_key' => 'invalid-api-key',
        ]);

        $service = new VfdArchiveService();
        $result = $service->testConnection();

        // Assert the connection failed
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Connection failed', $result['message']);
    }
}
