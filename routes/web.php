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
