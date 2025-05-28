<?php

// Bootstrap Laravel's environment
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Laravel Configuration Checker\n";
echo "===========================\n\n";

// Check environment
echo "Environment: " . app()->environment() . "\n";

// Check various ways the OpenAI API key might be configured
echo "\nChecking OpenAI API Key Configuration:\n";
echo "------------------------------------\n";

// 1. Check env directly
echo "1. From env() helper: " . (env('OPENAI_API_KEY') ? substr(env('OPENAI_API_KEY'), 0, 5) . "..." : "Not set") . "\n";

// 2. Check services.openai config
echo "2. From config('services.openai.api_key'): " . (config('services.openai.api_key') ? substr(config('services.openai.api_key'), 0, 5) . "..." : "Not set") . "\n";

// 3. Check all services config
echo "\n3. Complete Services Config:\n";
echo json_encode(config('services'), JSON_PRETTY_PRINT) . "\n";

// 4. Test AIOrderService
echo "\n4. Testing AIOrderService initialization:\n";
echo "------------------------------------\n";
try {
    $aiService = new App\Services\AIOrderService();
    $reflection = new ReflectionClass($aiService);
    $property = $reflection->getProperty('apiInitialized');
    $property->setAccessible(true);
    $apiInitialized = $property->getValue($aiService);
    
    echo "AIOrderService apiInitialized: " . ($apiInitialized ? "TRUE" : "FALSE") . "\n";
    
    if (!$apiInitialized) {
        echo "⚠️ The AIOrderService failed to initialize the OpenAI client.\n";
        echo "This explains why voice orders aren't working correctly.\n";
    } else {
        echo "✅ The AIOrderService successfully initialized the OpenAI client.\n";
    }
} catch (Exception $e) {
    echo "❌ Error testing AIOrderService: " . $e->getMessage() . "\n";
}

// Extra verification for file issues
echo "\nVerifying environment files:\n";
echo "-------------------------\n";
echo ".env exists: " . (file_exists('.env') ? "YES" : "NO") . "\n";
echo ".env.local exists: " . (file_exists('.env.local') ? "YES" : "NO") . "\n";

// Check content of .env file for OPENAI_API_KEY
if (file_exists('.env')) {
    $envContents = file_get_contents('.env');
    echo "\n.env contains OPENAI_API_KEY: " . (strpos($envContents, 'OPENAI_API_KEY=') !== false ? "YES" : "NO") . "\n";
}

if (file_exists('.env.local')) {
    $envLocalContents = file_get_contents('.env.local');
    echo ".env.local contains OPENAI_API_KEY: " . (strpos($envLocalContents, 'OPENAI_API_KEY=') !== false ? "YES" : "NO") . "\n";
}

echo "\nDONE.\n"; 