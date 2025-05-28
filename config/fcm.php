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
    'project_id' => env('FIREBASE_PROJECT_ID', 'msosijumla'),

    /*
    |--------------------------------------------------------------------------
    | FCM API Key
    |--------------------------------------------------------------------------
    |
    | The server key from the Firebase Console.
    |
    */
    'api_key' => env('FIREBASE_API_KEY', 'AIzaSyAuIozMv5MEpT94ynxZMSDheuqcSFshXWU'),

    /*
    |--------------------------------------------------------------------------
    | FCM Sender ID
    |--------------------------------------------------------------------------
    |
    | The sender ID from the Firebase Console.
    |
    */
    'sender_id' => env('FIREBASE_SENDER_ID', '853837987746'),
    'messaging_sender_id' => env('FIREBASE_MESSAGING_SENDER_ID', '853837987746'),
    'app_id' => env('FIREBASE_APP_ID', '1:853837987746:web:b0d822859c3a2605893cfb'),
    'measurement_id' => env('FIREBASE_MEASUREMENT_ID', 'G-YSYTD7ESL8'),
    'vapid_key' => env('FIREBASE_VAPID_KEY', 'BDO1IfAhx1-yl1pn7DjkBZi3a0NCAoiPsQLDHSqLXoB5QBxEEmxCTqKQNzPdBYtQXSow5KJlrDzzfph8hSkrsxE'),
    'auth_domain' => env('FIREBASE_AUTH_DOMAIN', 'msosijumla.firebaseapp.com'),
    'storage_bucket' => env('FIREBASE_STORAGE_BUCKET', 'msosijumla.appspot.com'),
    
    /*
    |--------------------------------------------------------------------------
    | FCM HTTP Protocol
    |--------------------------------------------------------------------------
    |
    | The HTTP protocol to use for FCM API calls.
    |
    */
    'http_protocol' => env('FIREBASE_HTTP_PROTOCOL', 'https'),
    
    /*
    |--------------------------------------------------------------------------
    | FCM HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout in seconds for HTTP requests to the FCM API.
    |
    */
    'http_timeout' => env('FIREBASE_HTTP_TIMEOUT', 30),
    
    /*
    |--------------------------------------------------------------------------
    | FCM Default Channel
    |--------------------------------------------------------------------------
    |
    | The default channel ID for notifications.
    |
    */
    'default_channel_id' => env('FIREBASE_DEFAULT_CHANNEL_ID', 'fcm_default_channel'),
    
    /*
    |--------------------------------------------------------------------------
    | FCM Default Sound
    |--------------------------------------------------------------------------
    |
    | The default sound for notifications.
    |
    */
    'default_sound' => env('FIREBASE_DEFAULT_SOUND', 'default'),
    
    /*
    |--------------------------------------------------------------------------
    | FCM Default Notification Icon
    |--------------------------------------------------------------------------
    |
    | The default notification icon for Android.
    |
    */
    'default_notification_icon' => env('FIREBASE_DEFAULT_NOTIFICATION_ICON', 'notification_icon'),
    
    /*
    |--------------------------------------------------------------------------
    | FCM Default Notification Color
    |--------------------------------------------------------------------------
    |
    | The default notification color for Android.
    |
    */
    'default_notification_color' => env('FIREBASE_DEFAULT_NOTIFICATION_COLOR', '#FF0000'),
    
    /*
    |--------------------------------------------------------------------------
    | FCM Default Click Action
    |--------------------------------------------------------------------------
    |
    | The default click action for notifications.
    |
    */
    'default_click_action' => env('FIREBASE_DEFAULT_CLICK_ACTION', 'FLUTTER_NOTIFICATION_CLICK'),
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
