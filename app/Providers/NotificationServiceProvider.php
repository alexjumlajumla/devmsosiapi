<?php

namespace App\Providers;

use App\Services\Notification\FirebaseTokenService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Auth;

class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(FirebaseTokenService::class, function ($app) {
            return new FirebaseTokenService(
                $app->make(Auth::class)
            );
        });

        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService(
                $app->make(FirebaseTokenService::class)
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
