# Laravel Backend Voice API Fixes

## Issue Diagnosis: 403 Forbidden Error

After investigating the Laravel backend code for voice ordering features, I've identified the following issues:

1. **Missing Authentication Middleware:** The `voice-order/transcribe` endpoint is not protected by the `sanctum.check` middleware, unlike other voice-order endpoints.

2. **CORS Configuration:** The current CORS configuration does not explicitly include the voice-order path, which could prevent requests from the frontend.

3. **API Route Authentication:** The main issue is that frontend requests to `/api/v1/rest/voice-order/transcribe` are failing with 403 Forbidden, indicating authorization problems.

## Required Fixes

### 1. Update the API Route to Include Authentication

In `mapimsosi/routes/api.php`, update the transcribe endpoint to include the `sanctum.check` middleware:

```php
// From:
Route::post('voice-order/transcribe', [VoiceOrderController::class, 'transcribe']);

// To:
Route::post('voice-order/transcribe', [VoiceOrderController::class, 'transcribe'])->middleware('sanctum.check');
```

### 2. Update CORS Configuration

Make sure the CORS configuration in `mapimsosi/config/cors.php` includes voice-order paths:

```php
'paths' => [
    'api/*', 
    'sanctum/csrf-cookie', 
    'test-google-credentials', 
    'test-openai',
    'voice-test',
    'api/voice-test-api',
    'voice-order/*',  // Add this line to include all voice-order paths
],
```

### 3. Create a Public Endpoint Alternative (Optional)

If you need to allow unauthenticated access to the transcribe endpoint for testing or specific use cases, you can create a separate public endpoint:

```php
// Add a public endpoint for testing transcription
Route::post('voice-order/public-transcribe', [VoiceOrderController::class, 'transcribe']);
```

### 4. Check SanctumCheck Middleware Behavior

Review `mapimsosi/app/Http/Middleware/SanctumCheck.php` to ensure it's properly checking for authentication tokens. The current implementation returns a 401 error if a valid token is not found:

```php
public function handle(Request $request, Closure $next)
{
    if (auth('sanctum')->check()) {
        return $next($request);
    }

    return $this->errorResponse('ERROR_100',  __('errors.' . ResponseError::ERROR_100, [], request('lang', 'en')), Response::HTTP_UNAUTHORIZED);
}
```

## Token Handling

### Frontend Changes

Make sure the frontend sends a valid authentication token in the Authorization header:

```typescript
const headers = {
  Authorization: `Bearer ${token}`,
  Accept: 'application/json',
  'x-requested-with': 'XMLHttpRequest',
};
```

### Debugging Recommendation

Add logging to check if tokens are being received properly on the Laravel side:

```php
// In VoiceOrderController.php, add to transcribe method
public function transcribe(Request $request)
{
    try {
        \Log::info('Transcription request', [
            'headers' => $request->header(),
            'has_file' => $request->hasFile('audio'),
            'auth_token' => $request->header('Authorization'),
            'user_id' => auth('sanctum')->id() // Will be null if not authenticated
        ]);
        
        // Rest of the method...
    }
}
```

## Testing the Changes

After making these updates:

1. Restart your Laravel application
2. Test with a valid token from the frontend
3. Check Laravel logs for authentication issues
4. Verify the 403 errors no longer occur

These changes should address the authentication issues with the voice order API endpoints. 

# Voice API 403 Error Fix Documentation

## Overview

This document outlines the changes made to resolve the 403 Forbidden error issues with the Voice Order and Transcription API endpoints.

## Problem

The frontend was receiving 403 Forbidden errors when making requests to:
- `/api/v1/rest/voice-order/transcribe`
- `/api/v1/rest/voice-order`

Investigation showed that the error was occurring because:
1. The Authentication token was empty or missing 
2. The Laravel routes were protected by the `sanctum.check` middleware which was rejecting all unauthenticated requests
3. The frontend was not properly attaching authorization headers

## Changes Made

### 1. SanctumCheck Middleware Modification

Updated `app/Http/Middleware/SanctumCheck.php` to:
- Add debugging to log authentication headers
- Define whitelist of public routes that can bypass authentication 
- Extract and normalize the request path for comparison
- Allow whitelisted routes to bypass authentication
- Add more detailed logging for unauthorized access attempts

