<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (FCM) Configuration
    |--------------------------------------------------------------------------
    |
    | This file contains the configuration for Firebase Cloud Messaging.
    |
    */


    /*
    |--------------------------------------------------------------------------
    | FCM Project ID
    |--------------------------------------------------------------------------
    |
    | The project ID from the Firebase Console.
    |
    */
    'project_id' => env('FIREBASE_PROJECT_ID', env('GOOGLE_CLOUD_PROJECT_ID')),

    /*
    |--------------------------------------------------------------------------
    | FCM API Key
    |--------------------------------------------------------------------------
    |
    | The server key from the Firebase Console.
    |
    */
    'api_key' => env('FIREBASE_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | FCM Sender ID
    |--------------------------------------------------------------------------
    |
    | The sender ID from the Firebase Console.
    |
    */
    'sender_id' => env('FIREBASE_SENDER_ID'),

    /*
    |--------------------------------------------------------------------------
    | FCM Server Key
    |--------------------------------------------------------------------------
    |
    | The server key from the Firebase Console.
    |
    */
    'server_key' => env('FIREBASE_SERVER_KEY'),


    /*
    |--------------------------------------------------------------------------
    | FCM Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration options for FCM.
    |
    */
    'config' => [
        // Timeout in seconds for FCM requests
        'timeout' => 10,
        
        // Number of retries for failed requests
        'retry_attempts' => 2,
        
        // Delay between retries in milliseconds
        'retry_delay' => 100,
        
        // Maximum number of tokens per batch request
        'max_tokens_per_batch' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | FCM Logging
    |--------------------------------------------------------------------------
    |
    | Logging configuration for FCM operations.
    |
    */
    'logging' => [
        // Enable/disable logging
        'enabled' => env('FCM_LOGGING_ENABLED', true),
        
        // Log level for FCM operations
        'level' => env('FCM_LOGGING_LEVEL', 'debug'),
        
        // Log channel to use
        'channel' => env('FCM_LOGGING_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FCM Cache
    |--------------------------------------------------------------------------
    |
    | Cache configuration for FCM tokens and other data.
    |
    */
    'cache' => [
        // Cache store to use
        'store' => env('FCM_CACHE_STORE', 'file'),
        
        // Cache prefix
        'prefix' => 'fcm_',
        
        // Cache TTL in seconds
        'ttl' => 3600, // 1 hour
    ],

    /*
    |--------------------------------------------------------------------------
    | FCM Defaults
    |--------------------------------------------------------------------------
    |
    | Default values for FCM notifications.
    |
    */
    'defaults' => [
        // Default notification sound
        'sound' => 'default',
        
        // Default notification priority
        'priority' => 'high',
        
        // Default notification visibility
        'visibility' => 'private',
        
        // Default notification badge count
        'badge' => 1,
    ],
];
