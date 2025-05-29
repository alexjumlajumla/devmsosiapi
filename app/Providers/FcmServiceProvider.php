<?php

namespace App\Providers;

use App\Services\FCM\FcmTokenService;
use Illuminate\Support\ServiceProvider;

class FcmServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(FcmTokenService::class, function ($app) {
            return new FcmTokenService();
        });
        
        // Merge config if not already merged
        $this->mergeConfigFrom(
            __DIR__.'/../../config/fcm.php', 'fcm'
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish the config file
        $this->publishes([
            __DIR__.'/../../config/fcm.php' => config_path('fcm.php'),
        ], 'fcm-config');
        
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\CleanupInvalidFcmTokens::class,
                \App\Console\Commands\TestFcmNotification::class,
                \App\Console\Commands\InspectFcmToken::class,
                \App\Console\Commands\DebugFcmTokens::class,
                \App\Console\Commands\ListFcmTokens::class,
                \App\Console\Commands\UpdateTestFcmTokens::class,
            ]);
        }
    }
}
