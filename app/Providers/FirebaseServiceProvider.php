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
use Illuminate\Support\Facades\Log;

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
            try {
                // Get configuration values with fallbacks
                $serviceAccount = $this->getServiceAccountConfig();
                
                // Initialize Firebase factory with retry logic
                $maxRetries = 3;
                $attempt = 0;
                $lastException = null;
                
                while ($attempt < $maxRetries) {
                    try {
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
                                'verify' => true,
                                'http_errors' => true,
                                'timeout' => 30,
                                'connect_timeout' => 10,
                            ]);
                        }
                        
                        // Test the connection
                        $auth = $factory->createAuth();
                        $auth->getApiClient(); // This will throw if auth fails
                        
                        Log::info('Successfully initialized Firebase factory');
                        return $factory;
                        
                    } catch (\Kreait\Firebase\Exception\Auth\InvalidAccessToken $e) {
                        $lastException = $e;
                        $attempt++;
                        Log::warning(sprintf(
                            'Firebase auth failed (attempt %d/%d): %s',
                            $attempt,
                            $maxRetries,
                            $e->getMessage()
                        ));
                        
                        if ($attempt >= $maxRetries) {
                            break;
                        }
                        
                        // Wait before retrying
                        usleep(500000 * $attempt); // 500ms, 1s, 1.5s, etc.
                    } catch (\Exception $e) {
                        $lastException = $e;
                        Log::error('Failed to initialize Firebase factory', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        break;
                    }
                }
                
                throw $lastException ?? new \RuntimeException('Failed to initialize Firebase factory after multiple attempts');
                
            } catch (\Exception $e) {
                Log::critical('Critical error initializing Firebase', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                // Rethrow to prevent silent failures
                throw $e;
            }
        });
    }
    
    /**
     * Get the service account configuration with proper error handling.
     *
     * @return array
     * @throws \RuntimeException If required configuration is missing
     */
    protected function getServiceAccountConfig(): array
    {
        $required = [
            'project_id' => config('fcm.project_id'),
            'private_key_id' => env('FIREBASE_PRIVATE_KEY_ID'),
            'private_key' => env('FIREBASE_PRIVATE_KEY'),
            'client_email' => env('FIREBASE_CLIENT_EMAIL'),
            'client_id' => env('FIREBASE_CLIENT_ID'),
            'client_cert_url' => env('FIREBASE_CLIENT_CERT_URL'),
        ];
        
        $missing = [];
        foreach ($required as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }
        
        if (!empty($missing)) {
            throw new \RuntimeException(sprintf(
                'Missing required Firebase configuration: %s',
                implode(', ', $missing)
            ));
        }
        
        return [
            'type' => 'service_account',
            'project_id' => $required['project_id'],
            'private_key_id' => $required['private_key_id'],
            'private_key' => str_replace('\\n', "\n", $required['private_key']),
            'client_email' => $required['client_email'],
            'client_id' => $required['client_id'],
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url' => $required['client_cert_url'],
            'universe_domain' => 'googleapis.com',
        ];
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
