# OpenAI API Setup Guide

This project uses OpenAI's API for various AI-powered features including voice processing and food recommendations. Follow this guide to set up your API key properly.

## Getting an API Key

1. Create an account on [OpenAI's platform](https://platform.openai.com/signup)
2. Navigate to [API Keys](https://platform.openai.com/account/api-keys)
3. Click "Create new secret key"
4. Copy your new API key (you won't be able to see it again!)

## Configuring Your Environment

1. In your project root directory, make sure you have a `.env` or `.env.local` file
2. Add the following line to your file:
   ```
   OPENAI_API_KEY=your_api_key_here
   ```
3. Replace `your_api_key_here` with the API key you copied from OpenAI
4. After updating your environment file, clear the config cache with:
   ```
   php artisan config:clear
   ```

## Recent Updates

This application now uses `orhanerday/open-ai` PHP library (version 5.3), which has been updated to support:

- The latest chat completion API formats
- All modern OpenAI models like `gpt-3.5-turbo` and `gpt-4`
- Improved error handling and response formatting

The legacy `completion()` method with instruct models has been replaced with the more modern `chat()` method format throughout the codebase.

## Available OpenAI Test Endpoints

The following endpoints are available for testing your OpenAI integration:

1. **Test API Key**: `api/v1/test-openai-key` (POST)
   - Validates your OpenAI API key
   - Example response: `{"success":true,"message":"API key is valid","valid":true,"model":"gpt-3.5-turbo"}`

2. **Test Chat**: `api/v1/openai-chat` (GET or POST)
   - GET: Performs a simple test chat with OpenAI
   - POST: Sends your custom messages to OpenAI
   - POST body example:
     ```json
     {
       "messages": [
         {"role": "system", "content": "You are a helpful assistant."},
         {"role": "user", "content": "Hello, how are you?"}
       ]
     }
     ```

3. **Integration Test**: `api/v1/test-openai-integration` (GET)
   - Quick test of OpenAI integration that works from a browser

4. **Debug Configuration**: `api/v1/debug-openai-config` (GET)
   - Shows information about your OpenAI configuration
   - Does not use the API, only checks local settings

## Troubleshooting Common Issues

### Quota Exceeded Error

If you see: `API key validation failed: You exceeded your current quota, please check your plan and billing details.`

**Solution:**
1. Check your account's [billing status](https://platform.openai.com/account/billing)
2. Add a payment method if you don't have one
3. Consider upgrading your plan for higher usage limits
4. Check if you have exceeded your monthly budget

### Invalid API Key Error

If you see: `API key validation failed: Incorrect API key provided`

**Solution:**
1. Ensure you've copied the full API key correctly
2. Check for extra spaces or newlines in your key
3. Verify the key format - it should start with `sk-` (older keys) or `sk-org-` (newer keys)
4. Try generating a new API key
5. Make sure you're using the key in the correct environment where it was created

### API Key Format

OpenAI has different types of API key formats:
- Regular keys start with `sk-` (e.g., `sk-ABC123...`)
- Organization keys start with `sk-org-` (e.g., `sk-org-ABC123...`)

All key types should work with our updated API client.

### Configuration Cache Issues

If you've updated your `.env` or `.env.local` file but the application is still not recognizing your API key:

1. Clear the Laravel configuration cache:
   ```
   php artisan config:clear
   ```
2. Restart your web server or application
3. If using Laravel Sail/Docker:
   ```
   ./vendor/bin/sail down
   ./vendor/bin/sail up -d
   ```

### HTTP Method Not Allowed Error

If you see: `The GET method is not supported for this route. Supported methods: POST.`

**Solution:**
1. Make sure you're using the correct HTTP method for the endpoint
2. For the `/openai-chat` endpoint, both GET and POST methods are now supported
3. Check if your frontend code is using the correct method

## Testing Your API Key

You can test if your API key is working properly by:

1. In your browser, navigate to: `/api/v1/test-openai-key` (this will use the key in your .env file)
2. Or provide a key directly: `/api/v1/test-openai-key?api_key=sk-YOUR_KEY_HERE`
3. You can also run our test script directly: `php test-openai-direct.php`
4. Try the integration test endpoint in your browser: `/api/v1/test-openai-integration`

A successful response means your OpenAI integration is working correctly.

## Understanding Different OpenAI Models

When using the OpenAI API in this application, it's important to understand the difference between model types:

### Chat Models vs. Instruct Models

1. **Chat Models** (e.g., `gpt-3.5-turbo`, `gpt-4`)
   - Used with the chat completions API endpoint
   - Require message format with roles (system, user, assistant)
   - Best for conversational applications
   - Example: `gpt-3.5-turbo`

2. **Instruct Models** (e.g., `gpt-3.5-turbo-instruct`)
   - Used with the completions API endpoint
   - Take a single prompt text input
   - Often used for simpler completion tasks
   - Example: `gpt-3.5-turbo-instruct`

Our application now automatically detects the model type and uses the appropriate API endpoint. If you want to use the `gpt-3.5-turbo-instruct` model, our backend will automatically use the completions API instead of the chat API.

### Model Selection in API Requests

When making requests to the `/api/v1/openai-chat` endpoint, you can specify the model:

**For chat models:**
```json
{
  "model": "gpt-3.5-turbo",
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "Hello, how are you?"}
  ]
}
```

**For instruct models:**
```json
{
  "model": "gpt-3.5-turbo-instruct",
  "messages": [
    {"role": "user", "content": "Write a short poem about technology."}
  ]
}
```

The application will detect the "instruct" in the model name and route your request accordingly. 