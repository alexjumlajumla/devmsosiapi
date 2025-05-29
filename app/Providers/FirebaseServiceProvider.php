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
        // Hardcoded configuration based on the provided credentials
        $config = [
            'type' => 'service_account',
            'project_id' => 'msosijumla',
            'private_key_id' => 'a14562c86b5b3672c0b99a5f4b6e911175f42ca4',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDk1uM9M87Xsjbb\n6F8lGDp2LJ8nFieQgPiIHdmXv4dP8q7rU41l3AgXBUahKTiS7tDVJwuSrk+mmvmd\ncT0Wh0wkklgaxmCBRJh39ZwqTA5GBAid9lxwFlyMMY7xBjIkBfP4wg/YYVSn/1yY\n5sKIMV5LfWcDZy4HoeQSHqmqcQd+x/KPLS9OFT6feUBLdIf501J9fzOR71jNW2N+\nv9Myc/zCLdmqaik7b4Cs3NXNNSHFUY6fB06JQdzJnm/xl9oS+vSz//fol1eV8R0i\nMq93GNq/bHwDqL9izyUfoiISCTZ4r1Oii8WmKhN02geNBO/FZhxJy2uNGtFji3KU\nPxSFyP5bAgMBAAECggEAMYWZVojMJdyAx7UxRd9I44MDjBtcw4ZNgnNnP9Iob7I2\njWGe82Ca9ZRkNQMtJYr98WrKM9t6DDV0eFBlpmbwIOf0nhK5FrBoCGnD+llMK3W7\nAagrV+xW6dXdt6YeGrKZGgexEyP5BIQcH1Cs33lDjTWdodxl1yv/JbayA9sDArnM\nln1xTEadBe8xDYFjarRH0nfOTWPiEgt/WIAq0SJzrrO97+7vVjr7KRyDlZoaYbup\nxzaWLdeCCrzViI/DQOGnlC8cnH0dkOna6F26mG+NIKx+m8zvg0OPLxPH16YGxQMd\nIh/uRFFyaAtJZYlz7WUbPFtFJHICYG3ZHkziH1R25QKBgQD8toyd32IZAM/HtYMk\ndhM1zDO2wKXDxI4JgKNkxfQE14zBAO+mp17O9rTE/hc5b0NxkwEJSfvxPzaGfVsU\n2zMC7/o8ikGf3xiwqQsu2p/0L678SF/2R43Lu9Cs+GhA3c/tBUSf9p/9vaqVpPZs\nOHKtOmUSOFOryoVGpoUYqyoi3QKBgQDn0NjRJ8yjRJGJUuw8mvWva33aNPMO5y6Z\noR/GImXoLHzSTq/xhbNLVkKyDSJ5gCOMUiu8b3JBBeHK3pgXPIML82RrRukLS9Eg\nJKF6j7U99SCQRPspw0kH05+DbKUcyq5ozZ2jQghe2NPr4FV2L9+TdaDSwu2Gd2ay\nASFzPp5GlwKBgQDdeLJ1bR7QoMh30lhzLNObEzHDGMRtdCWyqD0KBP3c/HbLcqGU\nYRwSr10vQythV2Q49cczt9YH0AleBiA7f/sNuPiJ8/SdQmyl7g/x6QHDg8KMMHWB\nJaZcBWZVIIJlTr95jmNc+UuvmXgVG3Qm1bWSoRmQxTJ23M6+YxND0kXkNQKBgFkl\nvKu6hXzoGpvX0td/tCnQyaZHpI0/pHEaQHDeu5fsu9fYwNq90vSO6Lk2SeK1v3Xw\nB7fAmAyfaXSt44lUEQVghWan72kTsAmPbLYIW+fGw84XaQtneUdUP8y31EtdOnM9\nV3j4JOXstprIO7VmtbEslDtZESUb99dOjgGWvCFjAoGAZKgN9EWzjz0vVQ56CMz7\nNNfLCdGeW/qq1bOnvMtrA98MaUjw7EFz6cs690Go6bmubiPgjTmIcSbKnm8arnDc\ncfJQZnMTVgjAvB2Zwv0RUH3vdPKvXCJKiexpHjFUOuIktZ25M1X6OPOZVmRgrOYB\nUJDNgKIV1NVuN7H0AmSbRkY=\n-----END PRIVATE KEY-----",
            'client_email' => 'firebase-adminsdk-zubkp@msosijumla.iam.gserviceaccount.com',
            'client_id' => '104096160020774877435',
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-zubkp%40msosijumla.iam.gserviceaccount.com',
            'universe_domain' => 'googleapis.com',
        ];

        // Log the configuration (without sensitive data)
        \Log::debug('Firebase configuration loaded', [
            'project_id' => $config['project_id'],
            'client_email' => $config['client_email'],
            'has_private_key' => !empty($config['private_key']),
            'has_private_key_id' => !empty($config['private_key_id']),
        ]);

        return $config;
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
