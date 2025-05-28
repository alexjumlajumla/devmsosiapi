<?php

namespace App\Services;

use App\Models\{Loan, LoanRepayment, Order, User, WalletHistory};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreditScoreService
{
    public function recalculateForSeller(User $seller): int
    {
        // Timely repayment ratio
        $totalLoans = Loan::where('user_id', $seller->id)->count();
        $loansRepaidOnTime = Loan::where('user_id', $seller->id)
            ->where('status', Loan::STATUS_REPAID)
            ->whereColumn('due_date', '>=', 'updated_at')
            ->count();
        $timelyRepaymentRatio = $totalLoans ? $loansRepaidOnTime / $totalLoans : 0;

        // Repayment completion
        $totRequired = Loan::where('user_id', $seller->id)->sum('repayment_amount');
        $totRepaid   = LoanRepayment::where('user_id', $seller->id)->sum('amount');
        $repaymentCompletionRatio = $totRequired ? min(1, $totRepaid / $totRequired) : 0;

        // Order growth (simple MoM)
        $startOfMonth = Carbon::now()->startOfMonth();
        $prevStart    = Carbon::now()->subMonth()->startOfMonth();
        $prevEnd      = Carbon::now()->subMonth()->endOfMonth();
        $currentOrders = Order::where('shop_id', $seller->shop_id)->whereBetween('created_at', [$startOfMonth, now()])->count();
        $prevOrders    = Order::where('shop_id', $seller->shop_id)->whereBetween('created_at', [$prevStart, $prevEnd])->count();
        $orderGrowthScore = $currentOrders > $prevOrders ? 1 : 0;

        // Wallet top-ups via gateway (non‐cash) this month
        $walletTopUps = WalletHistory::where('user_id', $seller->id)
            ->where('type', 'topup')
            ->whereBetween('created_at', [$startOfMonth, now()])
            ->count();
        $walletTopupScore = min(1, $walletTopUps / 5);

        // Customer retention
        $totalCustomers   = Order::where('shop_id', $seller->shop_id)->distinct('user_id')->count('user_id');
        $repeatCustomers  = Order::where('shop_id', $seller->shop_id)
            ->groupBy('user_id')
            ->havingRaw('COUNT(id) > 1')
            ->get()->count();
        $customerRetentionScore = $totalCustomers ? $repeatCustomers / $totalCustomers : 0;

        // Late payment penalty
        $overdueLoans = Loan::where('user_id', $seller->id)->where('status', Loan::STATUS_DEFAULTED)->count();
        $latePaymentPenalty = $totalLoans ? $overdueLoans / $totalLoans : 0;

        // Admin flag penalty – assume column flag_credit == true
        $adminFlagPenalty = $seller->flag_credit ? 1 : 0;

        $score = (
            ($timelyRepaymentRatio * 40) +
            ($repaymentCompletionRatio * 20) +
            ($orderGrowthScore * 15) +
            ($walletTopupScore * 10) +
            ($customerRetentionScore * 10) -
            ($latePaymentPenalty * 15) -
            ($adminFlagPenalty * 20)
        );

        $score = max(0, min(100, round($score)));
        $seller->update(['credit_score' => $score]);
        return $score;
    }

    public function recalculateAll(): void
    {
        User::where('role', 'seller')->chunkById(100, function ($sellers) {
            foreach ($sellers as $seller) {
                $this->recalculateForSeller($seller);
            }
        });
    }
} 