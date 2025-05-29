<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Log;

class GoogleServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('google.credentials', function ($app) {
            $primaryPath = env('GOOGLE_APPLICATION_CREDENTIALS');
            $fallbackPath1 = storage_path('app/google-service-account.json');
            $fallbackPath2 = storage_path('app/jumlajumla-1f0f0-98ab02854aef.json');
            $fallbackPath3 = base_path('jumlajumla-1f0f0-98ab02854aef.json');
            
            // Check if the primary path exists
            if (!empty($primaryPath) && file_exists($primaryPath)) {
                Log::info("Using Google credentials from env: $primaryPath");
                return $primaryPath;
            }
            
            // Try fallback paths
            if (file_exists($fallbackPath1)) {
                Log::info("Using fallback Google credentials: $fallbackPath1");
                return $fallbackPath1;
            }
            
            if (file_exists($fallbackPath2)) {
                Log::info("Using fallback Google credentials: $fallbackPath2");
                return $fallbackPath2;
            }
            
            if (file_exists($fallbackPath3)) {
                Log::info("Using fallback Google credentials: $fallbackPath3");
                return $fallbackPath3;
            }
            
            // If we get here, no valid path was found
            Log::warning("No valid Google credentials file found");
            return $primaryPath; // Return the primary path anyway, let the app handle the error
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
