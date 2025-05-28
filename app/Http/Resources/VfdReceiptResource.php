<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VfdReceiptResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'receipt_url' => $this->receipt_url,
            'receipt_type' => $this->receipt_type,
            'model_id' => $this->model_id,
            'model_type' => $this->model_type,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'status' => $this->status,
            'error_message' => $this->when($this->error_message, $this->error_message),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
} 