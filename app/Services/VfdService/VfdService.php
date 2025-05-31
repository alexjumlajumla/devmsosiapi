<?php

namespace App\Services\VfdService;

use App\Models\VfdReceipt;
use App\Services\CoreService;
use App\Models\SmsPayload;
use App\Services\SMSGatewayService\MobishastraService;
use App\Services\SMSGatewayService\TwilioService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class VfdService extends CoreService
{
    protected ?string $baseUrl = null;
    protected ?string $apiKey = null;
    protected ?string $tin = null;
    protected ?string $certPath = null;
    protected bool $isSandbox = true;

    public function __construct()
    {
        parent::__construct();
        
        try {
            $this->isSandbox = (bool) config('services.vfd.sandbox', true);
            $this->baseUrl = rtrim((string) config('services.vfd.base_url', ''), '/');
            $this->apiKey = (string) config('services.vfd.api_key', '');
            $this->tin = (string) config('services.vfd.tin', '');
            
            if (!$this->isSandbox) {
                $this->certPath = (string) config('services.vfd.cert_path', '');
                if (!file_exists($this->certPath)) {
                    throw new \RuntimeException('VFD certificate not found at: ' . $this->certPath);
                }
            } else {
                $this->certPath = null;
            }
            
            // Set default sandbox values if in sandbox mode and no values provided
            if ($this->isSandbox) {
                $this->baseUrl = $this->baseUrl ?: 'https://vfd-sandbox.mojatax.com';
                $this->apiKey = $this->apiKey ?: 'sandbox_test_key_123456';
                $this->tin = $this->tin ?: '123456789';
            }
        } catch (\Exception $e) {
            // If we're in sandbox mode, continue with defaults
            if ($this->isSandbox) {
                $this->baseUrl = 'https://vfd-sandbox.mojatax.com';
                $this->apiKey = 'sandbox_test_key_123456';
                $this->tin = '123456789';
                $this->certPath = null;
            } else {
                throw $e;
            }
        }
    }
    
    /**
     * Test connection to VFD API
     * 
     * @return array
     */
    public function testConnection(): array
    {
        try {
            if ($this->isSandbox) {
                return [
                    'success' => true,
                    'message' => 'Sandbox mode: Connection test skipped',
                    'sandbox' => true
                ];
            }
            
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Accept' => 'application/json',
            ])
            ->withOptions([
                'verify' => $this->certPath ?: false,
                'timeout' => 10,
            ])
            ->get("{$this->baseUrl}/api/health");
            
            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'data' => $response->json(),
                ];
            }
            
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $response->status() . ' ' . $response->body(),
                'status' => $response->status(),
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection error: ' . $e->getMessage(),
                'exception' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ];
        }
    }

    /**
     * Implement the abstract method from CoreService
     * 
     * @return string
     */
    protected function getModelClass(): string
    {
        return VfdReceipt::class;
    }

    /**
     * Generate a fiscal receipt for delivery or subscription
     *
     * @param string $type
     * @param array $data
     * @return array
     */
    public function generateReceipt(string $type, array $data): array
    {
        try {
            // Validate required fields
            if (!in_array($type, [VfdReceipt::TYPE_DELIVERY, VfdReceipt::TYPE_SUBSCRIPTION])) {
                throw new \InvalidArgumentException('Invalid receipt type');
            }

            // Create VFD receipt record
            $receipt = VfdReceipt::create([
                'receipt_type' => $type,
                'model_id' => $data['model_id'],
                'model_type' => $data['model_type'],
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'customer_name' => $data['customer_name'] ?? 'Customer',
                'customer_phone' => $data['customer_phone'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'receipt_number' => $this->generateReceiptNumber(),
                'status' => VfdReceipt::STATUS_PENDING
            ]);

            // Log receipt creation
            Log::info('Creating VFD receipt', [
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'type' => $type,
                'amount' => $data['amount']
            ]);

            // Prepare VFD API request payload
            $payload = [
                'tin' => $this->tin,
                'receiptNumber' => $receipt->receipt_number,
                'amount' => (float) $receipt->amount,
                'paymentMethod' => $this->mapPaymentMethod($receipt->payment_method),
                'customerDetails' => [
                    'name' => $receipt->customer_name,
                    'phone' => $receipt->customer_phone,
                    'email' => $receipt->customer_email
                ],
                'items' => [
                    [
                        'description' => $type === VfdReceipt::TYPE_DELIVERY ? 'Delivery Fee' : 'Subscription Fee',
                        'quantity' => 1,
                        'unitPrice' => (float) $receipt->amount,
                        'totalPrice' => (float) $receipt->amount,
                        'taxCode' => 'S', // Standard rate
                        'taxRate' => 18.0 // 18% VAT
                    ]
                ],
                'dateTime' => now()->format('Y-m-d H:i:s'),
                'currency' => 'TZS',
                'reference' => 'ORDER-' . $data['model_id']
            ];

            // Log the payload for debugging
            Log::debug('VFD API Request Payload', $payload);

            // Make API request to VFD
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])
                ->withOptions([
                    'verify' => $this->certPath ?: false,
                    'debug' => config('app.debug') ? fopen('php://stderr', 'w') : false
                ])
                ->post("{$this->baseUrl}/api/receipts", $payload);

            // Log the response
            Log::debug('VFD API Response', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                $receipt->update([
                    'receipt_url' => $responseData['receiptUrl'] ?? null,
                    'vfd_response' => json_encode($responseData),
                    'status' => VfdReceipt::STATUS_GENERATED,
                    'receipt_number' => $responseData['receiptNumber'] ?? $receipt->receipt_number
                ]);

                // Send SMS with receipt URL if phone is available
                if ($receipt->customer_phone) {
                    $this->sendReceiptSms($receipt);
                }

                return [
                    'status' => true,
                    'message' => 'Receipt generated successfully',
                    'data' => $receipt
                ];
            }

            // Handle API errors
            $errorResponse = $response->json();
            $errorMessage = $errorResponse['message'] ?? $response->body();
            
            $receipt->update([
                'status' => VfdReceipt::STATUS_FAILED,
                'error_message' => $errorMessage
            ]);

            Log::error('VFD API Error', [
                'status' => $response->status(),
                'error' => $errorMessage,
                'receipt_id' => $receipt->id
            ]);

            return [
                'status' => false,
                'message' => 'Failed to generate receipt: ' . $errorMessage,
                'error' => $errorMessage
            ];

        } catch (Exception $e) {
            Log::error('VFD Receipt Generation Error: ' . $e->getMessage(), [
                'type' => $type,
                'data' => $data,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => false,
                'message' => 'Error generating receipt',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate a unique receipt number
     */
    protected function generateReceiptNumber(): string
    {
        return 'VFD-' . time() . '-' . rand(1000, 9999);
    }

    /**
     * Map internal payment method to VFD payment method
     */
    protected function mapPaymentMethod(string $method): string
    {
        return match (strtolower($method)) {
            'cash' => 'CASH',
            'card' => 'CARD',
            'bank_transfer' => 'BANK',
            default => 'OTHER'
        };
    }

    /**
     * Send receipt URL via SMS
     */
    protected function sendReceiptSms(VfdReceipt $receipt): void
    {
        try {
            $message = "Your fiscal receipt is ready. View it here: {$receipt->receipt_url}";
            
            // Use active SMS gateway
            $smsPayload = SmsPayload::where('default', 1)->first();
            
            if (!$smsPayload) {
                Log::warning('No default SMS gateway configured for sending receipt SMS');
                return;
            }
            
            $phone = $receipt->customer_phone;
            
            // Send SMS based on the active SMS gateway
            $result = match ($smsPayload->type) {
                SmsPayload::MOBISHASTRA => (new MobishastraService)->sendSms($phone, $message),
                SmsPayload::FIREBASE => (new TwilioService)->sendSms($phone, null, $smsPayload, $message),
                SmsPayload::TWILIO => (new TwilioService)->sendSms($phone, null, $smsPayload, $message),
                default => ['status' => false, 'message' => 'Invalid SMS gateway type']
            };
            
            if (!data_get($result, 'status')) {
                Log::error('Failed to send receipt SMS: ' . data_get($result, 'message'));
            }
        } catch (Exception $e) {
            Log::error('Failed to send receipt SMS: ' . $e->getMessage(), [
                'receipt_id' => $receipt->id,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
} 