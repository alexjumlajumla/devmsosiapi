<?php

return [
    // ... existing code ...

    'vfd' => [
        // API Settings
        'base_url' => env('VFD_BASE_URL', 'https://api.vfd.tz'),
        'api_key' => env('VFD_API_KEY'),
        'tin' => env('VFD_TIN'),
        'cert_path' => env('VFD_CERT_PATH', storage_path('certs/vfd/cert.pem')),
        'timeout' => 30,
        'retry_attempts' => 3,
        'retry_delay' => 5, // seconds
        
        // Archive Settings
        'archive_enabled' => env('VFD_ARCHIVE_ENABLED', false),
        'archive_endpoint' => env('VFD_ARCHIVE_ENDPOINT'),
        'archive_api_key' => env('VFD_ARCHIVE_API_KEY'),
        'archive_verify_ssl' => env('VFD_ARCHIVE_VERIFY_SSL', true),
        
        // Notification Settings
        'notification_sms_enabled' => env('VFD_NOTIFICATION_SMS_ENABLED', true),
        'notification_email_enabled' => env('VFD_NOTIFICATION_EMAIL_ENABLED', false),
        'notification_email_address' => env('VFD_NOTIFICATION_EMAIL_ADDRESS'),
    ],
    
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-3.5-turbo'),
        'temperature' => env('OPENAI_TEMPERATURE', 0.7),
    ],

    'google' => [
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
        'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
    ],

    // ... existing code ...
]; 