<?php

use App\Http\Controllers\Web\ConvertController;
use App\Http\Controllers\API\v1\Dashboard\Payment\{MercadoPagoController,
    MollieController,
    PayFastController,
    PayStackController,
    PayTabsController,
    RazorPayController,
    StripeController,
    SelcomController
};
use Illuminate\Support\Facades\Route;
use App\Models\Settings;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

// Debug route for FCM tokens
Route::get('/debug/fcm-token/{userId}', function ($userId) {
    $user = User::find($userId);
    
    if (!$user) {
        return "User not found";
    }
    
    // Get raw token data
    $rawTokenData = $user->firebase_token;
    $tokenType = gettype($rawTokenData);
    
    // Get processed tokens
    $processedTokens = $user->getFcmTokens();
    
    // Log the data
    Log::info('Web Debug - FCM Token', [
        'user_id' => $user->id,
        'email' => $user->email,
        'raw_token_type' => $tokenType,
        'raw_token_sample' => is_string($rawTokenData) ? 
            (strlen($rawTokenData) > 20 ? substr($rawTokenData, 0, 20) . '...' : $rawTokenData) : 
            (is_array($rawTokenData) ? json_encode(array_slice($rawTokenData, 0, 3)) : 'N/A'),
        'processed_tokens_count' => count($processedTokens),
        'is_json' => $tokenType === 'string' && json_decode($rawTokenData) !== null,
    ]);
    
    // Output the data
    echo "<h1>FCM Token Debug - User ID: {$user->id}</h1>";
    echo "<h2>User: {$user->email}</h2>";
    
    echo "<h3>Raw Token Data</h3>";
    echo "<pre>";
    echo "Type: " . $tokenType . "\n";
    echo "Value: " . htmlspecialchars(print_r($rawTokenData, true)) . "\n";
    echo "</pre>";
    
    echo "<h3>Processed Tokens</h3>";
    echo "<pre>";
    print_r($processedTokens);
    echo "</pre>";
    
    echo "<h3>Debug Info</h3>";
    echo "<pre>";
    echo "Is Array: " . (is_array($rawTokenData) ? 'Yes' : 'No') . "\n";
    echo "Is String: " . (is_string($rawTokenData) ? 'Yes' : 'No') . "\n";
    echo "Is Null: " . (is_null($rawTokenData) ? 'Yes' : 'No') . "\n";
    echo "Is Object: " . (is_object($rawTokenData) ? 'Yes' : 'No') . "\n";
    echo "Is Countable: " . (is_countable($rawTokenData) ? 'Yes' : 'No') . "\n";
    if (is_countable($rawTokenData)) {
        echo "Count: " . count($rawTokenData) . "\n";
    }
    echo "</pre>";
    
    die();
});

/*
|--------------------------------------------------------------------------
| Dashboard Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
Route::get('upload-s3', function(){
    
    $isAws = Settings::where('key', 'aws')->first(); 
    if (data_get($isAws, 'value')) {
        $options = ['disk' => 's3'];
    }
    dd($options);
    try{
        $filePath = public_path('test.webp');
        Storage::disk('s3')->put('uploads/file.jpg', file_get_contents($filePath));
    }catch(\Exception $e){
        dd($e->getMessage());
    }

    
});
Route::any('selcom-result',   [SelcomController::class, 'orderResultTransaction']);
Route::any('subscription-selcom-success', [SelcomController::class, 'subscriptionResultTransaction']);

Route::any('order-stripe-success', [StripeController::class, 'orderResultTransaction']);
Route::any('parcel-order-stripe-success', [StripeController::class, 'orderResultTransaction']);
Route::any('subscription-stripe-success', [StripeController::class, 'subscriptionResultTransaction']);

//Route::get('order-paypal-success', [PayPalController::class, 'orderResultTransaction']);
//Route::get('subscription-paypal-success', [PayPalController::class, 'subscriptionResultTransaction']);

Route::get('order-razorpay-success', [RazorPayController::class, 'orderResultTransaction']);
Route::get('subscription-razorpay-success', [RazorPayController::class, 'subscriptionResultTransaction']);

Route::get('order-paystack-success', [PayStackController::class, 'orderResultTransaction']);
Route::get('subscription-paystack-success', [PayStackController::class, 'subscriptionResultTransaction']);

Route::get('order-mercado-pago-success', [MercadoPagoController::class, 'orderResultTransaction']);
Route::get('subscription-mercado-pago-success', [MercadoPagoController::class, 'subscriptionResultTransaction']);

Route::any('order-moya-sar-success', [MollieController::class, 'orderResultTransaction']);
Route::any('subscription-mollie-success', [MollieController::class, 'subscriptionResultTransaction']);

Route::any('order-paytabs-success', [PayTabsController::class, 'orderResultTransaction']);
Route::any('subscription-paytabs-success', [PayTabsController::class, 'subscriptionResultTransaction']);

Route::any('order-pay-fast-success', [PayFastController::class, 'orderResultTransaction']);
Route::any('subscription-pay-fast-success', [PayFastController::class, 'subscriptionResultTransaction']);

Route::get('/', function () {
    return view('welcome');
});

Route::get('convert', [ConvertController::class, 'index'])->name('convert');
Route::post('convert-post', [ConvertController::class, 'getFile'])->name('convertPost');

Route::get('selcom-result', [SelcomController::class, 'orderResultTransaction'])
    ->name('selcom.callback');
