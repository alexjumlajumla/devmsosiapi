<?php

namespace App\Http\Resources;

use App\Models\LoanRepayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanRepaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        /** @var LoanRepayment|JsonResource $this */
        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'user_id' => $this->user_id,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'recorded_by' => $this->recorded_by,
            'paid_at' => $this->when($this->paid_at, $this->paid_at?->format('Y-m-d H:i:s') . 'Z'),
            'created_at' => $this->when($this->created_at, $this->created_at?->format('Y-m-d H:i:s') . 'Z'),
            'updated_at' => $this->when($this->updated_at, $this->updated_at?->format('Y-m-d H:i:s') . 'Z'),
            'deleted_at' => $this->when($this->deleted_at, $this->deleted_at?->format('Y-m-d H:i:s') . 'Z'),

            // Relations
            'user' => UserResource::make($this->whenLoaded('user')),
            'recorded_by_user' => UserResource::make($this->whenLoaded('recordedBy')),
            'loan' => LoanResource::make($this->whenLoaded('loan')),
        ];
    }
} 