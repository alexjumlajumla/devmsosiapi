<?php

namespace App\Providers;

use App\Services\FCM\EnhancedFcmTokenService;
use Illuminate\Support\ServiceProvider;

class EnhancedFcmServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(EnhancedFcmTokenService::class, function ($app) {
            return new EnhancedFcmTokenService();
        });
        
        // Register the enhanced service as the default FCM token service
        $this->app->bind(
            \App\Services\FCM\FcmTokenServiceInterface::class,
            EnhancedFcmTokenService::class
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
