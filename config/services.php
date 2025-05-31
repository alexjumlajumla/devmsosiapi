<?php

return [
    // ... existing code ...

    'vfd' => [
        // VFD API Configuration - Sandbox
        'base_url' => env('VFD_BASE_URL', 'https://vfd-sandbox.mojatax.com/'),
        'api_key' => env('VFD_API_KEY', 'sandbox_test_key_123456'),
        'tin' => env('VFD_TIN', '123456789'),
        'cert_path' => env('VFD_CERT_PATH', storage_path('certs/vfd/sandbox_cert.pem')),
        'sandbox' => env('VFD_SANDBOX', true),
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