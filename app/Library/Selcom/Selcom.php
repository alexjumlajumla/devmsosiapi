<?php

namespace App\Library\Selcom;

use App\Library\Selcom\Exceptions\MissingDataException;
use App\Library\Selcom\Traits\HandlesCheckout;
use App\Library\Selcom\Traits\ValidatesData;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use App\Models\Payment;
use App\Models\PaymentPayload;

class Selcom
{
    use HandlesCheckout;
    use ValidatesData;

    private $vendor;

    private $apiKey;

    private $apiSecret;

    private string $apiUrl;

    private $host;

    private $redirectUrl;

    private $cancelUrl;

    private $webhookUrl;

    public function __construct($payload, $redirectUrl="", $cancelUrl="", $webhookUrl="")
    {   
        $payment = Payment::where('tag', Payment::TAG_SELCOM)->first();
        $paymentPayload = PaymentPayload::where('payment_id', $payment?->id)->first();
        $payload        = $paymentPayload?->payload;
        $this->host = request()->getSchemeAndHttpHost();

        $this->vendor = data_get($payload, 'selcom_vendor_id');

        $this->apiKey = data_get($payload, 'selcom_key');

        $this->apiSecret = data_get($payload, 'selcom_secret');

        $this->redirectUrl = $redirectUrl;

        $this->cancelUrl = $cancelUrl;

        $this->webhookUrl = $webhookUrl;

        // $subdomain = config('selcom.live') ? 'apigw' : 'apigwtest';

        $this->apiUrl = "https://apigw.selcommobile.com/v1/";
    }

    public function prefix()
    {
        return config('selcom.prefix');
    }

    public function redirectUrl()
    {
        return $this->redirectUrl;
    }

    public function cancelUrl()
    {
        return $this->cancelUrl;
    }

    public function webhookUrl()
    {
        return $this->webhookUrl;
    }

    public function paymentGatewayColors()
    {
        return config('selcom.colors');
    }

    public function paymentExpiry()
    {
        return config('selcom.expiry');
    }

    /**
     * @throws MissingDataException
     */
    private function validateConfig()
    {
        if (! $this->vendor || ! $this->apiKey || ! $this->apiSecret) {
            throw new MissingDataException(
                'Your Selcom credentials can not be empty!'
            );
        }
    }

    public function makeRequest(string $uri, string $method, array $data = []): Response
    {
        $fullPath = $this->apiUrl . $uri;

        return Http::withHeaders($this->getHeaders($data))
            ->{strtolower($method)}($fullPath, $data);
    }

    private function getHeaders($data): array
    {
        $this->validateConfig();

        date_default_timezone_set('Africa/Dar_es_Salaam');

        $authorization = base64_encode($this->apiKey);
        $signedFields = implode(',', array_keys($data));
        $timestamp = date('c');
        $digest = $this->getDigest($data, $timestamp);

        return [
            'Content-type' => 'application/json;charset=\"utf-8\"',
            'Accept' => 'application/json',
            'Authorization' => "SELCOM $authorization",
            'Digest-Method' => 'HS256',
            'Digest' => $digest,
            'Signed-Fields' => $signedFields,
            'Cache-Control' => 'no-cache',
            'Timestamp' => $timestamp,
        ];
    }

    private function getDigest($data, $timestamp): string
    {
        $this->validateConfig();

        $signData = "timestamp=$timestamp";

        if (count($data)) {
            foreach ($data as $key => $value) {
                $signData .= "&$key=$value";
            }
        }

        return base64_encode(hash_hmac('sha256', $signData, $this->apiSecret, true));
    }
}