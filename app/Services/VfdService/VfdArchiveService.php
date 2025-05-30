<?php

namespace App\Services\VfdService;

use App\Models\VfdReceipt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VfdArchiveService
{
    /**
     * The archive service endpoint
     *
     * @var string
     */
    protected ?string $endpoint = null;
    
    /**
     * The archive API key
     *
     * @var string|null
     */
    protected ?string $apiKey = null;
    
    /**
     * Whether to verify SSL certificates
     *
     * @var bool
     */
    protected bool $verifySsl = true;
    
    /**
     * Whether archive service is enabled
     *
     * @var bool
     */
    protected bool $enabled = false;
    
    /**
     * Whether to use sandbox mode
     *
     * @var bool
     */
    protected bool $sandbox = true;
    
    /**
     * Create a new VfdArchiveService instance
     *
     * @return void
     */
    public function __construct()
    {
        $this->enabled = (bool) config('services.vfd.archive_enabled', false);
        $this->sandbox = (bool) config('services.vfd.sandbox', true);
        
        if ($this->enabled || $this->sandbox) {
            $this->endpoint = rtrim((string) config('services.vfd.archive_endpoint', ''), '/');
            $this->apiKey = (string) config('services.vfd.archive_api_key', '');
            $this->verifySsl = (bool) config('services.vfd.archive_verify_ssl', !$this->sandbox);
        }
        
        // In sandbox mode, provide a mock endpoint if none is set
        if ($this->sandbox && empty($this->endpoint)) {
            $this->endpoint = 'https://vfd-sandbox.mojatax.com/api/archive';
            $this->apiKey = $this->apiKey ?: 'sandbox_archive_key_123456';
        }
    }
    
    /**
     * Sync a single receipt to the archive
     * 
     * @param VfdReceipt $receipt
     * @return array
     */
    public function syncReceipt(VfdReceipt $receipt): array
    {
        if (!$this->enabled && !$this->sandbox) {
            return [
                'success' => true,
                'message' => 'Archive service is disabled',
                'skipped' => true
            ];
        }
        
        if ($this->sandbox) {
            return [
                'success' => true,
                'message' => 'Sandbox: Receipt archived successfully',
                'sandbox' => true,
                'receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number
            ];
        }
        if (empty($this->endpoint) || empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Archive service not configured',
            ];
        }
        
        try {
            $payload = $this->preparePayload($receipt);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->withOptions([
                'verify' => $this->verifySsl,
                'timeout' => 30,
            ])
            ->post($this->endpoint, $payload);
            
            if ($response->successful()) {
                $receipt->update([
                    'synced_to_archive_at' => now(),
                    'sync_error' => null,
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Receipt synced to archive',
                ];
            }
            
            $error = "HTTP {$response->status()}: " . ($response->body() ?: 'No response body');
            
            $receipt->update([
                'sync_error' => $error,
            ]);
            
            Log::error('Failed to sync receipt to archive', [
                'receipt_id' => $receipt->id,
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            
            return [
                'success' => false,
                'message' => $error,
                'status' => $response->status(),
            ];
            
        } catch (\Exception $e) {
            $error = $e->getMessage();
            
            $receipt->update([
                'sync_error' => $error,
            ]);
            
            Log::error('Exception syncing receipt to archive', [
                'receipt_id' => $receipt->id,
                'error' => $error,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return [
                'success' => false,
                'message' => $error,
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
     * Prepare the payload for the archive service
     * 
     * @param VfdReceipt $receipt
     * @return array
     */
    protected function preparePayload(VfdReceipt $receipt): array
    {
        return [
            'receipt_number' => $receipt->receipt_number,
            'receipt_type' => $receipt->receipt_type,
            'amount' => $receipt->amount,
            'currency' => 'TZS',
            'customer_name' => $receipt->customer_name,
            'customer_phone' => $receipt->customer_phone,
            'customer_email' => $receipt->customer_email,
            'payment_method' => $receipt->payment_method,
            'status' => $receipt->status,
            'issued_at' => $receipt->created_at->toIso8601String(),
            'metadata' => [
                'model_type' => $receipt->model_type,
                'model_id' => $receipt->model_id,
                'vfd_response' => $receipt->vfd_response ? json_decode($receipt->vfd_response, true) : null,
            ],
        ];
    }
    
    /**
     * Test the connection to the archive service
     * 
     * @return array
     */
    public function testConnection(): array
    {
        if (empty($this->endpoint) || empty($this->apiKey)) {
            return [
                'success' => false,
                'message' => 'Archive service not configured',
            ];
        }
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ])
            ->withOptions([
                'verify' => $this->verifySsl,
                'timeout' => 10,
            ])
            ->get($this->endpoint . '/health');
            
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
}
