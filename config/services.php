<?php

return [
    // ... existing code ...

    'vfd' => [
        'base_url' => env('VFD_BASE_URL', 'https://vfd-api.example.com'),
        'api_key' => env('VFD_API_KEY'),
        'tin' => env('VFD_TIN'),
        'cert_path' => env('VFD_CERT_PATH'),
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