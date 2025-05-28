<?php

namespace App\Models;

use App\Traits\Loadable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Models\LoanRepayment;

class Loan extends Model
{
    use HasFactory, SoftDeletes, Loadable;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'float',
        'interest_rate' => 'float',
        'repayment_amount' => 'float',
        'disbursed_at' => 'datetime',
        'due_date' => 'datetime',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_REPAID = 'repaid';
    const STATUS_DEFAULTED = 'defaulted';

    const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_REPAID,
        self::STATUS_DEFAULTED,
    ];

    /**
     * Get the vendor (user) who received the loan.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the admin who disbursed the loan.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    /**
     * Get all repayments made for this loan.
     */
    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    /**
     * Accessor to get total amount repaid so far.
     */
    public function getTotalRepaidAttribute(): float
    {
        return $this->repayments()->sum('amount');
    }

    public function getRemainingAmountAttribute(): float
    {
        return $this->repayment_amount - $this->repayments->sum('amount');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === self::STATUS_ACTIVE && now()->gt($this->due_date);
    }

    public function calculateRepaymentAmount(): float
    {
        return $this->amount + ($this->amount * ($this->interest_rate / 100));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function disbursedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}
