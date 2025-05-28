<?php
/**
 * Simple test script to verify the voice order API endpoint is working
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set response headers
header('Content-Type: application/json');

try {
    // Create cURL request to test endpoints
    $endpoints = [
        'voice_order' => '/api/v1/voice-order',
        'test_transcribe' => '/api/v1/voice-order/test-transcribe'
    ];
    
    $results = [];
    
    // Test each endpoint
    foreach ($endpoints as $name => $endpoint) {
        $ch = curl_init();
        
        // Full URL to the endpoint (using current host)
        $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                "://$_SERVER[HTTP_HOST]$endpoint";
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        // Add proper JSON data
        $requestData = [
            'test' => true,
            'language' => 'en-US',
            'timestamp' => time(),
            'session_id' => 'test-' . uniqid(),
            'dialogue_state' => json_encode([
                'step' => 'initial',
                'cart' => [],
                'currency' => [
                    'code' => 'USD',
                    'name' => 'US Dollar',
                    'symbol' => '$',
                    'rate' => 1.0
                ]
            ])
        ];
        
        // Format request data based on endpoint
        if ($name == 'test_transcribe') {
            // For test-transcribe, we use simple form-encoded data
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($requestData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'X-Voice-Test: 1'
            ]);
        } else {
            // For voice-order endpoint, we use JSON
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'text' => 'This is a test message for the voice order system',
                'session_id' => 'test-' . uniqid(),
                'language' => 'en-US',
                'dialogue_state' => [
                    'step' => 'initial',
                    'cart' => [],
                    'currency' => [
                        'code' => 'USD',
                        'name' => 'US Dollar',
                        'symbol' => '$', 
                        'rate' => 1.0
                    ]
                ]
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Voice-Test: 1'
            ]);
        }
        
        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        
        curl_close($ch);
        
        // Store the results
        $results[$name] = [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'url' => $url,
            'response' => json_decode($response, true),
            'raw_response' => substr($response, 0, 1000), // First 1000 chars only
            'error' => $error,
            'info' => [
                'total_time' => $info['total_time'],
                'connect_time' => $info['connect_time'],
                'content_type' => $info['content_type'],
                'size_download' => $info['size_download']
            ]
        ];
    }
    
    // Output the results
    echo json_encode([
        'success' => true,
        'endpoints_tested' => array_keys($results),
        'results' => $results,
        'server_info' => [
            'php_version' => PHP_VERSION,
            'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'time' => date('Y-m-d H:i:s'),
            'request_uri' => $_SERVER['REQUEST_URI'],
            'request_method' => $_SERVER['REQUEST_METHOD']
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Return the error
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
} 