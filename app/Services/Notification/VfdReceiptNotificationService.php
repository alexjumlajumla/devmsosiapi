<?php

namespace App\Services\Notification;

use App\Models\VfdReceipt;
use App\Services\SMSGatewayService\MobishastraService;
use App\Services\SMSGatewayService\TwilioService;
use Illuminate\Support\Facades\Log;

class VfdReceiptNotificationService
{
    protected $mobishastraService;
    protected $twilioService;

    public function __construct(
        MobishastraService $mobishastraService,
        TwilioService $twilioService
    ) {
        $this->mobishastraService = $mobishastraService;
        $this->twilioService = $twilioService;
    }

    /**
     * Send receipt via SMS
     *
     * @param VfdReceipt $receipt
     * @return bool
     */
    public function sendReceiptSms(VfdReceipt $receipt): bool
    {
        if (empty($receipt->customer_phone) || empty($receipt->receipt_url)) {
            Log::warning('Cannot send SMS: Missing phone number or receipt URL', [
                'receipt_id' => $receipt->id,
                'has_phone' => !empty($receipt->customer_phone),
                'has_url' => !empty($receipt->receipt_url),
            ]);
            return false;
        }

        $message = $this->generateReceiptSms($receipt);
        $phone = $this->formatPhoneNumber($receipt->customer_phone);

        try {
            // Try Mobishastra first (or your primary SMS provider)
            $result = $this->mobishastraService->sendSms($phone, $message);
            
            if ($result['status'] ?? false) {
                Log::info('Receipt SMS sent via Mobishastra', [
                    'receipt_id' => $receipt->id,
                    'phone' => $phone,
                ]);
                return true;
            }
            
            // Fallback to Twilio if Mobishastra fails
            Log::warning('Mobishastra SMS failed, trying Twilio', [
                'receipt_id' => $receipt->id,
                'error' => $result['message'] ?? 'Unknown error',
            ]);
            
            $twilioResult = $this->twilioService->sendSms($phone, null, null, $message);
            
            if ($twilioResult['status'] ?? false) {
                Log::info('Receipt SMS sent via Twilio', [
                    'receipt_id' => $receipt->id,
                    'phone' => $phone,
                ]);
                return true;
            }
            
            Log::error('Failed to send receipt SMS via all providers', [
                'receipt_id' => $receipt->id,
                'phone' => $phone,
                'mobishastra_error' => $result['message'] ?? 'Unknown error',
                'twilio_error' => $twilioResult['message'] ?? 'Unknown error',
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            Log::error('Exception while sending receipt SMS', [
                'receipt_id' => $receipt->id,
                'phone' => $phone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return false;
        }
    }

    /**
     * Generate the SMS message for a receipt
     *
     * @param VfdReceipt $receipt
     * @return string
     */
    protected function generateReceiptSms(VfdReceipt $receipt): string
    {
        $amount = number_format($receipt->amount / 100, 2);
        $type = $receipt->receipt_type === 'delivery' ? 'Delivery' : 'Subscription';
        
        return "Your {$type} receipt #{$receipt->receipt_number} for TZS {$amount} is ready. View it at: {$receipt->receipt_url}";
    }

    /**
     * Format phone number for SMS
     * 
     * @param string $phone
     * @return string
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Add country code if missing
        if (strlen($phone) === 9 && !str_starts_with($phone, '0')) {
            $phone = '255' . $phone;
        } elseif (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            $phone = '255' . substr($phone, 1);
        }
        
        return $phone;
    }
}