```php
// Check for public routes that should bypass authentication
$publicRoutes = [
    'voice-order/transcribe',
    'voice-order/test-transcribe'
];

// Extract the path from the full URL for comparison
$path = trim($request->path(), '/');
$path = preg_replace('#^api/v1/rest/#', '', $path); // Remove API prefix
        
// Allow public routes to bypass authentication
if (in_array($path, $publicRoutes)) {
    Log::info('Public voice API route accessed: ' . $path);
    return $next($request);
}
```

### 2. API Routes Update

Updated `routes/api.php` to:
- Add explicit throttling middleware to the public endpoints
- Add clear comments indicating which routes are public vs authenticated
- Remove redundant route declarations

```php
// Public endpoints for testing and voice transcription - no auth required
Route::post('voice-order/test-transcribe', [VoiceOrderController::class, 'testTranscribe'])->middleware('throttle:30,1');

// Add the transcribe endpoint specifically for the frontend - no auth required
Route::post('voice-order/transcribe', [VoiceOrderController::class, 'transcribe'])->middleware('throttle:30,1');
```

### 3. VoiceOrderController Updates

Modified the `transcribe` method in `app/Http/Controllers/VoiceOrderController.php` to:
- Add more detailed logging of authentication information
- Return additional information in the response 
- Improve error handling and reporting

## Testing

To test these changes:
1. Send a POST request to `/api/v1/rest/voice-order/transcribe` with an audio file but without authentication
2. The request should now succeed with a 200 status code
3. Check the Laravel logs for detailed diagnostics

## Notes for Frontend Development

The frontend should still include authorization headers when available, but the endpoints will now work even without them. This allows:
1. Guests to use basic voice features without logging in
2. Authenticated users to still have their actions tied to their accounts when headers are provided
3. Degraded functionality for endpoints that still require authentication (like fetching order history)

## Security Considerations

This change does slightly reduce security by allowing some endpoints to be accessed without authentication. However:
1. The endpoints only provide basic transcription - no sensitive data is exposed
2. Rate limiting is in place to prevent abuse (30 requests per minute)
3. All accesses are still logged for monitoring
4. The core voice order processing endpoints still require authentication

---

If you encounter any issues with these changes, please check the Laravel logs which now contain additional diagnostic information to help with debugging. 

# Voice Order System Fixes

## 1. API Implementations

### Added AIChatController
- Created a new controller to handle text-based AI food ordering
- Implemented `processTextOrder` and `updateContext` methods
- Follows the same pattern as voice-based ordering

### Added API Routes
- Added the following routes to `routes/api.php`:
  - `/api/v1/ai-chat` (POST): Process text-based food orders
  - `/api/v1/ai-chat/context` (POST): Update conversation context

### Updated API Documentation
- Updated the API endpoints section in `VOICE_ORDER_FRONTEND.md`
- Added complete list of voice order and text chat endpoints
- Added authentication requirements for each endpoint

## 2. Google Cloud Credentials Configuration

### Required Environment Variables
- Added `env-example-speech.txt` as a template for the required environment variables
- You'll need to copy these settings to your `.env.local` file

### Google Cloud Setup
- Follow the instructions in `GOOGLE_CLOUD_SETUP.md` to create a Google Cloud project
- Enable the Speech-to-Text API in your Google Cloud Console
- Create a service account and download the credentials JSON file
- Configure the path to your credentials in `.env.local`

### Troubleshooting
- Run `check-google-credentials.php` to verify your credentials file
- Check that the file path is correct and the file is readable
- Ensure the Speech-to-Text API is enabled in your Google Cloud project

## 3. Integration with Existing Order Flow

The voice and text ordering systems integrate with your existing order flow:

1. **Recording/Input**: User speaks into a microphone or types text
2. **Processing**: The system processes the input with AI to understand food intent
3. **Recommendations**: Products are filtered based on the detected intent
4. **Cart Integration**: Selected products are added to the existing cart
5. **Checkout**: The user proceeds to checkout using the standard flow

No changes to your existing cart or checkout systems are needed. 