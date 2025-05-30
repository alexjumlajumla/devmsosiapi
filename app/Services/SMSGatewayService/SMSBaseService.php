<?php

namespace App\Services\SMSGatewayService;

use App\Models\Settings;
use App\Models\SmsCode;
use App\Models\SmsGateway;
use App\Models\SmsPayload;
use App\Services\CoreService;
use Illuminate\Support\Str;

class SMSBaseService extends CoreService
{
    /**
     * @return string
     */
    protected function getModelClass(): string
    {
        return SmsGateway::class;
    }

    /**
     * @param $phone
     * @param $message
     * @return array
     */
    public function smsGateway($phone, $message = null): array
    {
        try {
            // Initialize OTP if not provided
            $otp = null;
            
            if (empty($message)) {
                $otp = $this->setOTP();
                $message = "Confirmation code: " . $otp["otpCode"];
                \Log::debug('Generated OTP for SMS', [
                    'phone' => $phone,
                    'verifyId' => $otp['verifyId'],
                    'otpCode' => $otp['otpCode']
                ]);
            }

            // Get SMS gateway configuration
            $smsPayload = SmsPayload::where('default', 1)->first();

            if (!$smsPayload) {
                $error = 'SMS gateway is not configured';
                \Log::error($error);
                return ['status' => false, 'message' => $error];
            }
            
            \Log::debug('Sending SMS via gateway', [
                'gateway' => $smsPayload->type,
                'phone' => $phone,
                'message_length' => strlen($message)
            ]);
            
            // Route to the appropriate SMS service
            $result = match ($smsPayload->type) {
                SmsPayload::MOBISHASTRA => (new MobishastraService)->sendSms($phone, $message),
                SmsPayload::FIREBASE => $otp ? (new TwilioService)->sendSms($phone, $otp, $smsPayload) : ['status' => false, 'message' => 'OTP not generated'],
                SmsPayload::TWILIO => $otp ? (new TwilioService)->sendSms($phone, $otp, $smsPayload) : ['status' => false, 'message' => 'OTP not generated'],
                default => ['status' => false, 'message' => 'Invalid SMS gateway type']
            };
            
            \Log::debug('SMS gateway response', [
                'status' => $result['status'] ?? null,
                'message' => $result['message'] ?? 'No message',
                'gateway' => $smsPayload->type
            ]);
            
            if (!data_get($result, 'status')) {
                $error = 'SMS gateway error: ' . ($result['message'] ?? 'Unknown error');
                \Log::error($error, ['phone' => $phone]);
                return ['status' => false, 'message' => $error];
            }
            
            // Store OTP in cache if we generated one
            if ($otp) {
                $this->setOTPToCache($phone, $otp);
                \Log::debug('OTP stored in cache', [
                    'phone' => $phone,
                    'verifyId' => $otp['verifyId']
                ]);
            }

            return [
                'status' => true,
                'verifyId' => data_get($otp, 'verifyId'),
                'phone' => Str::mask($phone, '*', -12, 8),
                'message' => data_get($result, 'message', '')
            ];
        } catch (\Exception $e) {
            \Log::error('SMS Gateway Exception', [
                'phone' => $phone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'status' => false,
                'message' => 'Failed to send SMS: ' . $e->getMessage()
            ];
        }
    }

    public function setOTP(): array
    {
        return ['verifyId' => Str::uuid(), 'otpCode' => rand(100000, 999999)];
    }

    public function setOTPToCache($phone, $otp)
    {
        $verifyId  = data_get($otp, 'verifyId');
        $expiredAt = Settings::where('key', 'otp_expire_time')->first()?->value;
        
        SmsCode::create([
            'phone'     => $phone,
            'verifyId'  => $verifyId,
            'OTPCode'   => data_get($otp, 'otpCode'),
            'expiredAt' => now()->addMinutes($expiredAt >= 1 ? $expiredAt : 10),
        ]);
    }
}
