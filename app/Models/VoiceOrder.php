<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceOrder extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'shop_id',
        'currency_id',
        'address_id',
        'deliveryman_id',
        'delivery_type',
        'total_price',
        'delivery_fee',
        'tax',
        'service_fee',
        'address',
        'location',
        'delivery_date',
        'delivery_time',
        'session_id',
        'transcription_text',
        'intent_data',
        'filters_detected',
        'product_ids',
        'recommendation_text',
        'audio_url',
        'audio_format',
        'audio_duration',
        'status',
        'score',
        'processing_time_ms',
        'confidence_score',
        'transcription_duration_ms',
        'ai_processing_duration_ms',
        'assigned_agent_id',
        'is_feedback_provided',
        'feedback',
        'was_helpful',
        'order_id',
        'log_id'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'intent_data' => 'array',
        'filters_detected' => 'array',
        'product_ids' => 'array',
        'is_feedback_provided' => 'boolean',
        'was_helpful' => 'boolean',
        'feedback' => 'array',
        'confidence_score' => 'float',
        'score' => 'float',
        'location' => 'array',
        'total_price' => 'float',
        'delivery_fee' => 'float',
        'tax' => 'float',
        'service_fee' => 'float',
    ];

    /**
     * Get the user that owns the voice order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the shop associated with the voice order.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the currency associated with the voice order.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Get the address associated with the voice order.
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(UserAddress::class, 'address_id');
    }

    /**
     * Get the delivery man assigned to the voice order.
     */
    public function deliveryMan(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deliveryman_id');
    }

    /**
     * Get the related AI assistant log.
     */
    public function log(): BelongsTo
    {
        return $this->belongsTo(AIAssistantLog::class, 'log_id');
    }

    /**
     * Get the related order if it was converted.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the agent assigned to this voice order.
     */
    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    /**
     * Scope a query to only include pending voice orders.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include fulfilled voice orders.
     */
    public function scopeFulfilled($query)
    {
        return $query->where('status', 'fulfilled');
    }

    /**
     * Scope a query to only include converted voice orders (ones that created an order).
     */
    public function scopeConverted($query)
    {
        return $query->whereNotNull('order_id');
    }

    /**
     * Check if the voice order has been converted to a real order.
     */
    public function isConverted(): bool
    {
        return !is_null($this->order_id);
    }
} 