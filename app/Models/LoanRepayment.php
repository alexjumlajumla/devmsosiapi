<?php

namespace App\Models;

use App\Traits\Loadable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Models\Loan;

class LoanRepayment extends Model
{
    use HasFactory, SoftDeletes, Loadable;

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
    ];

    const PAYMENT_METHOD_WALLET = 'wallet';
    const PAYMENT_METHOD_MOBILE_MONEY = 'mobile_money';
    const PAYMENT_METHOD_CASH = 'cash';
    const PAYMENT_METHOD_CARD = 'card';

    const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_WALLET,
        self::PAYMENT_METHOD_MOBILE_MONEY,
        self::PAYMENT_METHOD_CASH,
        self::PAYMENT_METHOD_CARD,
    ];

    /**
     * Get the loan associated with this repayment.
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Get the user associated with this repayment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who recorded the repayment.
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
