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
            return new VfdService();
        });
        
        // VFD Archive Service
        $this->app->singleton(VfdArchiveService::class, function ($app) {
            return new VfdArchiveService();
        });

        // Notification Service
        $this->app->singleton(VfdReceiptNotificationService::class, function ($app) {
            return new VfdReceiptNotificationService(
                $app->make(MobishastraService::class),
                $app->make(TwilioService::class)
            );
        });

        // Receipt Service
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
