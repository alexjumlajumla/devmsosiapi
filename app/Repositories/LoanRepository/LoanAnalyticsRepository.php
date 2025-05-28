<?php

namespace App\Repositories\LoanRepository;

use App\Models\Loan;
use App\Models\LoanRepayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class LoanAnalyticsRepository
{
    /**
     * Get loan statistics for dashboard
     */
    public function getStatistics(array $filter = []): array
    {
        $dateFrom = date('Y-m-d 00:00:01', strtotime(data_get($filter, 'date_from', '-30 days')));
        $dateTo = date('Y-m-d 23:59:59', strtotime(data_get($filter, 'date_to', 'now')));

        $loans = Loan::where('created_at', '>=', $dateFrom)
            ->where('created_at', '<=', $dateTo)
            ->select([
                DB::raw('COUNT(*) as total_loans'),
                DB::raw('SUM(amount) as total_amount'),
                DB::raw('SUM(repayment_amount) as total_repayment_amount'),
                DB::raw("COUNT(CASE WHEN status = 'active' THEN 1 END) as active_loans"),
                DB::raw("COUNT(CASE WHEN status = 'repaid' THEN 1 END) as repaid_loans"),
                DB::raw("COUNT(CASE WHEN status = 'defaulted' THEN 1 END) as defaulted_loans"),
            ])
            ->first();

        $repayments = LoanRepayment::where('created_at', '>=', $dateFrom)
            ->where('created_at', '<=', $dateTo)
            ->sum('amount');

        return [
            'total_loans' => $loans->total_loans ?? 0,
            'total_amount' => $loans->total_amount ?? 0,
            'total_repayment_amount' => $loans->total_repayment_amount ?? 0,
            'total_repaid' => $repayments ?? 0,
            'active_loans' => $loans->active_loans ?? 0,
            'repaid_loans' => $loans->repaid_loans ?? 0,
            'defaulted_loans' => $loans->defaulted_loans ?? 0,
        ];
    }

    /**
     * Get loan disbursement chart data
     */
    public function getDisbursementChart(array $filter = []): Collection
    {
        $dateFrom = date('Y-m-d 00:00:01', strtotime(data_get($filter, 'date_from', '-30 days')));
        $dateTo = date('Y-m-d 23:59:59', strtotime(data_get($filter, 'date_to', 'now')));
        $type = data_get($filter, 'type', 'day');

        $format = match ($type) {
            'year' => '%Y',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        return Loan::where('created_at', '>=', $dateFrom)
            ->where('created_at', '<=', $dateTo)
            ->select([
                DB::raw("DATE_FORMAT(created_at, '$format') as time"),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as amount'),
                DB::raw('SUM(repayment_amount) as repayment_amount'),
            ])
            ->groupBy('time')
            ->orderBy('time')
            ->get();
    }

    /**
     * Get loan repayment chart data
     */
    public function getRepaymentChart(array $filter = []): Collection
    {
        $dateFrom = date('Y-m-d 00:00:01', strtotime(data_get($filter, 'date_from', '-30 days')));
        $dateTo = date('Y-m-d 23:59:59', strtotime(data_get($filter, 'date_to', 'now')));
        $type = data_get($filter, 'type', 'day');

        $format = match ($type) {
            'year' => '%Y',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        return LoanRepayment::where('created_at', '>=', $dateFrom)
            ->where('created_at', '<=', $dateTo)
            ->select([
                DB::raw("DATE_FORMAT(created_at, '$format') as time"),
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(amount) as amount'),
            ])
            ->groupBy('time')
            ->orderBy('time')
            ->get();
    }

    /**
     * Get loan status distribution
     */
    public function getStatusDistribution(): array
    {
        $statuses = Loan::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get();

        return [
            'labels' => $statuses->pluck('status')->toArray(),
            'data' => $statuses->pluck('count')->toArray(),
        ];
    }

    /**
     * Get payment method distribution
     */
    public function getPaymentMethodDistribution(): array
    {
        $methods = LoanRepayment::select('payment_method', DB::raw('COUNT(*) as count'))
            ->groupBy('payment_method')
            ->get();

        return [
            'labels' => $methods->pluck('payment_method')->toArray(),
            'data' => $methods->pluck('count')->toArray(),
        ];
    }
} 