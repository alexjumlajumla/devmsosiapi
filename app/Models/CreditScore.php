<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CreditScore extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'credit_scores';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'score_change',
        'reason',
        'source_type',  // For polymorphic relation (loan, repayment, etc)
        'source_id',    // For polymorphic relation
        'current_score',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'score_change' => 'integer',
        'current_score' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user that owns the credit score.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent sourceable model (loan, repayment, etc).
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Calculate credit score change based on loan repayment
     */
    public static function calculateScoreChange(string $action, $data): int
    {
        return match($action) {
            'loan_disbursed' => -5, // Initial negative impact
            'repayment_ontime' => 10, // Positive impact for on-time payment
            'repayment_late' => -10,  // Negative impact for late payment
            'loan_defaulted' => -20, // Major negative impact for default
            'loan_completed' => 20,   // Major positive impact for completing loan
            default => 0,
        };
    }
}