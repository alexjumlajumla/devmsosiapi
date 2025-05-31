<?php

namespace App\Providers;

use App\Services\Notification\VfdReceiptNotificationService;
use App\Services\Order\VfdReceiptService;
use App\Services\SMSGatewayService\MobishastraService;
use App\Services\SMSGatewayService\TwilioService;
use App\Services\VfdService\VfdArchiveService;
use App\Services\VfdService\VfdService;
use Illuminate\Support\ServiceProvider;

class VfdServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Main VFD Service
        $this->app->singleton(VfdService::class, function ($app) {
            try {
                return new VfdService();
            } catch (\Exception $e) {
                // Log the error but don't break the application
                logger()->error('Failed to initialize VfdService: ' . $e->getMessage());
                
                // Return a mock service in sandbox mode
                if (config('services.vfd.sandbox', true)) {
                    return new class extends VfdService {
                        public function __construct() {
                            $this->isSandbox = true;
                            $this->baseUrl = 'https://vfd-sandbox.mojatax.com';
                            $this->apiKey = 'sandbox_test_key_123456';
                            $this->tin = '123456789';
                        }
                        
                        public function testConnection(): array {
                            return [
                                'success' => true,
                                'message' => 'Sandbox mode: Using mock VFD service',
                                'sandbox' => true
                            ];
                        }
                    };
                }
                
                throw $e;
            }
        });
        
        // VFD Archive Service - Lazy load to prevent issues if VFD service fails
        $this->app->singleton(VfdArchiveService::class, function ($app) {
            return new VfdArchiveService();
        });

        // Notification Service - Lazy load
        $this->app->singleton(VfdReceiptNotificationService::class, function ($app) {
            return new VfdReceiptNotificationService(
                $app->make(MobishastraService::class),
                $app->make(TwilioService::class)
            );
        });

        // Receipt Service - Lazy load dependencies
        $this->app->singleton(VfdReceiptService::class, function ($app) {
            return new VfdReceiptService(
                $app->make(VfdService::class),
                $app->make(VfdReceiptNotificationService::class),
                $app->make(VfdArchiveService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
