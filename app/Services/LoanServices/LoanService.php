<?php

namespace App\Services\LoanServices;

use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\User;
use App\Services\UserServices\UserWalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Exception;
use Throwable;
use App\Models\CreditScore;



class LoanService
{
    /**
     * Disburse a loan to a user's wallet
     */
    public function disburseLoan(array $data, int $adminId): Loan
    {
        return DB::transaction(function () use ($data, $adminId) {
            $repaymentAmount = $data['amount'] + ($data['amount'] * $data['interest_rate'] / 100);

            $loan = Loan::create([
                'user_id'          => $data['user_id'],
                'amount'           => $data['amount'],
                'interest_rate'    => $data['interest_rate'],
                'repayment_amount' => $repaymentAmount,
                'disbursed_by'     => $adminId,
                'disbursed_at'     => now(),
                'due_date'         => $data['due_date'],
                'status'           => 'active',
            ]);

            $user = User::find($data['user_id']);
            if (!$user || !$user->wallet) {
                throw ValidationException::withMessages([
                    'user_id' => 'User or wallet not found.'
                ]);
            }

            $walletResult = (new UserWalletService())->update($user, [
                'price' => $data['amount'],
                'note'  => "Loan disbursement #{$loan->id}",
            ]);

            if (!data_get($walletResult, 'status')) {
                Log::error("Wallet update failed for loan ID {$loan->id}", [
                    'user_id' => $user->id,
                    'result' => $walletResult
                ]);
                throw new Exception('Failed to update user wallet.');
            }

            return $loan;
        });
    }

