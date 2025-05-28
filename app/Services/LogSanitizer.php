<?php

namespace App\Services;

class LogSanitizer
{
    /**
     * Keys that should be completely masked in logs
     */
    protected static array $maskCompletely = [
        'password', 'pwd', 'secret', 'token', 'key', 'auth_token', 'authorization', 
        'twilio_auth_token', 'twilio_account_id', 'api_key', 'private_key',
        'firebase_token'
    ];
    
    /**
     * Keys that should be partially masked in logs (show first/last few characters)
     */
    protected static array $maskPartially = [
        'phone', 'email', 'mobileno', 'user', 'senderid'
    ];
    
    /**
     * Apply sanitization to log data
     */
    public static function sanitize($data): mixed
    {
        if (is_string($data)) {
            return self::sanitizeString($data);
        }
        
        if (is_array($data)) {
            return self::sanitizeArray($data);
        }
        
        if (is_object($data) && method_exists($data, 'toArray')) {
            $array = $data->toArray();
            return self::sanitizeArray($array);
        }
        
        return $data;
    }
    
    /**
     * Sanitize an array of values
     */
    protected static function sanitizeArray(array $data): array
    {
        foreach ($data as $key => $value) {
            // Recursively sanitize nested arrays
            if (is_array($value)) {
                $data[$key] = self::sanitizeArray($value);
                continue;
            }
            
            // Skip non-string values that can't be masked
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            
            $lowerKey = strtolower((string)$key);
            
            // Check if this should be completely masked
            foreach (self::$maskCompletely as $pattern) {
                if (stripos($lowerKey, $pattern) !== false) {
                    $data[$key] = '********';
                    continue 2; // Skip to next key
                }
            }
            
            // Check if this should be partially masked
            foreach (self::$maskPartially as $pattern) {
                if (stripos($lowerKey, $pattern) !== false) {
                    $data[$key] = self::maskValue((string)$value);
                    continue 2; // Skip to next key
                }
            }
            
            // If the value is a URL, sanitize it
            if (is_string($value) && (stripos($value, 'http') === 0)) {
                $data[$key] = self::sanitizeUrl($value);
            }
        }
        
        return $data;
    }
    
    /**
     * Sanitize a string that might contain sensitive data
     */
    protected static function sanitizeString(string $data): string
    {
        // Check if this is a URL (common in logs)
        if (stripos($data, 'http') === 0) {
            return self::sanitizeUrl($data);
        }
        
        // If it contains JSON, attempt to decode, sanitize, and re-encode
        if (stripos($data, '{') !== false) {
            $json = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $sanitized = self::sanitizeArray($json);
                return json_encode($sanitized);
            }
        }
        
        // Check for common patterns of sensitive data
        $patterns = [
            // Password in URL
            '/(pwd|password)=([^&]+)/i' => '$1=********',
            
            // API keys in URL
            '/(api[_-]?key|key|token)=([^&]+)/i' => '$1=********',
            
            // Authorization headers
            '/(Bearer|Basic|auth_token)\s+([^\s]+)/i' => '$1 ********',
            
            // Credit card numbers (simple pattern that matches 14-19 digits with optional spaces/dashes)
            '/\b(?:\d[ -]*?){14,19}\b/' => '[MASKED_CARD_NUMBER]',
            
            // Phone numbers - allow showing first 3 digits
            '/\b(\d{3})[- ]?\d{3}[- ]?\d{4}\b/' => '$1-***-****',
        ];
        
        return preg_replace(array_keys($patterns), array_values($patterns), $data);
    }
    
    /**
     * Sanitize a URL to remove sensitive query parameters
     */
    protected static function sanitizeUrl(string $url): string
    {
        $parsedUrl = parse_url($url);
        
        if (!isset($parsedUrl['query'])) {
            return $url;
        }
        
        parse_str($parsedUrl['query'], $queryParams);
        
        // Sanitize the query parameters
        $queryParams = self::sanitizeArray($queryParams);
        
        // Rebuild the URL
        $sanitizedQuery = http_build_query($queryParams);
        
        // Remove the original query string and append the sanitized one
        $baseUrl = str_replace('?' . $parsedUrl['query'], '', $url);
        return $baseUrl . '?' . $sanitizedQuery;
    }
    
    /**
     * Mask a value, showing only first and last characters
     */
    protected static function maskValue(string $value): string
    {
        $length = strlen($value);
        
        if ($length <= 5) {
            return '********';
        }
        
        // Show first 2 and last 2 characters
        return substr($value, 0, 2) . str_repeat('*', $length - 4) . substr($value, -2);
    }
} 