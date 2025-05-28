<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelcomPayment extends Model
{
    protected $fillable = [
        'transid',
        'order_id',
        'amount',
        'status',
        'payment_data'
    ];

    protected $casts = [
        'payment_data' => 'array'
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
