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
        $this->registerFirebaseFactory();
        $this->registerFirebaseServices();
    }

    /**
     * Register the Firebase factory.
     */
    protected function registerFirebaseFactory(): void
    {
        $this->app->singleton(Factory::class, function ($app) {
            // Get configuration values
            $projectId = config('fcm.project_id');
            $privateKeyId = env('FIREBASE_PRIVATE_KEY_ID');
            $privateKey = env('FIREBASE_PRIVATE_KEY');
            $clientEmail = env('FIREBASE_CLIENT_EMAIL');
            $clientId = env('FIREBASE_CLIENT_ID');
            $clientCertUrl = env('FIREBASE_CLIENT_CERT_URL');
            
            // Create service account configuration
            $serviceAccount = [
                'type' => 'service_account',
                'project_id' => $projectId,
                'private_key_id' => $privateKeyId,
                'private_key' => str_replace('\\n', "\n", $privateKey),
                'client_email' => $clientEmail,
                'client_id' => $clientId,
                'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                'token_uri' => 'https://oauth2.googleapis.com/token',
                'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                'client_x509_cert_url' => $clientCertUrl,
            ];

            // Initialize Firebase factory
            $factory = new Factory();
            $factory = $factory->withServiceAccount($serviceAccount);
            
            // Set database URI if configured
            $databaseUrl = config('fcm.database_url');
            if (!empty($databaseUrl)) {
                $factory = $factory->withDatabaseUri($databaseUrl);
            }

            // Enable HTTP debugging in debug mode
            if (config('app.debug')) {
                $factory->withHttpClientOptions([
                    'debug' => true,
                ]);
            }

            return $factory;
        });
    }

    /**
     * Register Firebase services.
     */
    protected function registerFirebaseServices(): void
    {
        $this->app->singleton(Auth::class, function ($app) {
            return $app->make(Factory::class)->createAuth();
        });
        
        $this->app->singleton(Firestore::class, function ($app) {
            return $app->make(Factory::class)->createFirestore();
        });
        
        $this->app->singleton(Messaging::class, function ($app) {
            return $app->make(Factory::class)->createMessaging();
        });
        
        $this->app->singleton(RemoteConfig::class, function ($app) {
            return $app->make(Factory::class)->createRemoteConfig();
        });
        
        $this->app->singleton(Storage::class, function ($app) {
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
