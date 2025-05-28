<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

   'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'voice-order',
        'login',
        'logout'
    ],

    'allowed_methods' => [
        'POST',
        'GET',
        'OPTIONS',
        'PUT',
        'PATCH',
        'DELETE'
    ],

    'allowed_origins' => [
        'https://msosi.jumlajumla.com',
        'https://mapi.jumlajumla.com',
        'https://madmin.jumlajumla.com',
        'https://beta.jumlajumla.com',
        'https://devdash.jumlajumla.com',
        'https://dev.jumlajumla.com',
        'http://localhost:3000',
        'http://localhost:8000' // For local API development
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'Accept',
        'Origin',
        'User-Agent',
        'Referer',
        'X-CSRF-TOKEN',
        'X-Socket-ID'
    ],

    'exposed_headers' => [
        'Authorization',
        'Content-Type',
        'X-Socket-ID',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining'
    ],

    'max_age' => 86400,

    'supports_credentials' => true,
];
