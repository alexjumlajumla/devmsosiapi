<?php

namespace App\Services\LoanService;

use App\Helpers\ResponseError;
use App\Models\Loan;
use App\Models\User;
use App\Models\WalletHistory;
use App\Services\CoreService;
use App\Services\WalletHistoryService\WalletHistoryService;
use DB;
use Exception;
use Throwable;

class LoanService extends CoreService
{
    protected function getModelClass(): string
    {
        return Loan::class;
    }

    /**
     * Disburse a loan to a seller
     */
    public function disburseLoan(array $data): array
    {
        try {
            return DB::transaction(function () use ($data) {
                /** @var User $seller */
                $seller = User::find(data_get($data, 'user_id'));
                
                if (!$seller?->wallet) {
                    return [
                        'status'  => false,
                        'code'    => ResponseError::ERROR_108,
                        'message' => __('errors.' . ResponseError::ERROR_108, locale: $this->language)
                    ];
                }

                $amount = data_get($data, 'amount');
                $interestRate = data_get($data, 'interest_rate');

                /** @var Loan $loan */
                $loan = $this->model()->create([
                    'user_id' => $seller->id,
                    'amount' => $amount,
                    'interest_rate' => $interestRate,
                    'repayment_amount' => $amount + ($amount * ($interestRate / 100)),
                    'disbursed_by' => auth('sanctum')->id(),
                    'disbursed_at' => now(),
                    'due_date' => data_get($data, 'due_date'),
                    'status' => Loan::STATUS_ACTIVE,
                ]);

                // Credit seller's wallet
                (new WalletHistoryService)->create([
                    'type'   => 'topup',
                    'price'  => $amount,
                    'note'   => "Loan disbursement #$loan->id",
                    'status' => WalletHistory::PAID,
                    'user'   => $seller,
                ]);

                return [
                    'status' => true,
                    'code' => ResponseError::NO_ERROR,
                    'data' => $loan
                ];
            });
        } catch (Throwable $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_501,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Record a loan repayment
     */
    public function recordRepayment(array $data): array
    {
        try {
            return DB::transaction(function () use ($data) {
                /** @var Loan $loan */
                $loan = $this->model()->find(data_get($data, 'loan_id'));
                
                if (!$loan) {
                    return [
                        'status'  => false,
                        'code'    => ResponseError::ERROR_404,
                        'message' => __('errors.' . ResponseError::ERROR_404, locale: $this->language)
                    ];
                }

                $amount = data_get($data, 'amount');

                // Create repayment record
                $repayment = $loan->repayments()->create([
                    'user_id' => $loan->user_id,
                    'amount' => $amount,
                    'payment_method' => data_get($data, 'payment_method'),
                    'recorded_by' => auth('sanctum')->id(),
                    'paid_at' => now(),
                ]);

                // If payment is through wallet, deduct from wallet
                if (data_get($data, 'payment_method') === 'wallet') {
                    (new WalletHistoryService)->create([
                        'type'   => 'withdraw',
                        'price'  => $amount,
                        'note'   => "Loan repayment #$loan->id",
                        'status' => WalletHistory::PAID,
                        'user'   => $loan->user,
                    ]);
                }

                // Check if loan is fully repaid
                if ($loan->remaining_amount <= 0) {
                    $loan->update(['status' => Loan::STATUS_REPAID]);
                }

                return [
                    'status' => true,
                    'code' => ResponseError::NO_ERROR,
                    'data' => $repayment
                ];
            });
        } catch (Throwable $e) {
            $this->error($e);
            return [
                'status'  => false,
                'code'    => ResponseError::ERROR_501,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Check for overdue loans and update status
     */
    public function checkOverdueLoans(): void
    {
        $this->model()
            ->where('status', Loan::STATUS_ACTIVE)
            ->where('due_date', '<', now())
            ->update(['status' => Loan::STATUS_DEFAULTED]);
    }

    /**
     * Get loan statistics for a user
     */
    public function getUserLoanStats(int $userId): array
    {
        $loans = $this->model()
            ->where('user_id', $userId)
            ->get();

        return [
            'total_loans' => $loans->count(),
            'active_loans' => $loans->where('status', Loan::STATUS_ACTIVE)->count(),
            'repaid_loans' => $loans->where('status', Loan::STATUS_REPAID)->count(),
            'defaulted_loans' => $loans->where('status', Loan::STATUS_DEFAULTED)->count(),
            'total_borrowed' => $loans->sum('amount'),
            'total_repaid' => $loans->sum(function ($loan) {
                return $loan->repayments->sum('amount');
            }),
        ];
    }
} 