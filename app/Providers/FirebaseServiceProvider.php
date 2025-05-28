<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Contract\Firestore;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Contract\RemoteConfig;
use Kreait\Firebase\Contract\Storage;
use Kreait\Firebase\Factory;
use Kreait\Firebase\ServiceAccount;
use Kreait\Firebase\Messaging\CloudMessage;

class FirebaseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(Factory::class, function ($app) {
            $serviceAccount = [
                'type' => 'service_account',
                'project_id' => config('fcm.project_id'),
                'private_key_id' => env('FIREBASE_PRIVATE_KEY_ID'),
                'private_key' => str_replace('\\n', "\n", env('FIREBASE_PRIVATE_KEY')),
                'client_email' => env('FIREBASE_CLIENT_EMAIL'),
                'client_id' => env('FIREBASE_CLIENT_ID'),
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => env('FIREBASE_CLIENT_CERT_URL'),
            ];

            $factory = (new Factory)
                ->withServiceAccount($serviceAccount)
                ->withDatabaseUri(env('FIREBASE_DATABASE_URL'));

            // Enable HTTP debugging if in debug mode
            if (config('app.debug')) {
                $factory->withHttpClientOptions([
                    'debug' => true,
                ]);
            }

            return $factory;
        });

        // Bind Firebase services
        $this->app->bind(Auth::class, function ($app) {
            return $app->make(Factory::class)->createAuth();
        });

        $this->app->bind(Firestore::class, function ($app) {
            return $app->make(Factory::class)->createFirestore();
        });

        $this->app->bind(Messaging::class, function ($app) {
            return $app->make(Factory::class)->createMessaging();
        });

        $this->app->bind(RemoteConfig::class, function ($app) {
            return $app->make(Factory::class)->createRemoteConfig();
        });

        $this->app->bind(Storage::class, function ($app) {
            return $app->make(Factory::class)->createStorage();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish the configuration file
        $this->publishes([
            __DIR__.'/../../config/fcm.php' => config_path('fcm.php'),
        ], 'fcm-config');
    }
}