    /**
     * Record a repayment for a loan
     */
    public function recordRepayment(array $data, int $adminId): LoanRepayment
    {
        return DB::transaction(function () use ($data, $adminId) {
            $loan = Loan::find($data['loan_id']);
            if (!$loan) {
                throw ValidationException::withMessages([
                    'loan_id' => 'Loan not found.'
                ]);
            }

            $remainingBalance = $loan->repayment_amount - $loan->total_repaid;

            if ($remainingBalance <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'This loan has already been fully repaid.'
                ]);
            }

            if ($data['amount'] > $remainingBalance) {
                $overpaid = $data['amount'] - $remainingBalance;
                throw ValidationException::withMessages([
                    'amount' => "The payment exceeds the remaining loan balance by $overpaid."
                ]);
            }

            $repayment = LoanRepayment::create([
                'loan_id'        => $loan->id,
                'user_id'        => $loan->user_id,
                'amount'         => $data['amount'],
                'payment_method' => $data['payment_method'],
                'recorded_by'    => $adminId,
                'paid_at'        => $data['paid_at'] ?? now(),
            ]);

            $this->updateLoanStatus($loan);

            return $repayment;
        });
    }

    /**
     * Mark loan as repaid if fully paid
     */

    protected function updateLoanStatus(Loan $loan): void
    {
        DB::transaction(function () use ($loan) {
            $totalRepaid = $loan->repayments()->sum('amount');
            if ($totalRepaid >= $loan->repayment_amount && $loan->status !== 'repaid') {
                $loan->update(['status' => 'repaid']);
                
                // Wrap updateCreditScore inside the same transaction to ensure atomicity.
                $this->updateCreditScore($loan);
            }
        });
    }
    


    /**
     * Get the user's loan balance by email or user_id
     */

    public function getUserLoanBalance($validated)
    {
        try {
            if (empty($validated['user_id']) && empty($validated['email'])) {
                throw ValidationException::withMessages([
                    'user_id_or_email' => 'Either user_id or email must be provided.',
                ]);
            }

            // Find user by ID or email
            $user = isset($validated['user_id'])
                ? User::findOrFail($validated['user_id'])
                : User::where('email', $validated['email'])->firstOrFail();

            // Get all active loans (exclude those with 'repaid' status)
            $loans = Loan::where('user_id', $user->id)
                ->where('status', '!=', 'repaid')
                ->get();

            // Get all repayments for the user
            $repayments = LoanRepayment::where('user_id', $user->id)->get();

            // Group repayments by loan_id
            $repaymentsGroupedByLoan = $repayments->groupBy('loan_id');

            $loanBalances = [];
            $totalOwed = 0;
            $totalRepaid = 0;
            $totalOutstandingBalance = 0;

            // Loop through each loan and calculate the remaining balance
            foreach ($loans as $loan) {
                $repaymentAmount = $loan->repayment_amount;
                $loanRepaid = 0;

                // Apply repayments to the loan
                if ($repaymentsGroupedByLoan->has($loan->id)) {
                    $loanRepayments = $repaymentsGroupedByLoan[$loan->id];

                    foreach ($loanRepayments as $payment) {
                        $remainingRepayment = $repaymentAmount - $loanRepaid;
                        if ($payment->amount <= $remainingRepayment) {
                            $loanRepaid += $payment->amount;
                        } else {
                            $loanRepaid += $remainingRepayment;
                        }
                    }
                }

                // Calculate the outstanding balance for each loan
                $outstandingBalanceForLoan = max($repaymentAmount - $loanRepaid, 0);

                // Add loan information and its outstanding balance to the response
                $loanBalances[] = [
                    'loan_id'             => $loan->id,
                    'repayment_amount'    => number_format($repaymentAmount, 2, '.', ''),
                    'amount_repaid'       => number_format($loanRepaid, 2, '.', ''),
                    'outstanding_balance' => number_format($outstandingBalanceForLoan, 2, '.', ''),
                    'status'              => $loan->status,
                ];

                // Add to totals
                $totalOwed += $repaymentAmount;
                $totalRepaid += min($loanRepaid, $repaymentAmount);
                $totalOutstandingBalance += $outstandingBalanceForLoan;
            }

            return [
                'status'                => true,
                'user_id'               => $user->id,
                'email'                 => $user->email,
                'loan_balances'         => $loanBalances,  // Return outstanding balance for each loan
                'total_owed'            => number_format($totalOwed, 2, '.', ''),
                'total_repaid'          => number_format($totalRepaid, 2, '.', ''),
                'total_outstanding_balance' => number_format($totalOutstandingBalance, 2, '.', ''),
            ];
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        } catch (\Throwable $e) {
            return $this->handleGeneralException($e);
        }
    }

    /**
     * repay loan balance by loan_id
     */

    public function repayLoanFromWallet(int $loanId, int $adminId): ?LoanRepayment
    {
        return DB::transaction(function () use ($loanId, $adminId) {
            Log::info("Initiating loan repayment from wallet.", [
                'loan_id' => $loanId,
                'admin_id' => $adminId
            ]);

            $loan = Loan::findOrFail($loanId);

            Log::info("Loan fetched", [
                'loan_id' => $loan->id,
                'user_id' => $loan->user_id,
                'repayment_amount' => $loan->repayment_amount,
                'status' => $loan->status,
            ]);

            if ($loan->status === 'repaid') {
                Log::warning("Loan already repaid.", ['loan_id' => $loan->id]);
                throw ValidationException::withMessages([
                    'loan_id' => 'Loan is already fully repaid.',
                ]);
            }

            $user = $loan->vendor;

            if (!$user || !$user->wallet) {
                Log::error("User or wallet not found.", [
                    'user' => $user,
                    'wallet' => optional($user)->wallet
                ]);
                throw ValidationException::withMessages([
                    'user' => 'User or wallet not found.',
                ]);
            }

            $remainingBalance = $loan->repayment_amount - $loan->repayments()->sum('amount');
            $walletBalance = $user->wallet->price;

            Log::info("User wallet and loan balance info", [
                'user_id' => $user->id,
                'wallet_balance' => $walletBalance,
                'remaining_loan_balance' => $remainingBalance,
            ]);

            if ($walletBalance <= 0) {
                Log::warning("Wallet has no balance.", [
                    'user_id' => $user->id,
                    'wallet_balance' => $walletBalance,
                ]);
                throw ValidationException::withMessages([
                    'wallet' => 'User wallet has no balance.',
                ]);
            }

            $repaymentAmount = min($remainingBalance, $walletBalance);

            Log::info("Calculated repayment amount", [
                'repayment_amount' => $repaymentAmount,
            ]);

            // Deduct from wallet
            $walletResult = (new UserWalletService())->update($user, [
                'price' => -$repaymentAmount,
                'note'  => "Partial loan repayment for Loan ID #{$loan->id}",
            ]);

            if (!data_get($walletResult, 'status')) {
                Log::error("Wallet deduction failed for loan ID {$loan->id}", [
                    'user_id' => $user->id,
                    'result'  => $walletResult
                ]);
                throw new Exception('Failed to deduct from user wallet.');
            }

            // Record repayment
            $repayment = LoanRepayment::create([
                'loan_id'        => $loan->id,
                'user_id'        => $loan->user_id,
                'amount'         => $repaymentAmount,
                'payment_method' => 'wallet',
                'recorded_by'    => $adminId,
                'paid_at'        => now(),
            ]);

            Log::info("Loan repayment recorded", [
                'repayment_id' => $repayment->id,
                'amount' => $repaymentAmount,
                'user_id' => $user->id,
            ]);

            $this->updateLoanStatus($loan);

            Log::info("Loan status updated if fully repaid.", [
                'loan_id' => $loan->id,
                'current_total_repaid' => $loan->repayments()->sum('amount')
            ]);

            return $repayment;
        });
    }






    /**
     * Update the user's credit score based on loan repayment
     */
    /**
 * Update the user's credit score based on loan repayment
 */
