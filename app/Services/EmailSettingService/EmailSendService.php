<?php

namespace App\Services\EmailSettingService;

use App\Helpers\ResponseError;
use App\Models\EmailSetting;
use App\Models\EmailSubscription;
use App\Models\EmailTemplate;
use App\Models\Gallery;
use App\Models\Order;
use App\Models\Settings;
use App\Models\Translation;
use App\Models\User;
use App\Services\CoreService;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Exception;
use Illuminate\Support\Facades\Cache;
use Log;
use PHPMailer\PHPMailer\PHPMailer;
use Storage;
use Throwable;
use View;

class EmailSendService extends CoreService
{
    /**
     * @return string
     */
    protected function getModelClass(): string
    {
        return EmailSetting::class;
    }

	/**
	 * @param EmailTemplate $emailTemplate
	 * @return array
	 */
	public function sendSubscriptions(EmailTemplate $emailTemplate): array
    {
        $mail = new PHPMailer(true);

        try {
            $emailSetting = $emailTemplate->emailSetting;

            $mail->CharSet = 'UTF-8';

            // Настройки SMTP
            /*$mail->isSMTP();
            $mail->SMTPAuth     = $emailSetting->smtp_auth;
            $mail->SMTPDebug    = $emailSetting->smtp_debug;*/

            $mail->Host         = $emailSetting->host;
            $mail->Port         = $emailSetting->port;
            $mail->Username     = $emailSetting->from_to;
            $mail->Password     = $emailSetting->password;
            $mail->SMTPSecure   = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPOptions  = $emailSetting->ssl ?: [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            // От кого
            $mail->setFrom($emailSetting->from_to, $emailSetting->from_site);

            // Кому

            foreach (EmailSubscription::where('active', true)->get() as $subscribe) {

                /** @var EmailSubscription $subscribe */
                $email = data_get($subscribe->user, 'email');

                if (!empty($email)) {
                    $mail->addAddress($email, data_get($subscribe->user, 'firstname', 'User'));
                }

            }

            // Тема письма
            $mail->Subject = $emailTemplate->subject;

            // Тело письма
            $mail->isHTML();
            $mail->Body    = $emailTemplate->body; // <p><strong>«Hello, world!» </strong></p>
            $mail->AltBody = $emailTemplate->alt_body; // Hello, world!

            // Приложение
            foreach ($emailTemplate->galleries as $gallery) {
                /** @var Gallery $gallery */
                try {
                    $mail->addAttachment(request()->getHttpHost() . '/storage/' . $gallery->path);
                } catch (Throwable) {
                    Log::error($mail->ErrorInfo);
                }
            }

            $mail->send();

            return [
                'status' => true,
                'code' => ResponseError::NO_ERROR,
            ];

        } catch (Exception) {
            Log::error($mail->ErrorInfo);
            return [
                'message' => $mail->ErrorInfo,
                'status'  => false,
                'code'    => ResponseError::ERROR_504,
            ];
        }
    }

	/**
	 * @param User $user
	 * @return array
	 */
	public function sendVerify(User $user): array
    {
        $emailTemplate = EmailTemplate::with("emailSetting")->where('type', EmailTemplate::TYPE_VERIFY)->first();
        info(json_encode($emailTemplate->emailSetting));
        $mail = $this->emailBaseAuth($emailTemplate?->emailSetting, $user);

        try {

            $mail->Subject  = data_get($emailTemplate, 'subject', 'Verify your email address');

            $default        = 'Please enter code for verify your email: $verify_code';
            $body           = data_get($emailTemplate, 'body', $default);
            $altBody        = data_get($emailTemplate, 'alt_body', $default);

            $mail->Body     = str_replace('$verify_code', $user->verify_token, $body);
            $mail->AltBody  = str_replace('$verify_code', $user->verify_token, $altBody);

            if (!empty(data_get($emailTemplate, 'galleries'))) {
                foreach ($emailTemplate->galleries as $gallery) {
                    /** @var Gallery $gallery */
                    try {
                        $mail->addAttachment(request()->getHttpHost() . '/storage/' . $gallery->path);
                    } catch (Throwable) {
                        Log::error($mail->ErrorInfo);
                    }
                }
            }

            info(json_encode($mail->send()));

            return [
                'status' => true,
                'code' => ResponseError::NO_ERROR,
            ];
        } catch (Exception $e) {
            info(json_encode($e));
            Log::error('ErrorInfo', [
                $mail->ErrorInfo
            ]);
            $this->error($e);
            return [
                'message' => $mail->ErrorInfo,
                'status'  => false,
                'code'    => ResponseError::ERROR_504,
            ];
        }
    }

	/**
	 * @param Order $order
	 * @return array
	 */
	public function sendOrder(Order $order): array
	{
		Pdf::setOption(['dpi' => 150, 'defaultFont' => 'sans-serif']);

		$titleKey = "order.email.invoice.$order->status.title";
		$title    = Translation::where(['locale' => $this->language, 'key' => $titleKey])->first()?->value ?? $titleKey;
		$logo     = Settings::where('key', 'logo')->first()?->value;
		$fileName = null;
		$host     = request()->getSchemeAndHttpHost();

		if ($logo) {

			$id   = auth('sanctum')->id() ?? "0001";
			$ext  = strtolower(preg_replace("#.+\.([a-z]+)$#i", "$1", $logo));
			$unix = now()->unix();

			$fileName = "$id-$unix.$ext";

			Storage::put("public/images/$fileName", file_get_contents($logo));
		}

		$pdf = View::make(
			'order-email-invoice',
			[
				'order' => $order,
				'lang'  => $this->language,
				'title' => $title,
				'logo'  => $fileName ? "$host/storage/images/$fileName" : '',
			]
		)->render();
		try {
			$mail           = $this->emailBaseAuth(EmailSetting::first(), $order->user);
			$mail->Subject  = $title;
			$mail->Body     = $pdf;
			$mail->addCustomHeader('MIME-Version', '1.0');
			$mail->addCustomHeader('Content-type', 'text/html;charset=UTF-8');
			$mail->send();

			Storage::delete(storage_path("images/$fileName"));

			return [
				'status' => true,
				'code'   => ResponseError::NO_ERROR,
			];
		} catch (Exception $e) {
			$this->error($e);
			return [
				'message' => $e->getMessage(), //$mail->ErrorInfo,
				'status'  => false,
				'code'    => ResponseError::ERROR_504,
			];
		}
	}

	/**
	 * @param User $user
	 * @param $str
	 * @return array
	 */
	public function sendEmailPasswordReset(User $user, $str): array
    {
        $emailTemplate = EmailTemplate::where('type', EmailTemplate::TYPE_VERIFY)->first();
		$mail = $this->emailBaseAuth($emailTemplate?->emailSetting, $user);

        try {

			$mail->Subject  = $emailTemplate->subject ?? 'Reset password';

            $default        = 'Please enter code for reset your password: $verify_code';
            $body           = $emailTemplate->body ?? $default;
            $altBody        = $emailTemplate->alt_body ?? $default;

            $mail->Body     = str_replace('$verify_code', $str, $body);
            $mail->AltBody  = str_replace('$verify_code', $str, $altBody);

            if (!empty($emailTemplate->galleries)) {
                foreach ($emailTemplate->galleries as $gallery) {
                    /** @var Gallery $gallery */
                    try {
                        $mail->addAttachment(request()->getHttpHost() . '/storage/' . $gallery->path);
                    } catch (Throwable) {
                        Log::error($mail->ErrorInfo);
                    }
                }
            }

            $mail->send();

            return [
                'status' => true,
                'code'   => ResponseError::NO_ERROR,
            ];
        } catch (Throwable $e) {


            $this->error($e);

            return [
                'message' => $mail->ErrorInfo,
                'status'  => false,
                'code'    => ResponseError::ERROR_504,
            ];
        }
    }

	/**
	 * @param EmailSetting|null $emailSetting
	 * @param User $user
	 * @return PHPMailer
	 */
	public function emailBaseAuth(?EmailSetting $emailSetting, User $user): PHPMailer
    {
		if (empty($emailSetting)) {
			$emailSetting = EmailSetting::first();
		}
        $mail = new PHPMailer(true);
        $mail->isHTML();
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        info($emailSetting->from_to);
        $mail->SMTPAuth     = $emailSetting->smtp_auth;
        // $mail->SMTPDebug    = $emailSetting->smtp_debug;
        /*
        */
        $mail->SMTPDebug = 0;
        
        $mail->setFrom($emailSetting->from_to, "JumlaJumla");

        $mail->addAddress($user->email, $user->name_or_email);
        
        $mail->Host         = $emailSetting->host;
        $mail->Port         = $emailSetting->port;
        $mail->Username     = $emailSetting?->from_to;
        $mail->Password     = $emailSetting?->password;
        $mail->SMTPSecure   = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPOptions  = data_get($emailSetting, 'ssl.ssl.verify_peer') ? $emailSetting->ssl : [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

		if (!Cache::get('tvoirifgjn.seirvjrc') || data_get(Cache::get('tvoirifgjn.seirvjrc'), 'active') != 1) {
			abort(403);
		}

        // try {

        //     $mail->setFrom($emailSetting->from_to, $emailSetting->from_site);

        // } catch (Throwable $e) {
        //     Log::error($mail->ErrorInfo);
        //     $this->error($e);
        // }

        return $mail;
    }
}
