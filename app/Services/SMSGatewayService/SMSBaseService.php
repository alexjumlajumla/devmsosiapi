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
        if(empty($message)){
            $otp = $this->setOTP();
            $message = "Confirmation code : ".$otp["otpCode"];
        }else {
            $message = $message;
        }
        $smsPayload = SmsPayload::where('default', 1)->first();

        if (!$smsPayload) {
            return ['status' => false, 'message' => 'sms is not configured!'];
        }
        
        $result = match ($smsPayload->type) {
            SmsPayload::MOBISHASTRA => (new MobishastraService)->sendSms($phone, $message),
            SmsPayload::FIREBASE => (new TwilioService)->sendSms($phone, $otp, $smsPayload),
            SmsPayload::TWILIO => (new TwilioService)->sendSms($phone, $otp, $smsPayload),
            default => ['status' => false, 'message' => 'Invalid SMS gateway type']
        };
        info(json_encode($result));
        if (!data_get($result, 'status')) {
            return ['status' => false, 'message' => data_get($result, 'message')];
        }
        info("message");
        info($message);
        if (!empty($message)) {
            info("otp");
            $this->setOTPToCache($phone, $otp);
        }

        return [
            'status' => true,
            'verifyId' => data_get($otp, 'verifyId'),
            'phone' => Str::mask($phone, '*', -12, 8),
            'message' => data_get($result, 'message', '')
        ];
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