public function updateCreditScore(Loan $loan): void
{
    // Fetch the user from the loan
    $user = $loan->user;

    // Check if the user exists
    if (!$user) {
        Log::error("User not found for loan ID {$loan->id}", [
            'loan_id' => $loan->id,
        ]);
        return; // Exit early if the user does not exist
    }

    // Prevent duplicate credit score updates for the same loan
    $alreadyScored = CreditScore::where('source_type', Loan::class)
        ->where('source_id', $loan->id)
        ->exists();

    if ($alreadyScored) {
        return; // No need to update if the score already exists for this loan
    }

    // Get the latest repayment date, assuming repayments are sorted by 'created_at'
    $repaymentDate = optional($loan->repayments()->latest('created_at')->first())->created_at ?? now();
    $dueDate = $loan->due_date;

    // Default to 50 if no prior score exists
    $currentScore = $user->creditScores()->latest()->value('current_score') ?? 50;

    // Determine if repayment is on time and adjust the score
    $onTime = $repaymentDate->lte($dueDate);
    $scoreChange = $onTime ? 10 : -5;

    // Calculate the new credit score, clamped between 1 and 100
    $newScore = max(1, min(100, $currentScore + $scoreChange));

    // Create a new credit score entry
    CreditScore::create([
        'user_id'       => $user->id,
        'score_change'  => $scoreChange,
        'current_score' => $newScore,
        'reason'        => $onTime ? 'Repayment on time' : 'Late repayment',
        'source_type'   => Loan::class,
        'source_id'     => $loan->id,
    ]);
}

    
     


    /**
     * Handle validation exception responses
     */
    public function handleValidationException(ValidationException $e)
    {
        return response()->json([
            'status' => false,
            'message' => 'Validation failed.',
            'errors' => $e->errors(),
        ], 422);
    }

    /**
     * Handle general exception responses
     */
    public function handleGeneralException(Throwable $e, string $fallbackMessage = 'An unexpected error occurred.')
    {
        return response()->json([
            'status' => false,
            'message' => $fallbackMessage,
            'error' => config('app.debug') ? [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTrace(),
            ] : null,
        ], 500);
    }
}
