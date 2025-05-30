<?php

declare(strict_types=1);

namespace App\Services\SMSGatewayService;

use App\Models\SmsPayload;
use App\Services\CoreService;
use Exception;
class MobishastraService extends CoreService
{
    protected function getModelClass(): string
    {
        return SmsPayload::class;
    }

    /**
     * Send regular SMS message
     */
    public function sendSMS($phone, $message): array
    {   
        $smsPayload = SmsPayload::where('default', 1)->first();
        if (!$smsPayload) {
            return ['status' => false, 'message' => 'Default SMS payload not found'];
        }
        
        return $this->processSmsSending($phone, $message, $smsPayload);
    }

    /**
     * Send OTP specific message
     */
    public function sendOtp($phone, $otp): array
    {   
        if (is_array($otp)) {
            $otpCode = data_get($otp, 'otpCode');
            $message = "Confirmation code $otpCode";
        } else {
            $message = $otp; // Direct message for order notifications
        }

        $smsPayload = SmsPayload::where('default', 1)->first();
        if (!$smsPayload) {
            return ['status' => false, 'message' => 'Default SMS payload not found'];
        }
        
        return $this->processSmsSending($phone, $message, $smsPayload);
    }

    /**
     * Process the actual SMS sending
     */
    public function processSmsSending($phone, $message, $smsPayload = null)
    {
        $logContext = [
            'phone' => $phone,
            'message_length' => strlen($message),
            'payload_id' => $smsPayload->id ?? null
        ];

        try {
            if (!$smsPayload) {
                throw new Exception('SMS payload configuration is missing', 500);
            }

            $accountId = data_get($smsPayload->payload, 'mobishastra_user');
            $password = data_get($smsPayload->payload, 'mobishastra_password');
            $senderID = data_get($smsPayload->payload, 'mobishastra_sender_id');

            // Validate configuration
            if (empty($accountId) || empty($password) || empty($senderID)) {
                throw new Exception('Incomplete SMS gateway configuration', 500);
            }

            // Clean and validate phone number
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (strlen($phone) < 7) {
                throw new Exception('Invalid phone number: ' . $phone, 400);
            }

            // Format the request URL
            $request = "?user=" . $accountId . 
                     "&pwd=" . $password . 
                     "&senderid=" . $senderID . 
                     "&mobileno=" . $phone . 
                     "&msgtext=" . urlencode($message) . 
                     "&priority=High&CountryCode=ALL";
            
            // Log sanitized request (without sensitive data)
            $logContext['request'] = [
                'sender_id' => $senderID,
                'phone' => substr($phone, 0, 5) . '****',
                'message_length' => strlen($message),
                'has_credentials' => !empty($accountId) && !empty($password)
            ];
            
            \Log::info('Sending SMS via Mobishastra', $logContext);
            
            // Send the request
            $startTime = microtime(true);
            $response = $this->send_get_request($request);
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Log the response
            $logContext['response'] = $response;
            $logContext['response_time_ms'] = $responseTime;
            
            if (empty($response)) {
                throw new Exception('Empty response from SMS gateway', 500);
            }
            
            // Check for known error responses
            if (strpos($response, 'Error') === 0) {
                throw new Exception('SMS gateway error: ' . $response, 500);
            }
            
            \Log::info('SMS sent successfully', $logContext);
            return ['status' => true, 'message' => 'SMS sent successfully'];

        } catch (Exception $e) {
            $errorContext = array_merge($logContext, [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'exception' => get_class($e)
            ]);
            
            \Log::error('Failed to send SMS', $errorContext);
            
            // Return a user-friendly error message
            $userMessage = $e->getCode() === 400 ? 
                'Invalid phone number' : 
                'Failed to send SMS. Please try again later.';
                
            return [
                'status' => false, 
                'message' => $userMessage,
                'debug' => config('app.debug') ? $e->getMessage() : null
            ];
        }
    }

    /**
     * Send HTTP GET request to the SMS gateway
     * 
     * @param string $request The request parameters
     * @return string The raw response from the server
     * @throws Exception If the request fails
     */
    protected function send_get_request($request) {
        $api_endpoint = "https://mshastra.com/sendurlcomma.aspx";
        $url = $api_endpoint . $request;
        
        // Log sanitized URL (without sensitive data)
        $logUrl = $api_endpoint . '?' . http_build_query([
            'user' => '***',
            'pwd' => '***',
            'senderid' => parse_url($url, PHP_URL_QUERY) ? 
                (preg_match('/senderid=([^&]+)/', $url, $matches) ? $matches[1] : '***') : '***',
            'mobileno' => '***' . substr(parse_url($url, PHP_URL_QUERY), -4),
            'msgtext' => '***',
            'priority' => 'High',
            'CountryCode' => 'ALL'
        ]);
        
        \Log::debug('SMS API Request', ['url' => $logUrl]);
        
        // Initialize cURL
        $ch = curl_init();
        
        // Set cURL options
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30, // 30 seconds timeout
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: text/plain'
            ]
        ];
        
        curl_setopt_array($ch, $options);
        
        // Execute the request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Close cURL resource
        curl_close($ch);
        
        // Log the response status
        \Log::debug('SMS API Response', [
            'http_code' => $httpCode,
            'response' => $response,
            'error' => $error ?: null
        ]);
        
        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: " . $httpCode);
        }
        
        return $response;
    }
}