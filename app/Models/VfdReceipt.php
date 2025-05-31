<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VfdReceipt extends Model
{
    use HasFactory, SoftDeletes;

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
        'error_message',
        'synced_to_archive_at',
        'sync_error',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'synced_to_archive_at' => 'datetime',
        'vfd_response' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_GENERATED = 'generated';
    public const STATUS_FAILED = 'failed';
    
    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();
        
        // When a receipt is created or updated to generated status, dispatch a job to sync it to the archive
        static::created(function ($receipt) {
            if (config('services.vfd.archive_enabled', false) && $receipt->status === self::STATUS_GENERATED) {
                ArchiveVfdReceipt::dispatch($receipt)
                    ->onQueue('vfd-archive')
                    ->delay(now()->addMinutes(1)); // Small delay to ensure receipt is fully processed
            }
        });
        
        // Also handle updates in case status changes to generated
        static::updated(function ($receipt) {
            if (config('services.vfd.archive_enabled', false) && 
                $receipt->isDirty('status') && 
                $receipt->status === self::STATUS_GENERATED && 
                !$receipt->synced_to_archive_at) {
                
                ArchiveVfdReceipt::dispatch($receipt->fresh())
                    ->onQueue('vfd-archive')
                    ->delay(now()->addMinutes(1));
            }
        });
    }

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