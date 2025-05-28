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
        // Try the environment variable first
        'credentials' => function() {
            $primaryPath = env('GOOGLE_APPLICATION_CREDENTIALS');
            $fallbackPath1 = storage_path('app/google-service-account.json');
            $fallbackPath2 = storage_path('app/jumlajumla-1f0f0-98ab02854aef.json');
            $fallbackPath3 = base_path('jumlajumla-1f0f0-98ab02854aef.json');
            
            // Check if the primary path exists
            if (!empty($primaryPath) && file_exists($primaryPath)) {
                \Log::info("Using Google credentials from env: $primaryPath");
                return $primaryPath;
            }
            
            // Try fallback paths
            if (file_exists($fallbackPath1)) {
                \Log::info("Using fallback Google credentials: $fallbackPath1");
                return $fallbackPath1;
            }
            
            if (file_exists($fallbackPath2)) {
                \Log::info("Using fallback Google credentials: $fallbackPath2");
                return $fallbackPath2;
            }
            
            if (file_exists($fallbackPath3)) {
                \Log::info("Using fallback Google credentials: $fallbackPath3");
                return $fallbackPath3;
            }
            
            // If we get here, no valid path was found
            \Log::warning("No valid Google credentials file found");
            return $primaryPath; // Return the primary path anyway, let the app handle the error
        },
        'project_id' => env('GOOGLE_CLOUD_PROJECT_ID'),
    ],

    // ... existing code ...
]; 