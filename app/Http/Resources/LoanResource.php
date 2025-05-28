<?php

namespace App\Http\Resources;

use App\Models\Loan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        /** @var Loan|JsonResource $this */
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'amount' => $this->amount,
            'interest_rate' => $this->interest_rate,
            'repayment_amount' => $this->repayment_amount,
            'remaining_amount' => $this->remaining_amount,
            'disbursed_by' => $this->disbursed_by,
            'disbursed_at' => $this->when($this->disbursed_at, $this->disbursed_at?->format('Y-m-d H:i:s') . 'Z'),
            'due_date' => $this->when($this->due_date, $this->due_date?->format('Y-m-d H:i:s') . 'Z'),
            'status' => $this->status,
            'is_overdue' => $this->is_overdue,
            'created_at' => $this->when($this->created_at, $this->created_at?->format('Y-m-d H:i:s') . 'Z'),
            'updated_at' => $this->when($this->updated_at, $this->updated_at?->format('Y-m-d H:i:s') . 'Z'),
            'deleted_at' => $this->when($this->deleted_at, $this->deleted_at?->format('Y-m-d H:i:s') . 'Z'),

            // Relations
            'user' => UserResource::make($this->whenLoaded('user')),
            'disbursed_by_user' => UserResource::make($this->whenLoaded('disbursedBy')),
            'repayments' => LoanRepaymentResource::collection($this->whenLoaded('repayments')),
        ];
    }
} 