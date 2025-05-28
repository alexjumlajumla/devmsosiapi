<?php

namespace App\Providers;

use App\Channels\FcmChannel;
use App\Services\FCM\FcmTokenService;
use App\Services\Notification\FirebaseTokenService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Messaging;

class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register Firebase Token Service
        $this->app->singleton(FirebaseTokenService::class, function ($app) {
            return new FirebaseTokenService(
                $app->make(Auth::class)
            );
        });

        // Register FCM Token Service
        $this->app->singleton(FcmTokenService::class, function ($app) {
            return new FcmTokenService();
        });

        // Register Notification Service
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
        // Register the FCM notification channel
        Notification::extend('fcm', function ($app) {
            return new FcmChannel(
                $app->make(FcmTokenService::class)
            );
        });
    }
}
