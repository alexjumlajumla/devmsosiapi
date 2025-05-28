<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VfdReceipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'receipt_number',
        'receipt_url',
        'vfd_response',
        'receipt_type',
        'model_id',
        'model_type',
        'amount',
        'payment_method',
        'customer_name',
        'customer_phone',
        'customer_email',
        'status',
        'error_message'
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_FAILED = 'failed';

    public const TYPE_DELIVERY = 'delivery';
    public const TYPE_SUBSCRIPTION = 'subscription';

    /**
     * Get the parent model (delivery or subscription)
     */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }
} 