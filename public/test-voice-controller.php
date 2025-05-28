<?php

/**
 * Simple test script to diagnose validation issues with VoiceOrderController
 */

// Bootstrap Laravel
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Set headers
header('Content-Type: application/json');

try {
    // Get the VoiceOrderController
    $controller = app(\App\Http\Controllers\VoiceOrderController::class);
    
    // Create a mock request with the minimum required fields
    $request = new \Illuminate\Http\Request();
    $request->replace([
        'session_id' => 'test-' . uniqid(),
        'language' => 'en-US',
        'text' => 'This is a test message for the voice order system',
        'dialogue_state' => json_encode([
            'step' => 'initial',
            'cart' => [],
            'selected_payment' => null,
            'currency' => [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'rate' => 1.0
            ]
        ]),
        'delivery_type' => 'delivery'
    ]);
    
    // Get validator rules from controller if available
    $rules = [];
    if (method_exists($controller, 'rules')) {
        $rules = $controller->rules($request);
    } else if (method_exists($controller, 'validationRules')) {
        $rules = $controller->validationRules($request);
    }
    
    // Validate the request manually
    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $rules);
    
    if ($validator->fails()) {
        // If validation fails, show the errors
        echo json_encode([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $validator->errors()->toArray(),
            'request_data' => $request->all()
        ], JSON_PRETTY_PRINT);
    } else {
        // Validation passed, try to call the controller directly
        try {
            $response = $controller->processVoiceOrder($request);
            
            // Get the response data
            $responseData = $response->getData(true);
            
            echo json_encode([
                'success' => true,
                'controller_response' => $responseData,
                'request_data' => $request->all()
            ], JSON_PRETTY_PRINT);
        } catch (\Exception $e) {
            // If controller throws an exception, show it
            echo json_encode([
                'success' => false,
                'message' => 'Controller error: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request_data' => $request->all()
            ], JSON_PRETTY_PRINT);
        }
    }
} catch (\Exception $e) {
    // Show any errors
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ], JSON_PRETTY_PRINT);
} 