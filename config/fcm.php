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


    // Firebase Project ID
    'project_id' => env('FIREBASE_PROJECT_ID', 'msosijumla'),

    // Firebase API Key
    'api_key' => env('FIREBASE_API_KEY', 'AIzaSyAuIozMv5MEpT94ynxZMSDheuqcSFshXWU'),

    // Firebase Sender ID
    'sender_id' => env('FIREBASE_SENDER_ID', '853837987746'),
    'messaging_sender_id' => env('FIREBASE_MESSAGING_SENDER_ID', '853837987746'),
    'app_id' => env('FIREBASE_APP_ID', '1:853837987746:web:b0d822859c3a2605893cfb'),
    'measurement_id' => env('FIREBASE_MEASUREMENT_ID', 'G-YSYTD7ESL8'),
    'vapid_key' => env('FIREBASE_VAPID_KEY', 'BDO1IfAhx1-yl1pn7DjkBZi3a0NCAoiPsQLDHSqLXoB5QBxEEmxCTqKQNzPdBYtQXSow5KJlrDzzfph8hSkrsxE'),
    'auth_domain' => env('FIREBASE_AUTH_DOMAIN', 'msosijumla.firebaseapp.com'),
    'storage_bucket' => env('FIREBASE_STORAGE_BUCKET', 'msosijumla.appspot.com'),
    'database_url' => env('FIREBASE_DATABASE_URL', 'https://msosijumla-default-rtdb.firebaseio.com'),
    
    // FCM Configuration
    'driver' => 'fcm',
    'key' => env('FCM_SERVER_KEY'),
    'sender_id' => env('FCM_SENDER_ID'),
    'server_key' => env('FCM_SERVER_KEY'),
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'database_url' => env('FIREBASE_DATABASE_URL'),
    'messaging_id' => env('FIREBASE_MESSAGING_SENDER_ID'),
    'storage_bucket' => env('FIREBASE_STORAGE_BUCKET'),
    'app_id' => env('FIREBASE_APP_ID'),
    'measurement_id' => env('FIREBASE_MEASUREMENT_ID'),
    'api_key' => env('FIREBASE_API_KEY'),
    'auth_domain' => env('FIREBASE_AUTH_DOMAIN'),
    'vapid_key' => env('FIREBASE_VAPID_KEY'),
    
    // Service account credentials
    'service_account' => [
        'type' => 'service_account',
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'private_key_id' => env('FIREBASE_PRIVATE_KEY_ID'),
        'private_key' => str_replace('\\n', "\n", env('FIREBASE_PRIVATE_KEY', '')),
        'client_email' => env('FIREBASE_CLIENT_EMAIL'),
        'client_id' => env('FIREBASE_CLIENT_ID'),
        'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri' => 'https://oauth2.googleapis.com/token',
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url' => env('FIREBASE_CLIENT_CERT_URL'),
    ],
    
    // HTTP client configuration
    'http' => [
        'timeout' => 30,
        'retry_delay' => 5000,
        'retries' => 3,
        'verify' => true,
        'http_errors' => true,
    ],
    
    // Logging configuration
    'log_enabled' => true,
    'log_channel' => env('LOG_CHANNEL', 'stack'),
    
    // Default message data
    'message' => [
        'priority' => 'high',
        'content_available' => true,
        'mutable_content' => true,
        'notification' => [
            'sound' => 'default',
            'badge' => 1,
        ],
    ],
    
    // HTTP Settings
    'http_protocol' => env('FIREBASE_HTTP_PROTOCOL', 'https'),
    'http_timeout' => env('FIREBASE_HTTP_TIMEOUT', 30),
    
    // Notification Defaults
    'default_channel_id' => env('FIREBASE_DEFAULT_CHANNEL_ID', 'fcm_default_channel'),
    'default_sound' => env('FIREBASE_DEFAULT_SOUND', 'default'),
    'default_notification_icon' => env('FIREBASE_DEFAULT_NOTIFICATION_ICON', 'notification_icon'),
    'default_notification_color' => env('FIREBASE_DEFAULT_NOTIFICATION_COLOR', '#FF0000'),
    'default_click_action' => env('FIREBASE_DEFAULT_CLICK_ACTION', 'FLUTTER_NOTIFICATION_CLICK'),
    'server_key' => env('FIREBASE_SERVER_KEY'),

    // FCM Configuration
    'config' => [
        'timeout' => 10,
        'retry_attempts' => 2,
        'retry_delay' => 100,
        'max_tokens_per_batch' => 500,
    ],

    // Logging Configuration
    'logging' => [
        'enabled' => env('FCM_LOGGING_ENABLED', true),
        'level' => env('FCM_LOGGING_LEVEL', 'debug'),
        'channel' => env('FCM_LOGGING_CHANNEL', 'stack'),
    ],

    // Cache Configuration
    'cache' => [
        'store' => env('FCM_CACHE_STORE', 'file'),
        'prefix' => 'fcm_',
        'ttl' => 3600, // 1 hour
    ],

    // Default Notification Settings
    'defaults' => [
        'sound' => 'default',
        'priority' => 'high',
        'visibility' => 'private',
        'badge' => 1,
    ],
];
