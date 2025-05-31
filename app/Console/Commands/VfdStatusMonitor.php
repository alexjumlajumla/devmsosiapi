<?php

namespace App\Console\Commands;

use App\Models\VfdReceipt;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VfdStatusMonitor extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vfd:monitor {--hours=24 : Number of hours to look back} {--status=pending : Status to monitor (pending, failed, all)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor the status of VFD receipts';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $hours = (int) $this->option('hours');
        $status = $this->option('status');
        
        $query = VfdReceipt::where('created_at', '>=', now()->subHours($hours))
            ->orderBy('created_at', 'desc');
            
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        
        $receipts = $query->get();
        
        if ($receipts->isEmpty()) {
            $this->info("No receipts found in the last {$hours} hours with status: {$status}");
            return 0;
        }
        
        $statusCounts = $receipts->groupBy('status')
            ->map(function ($group) {
                return $group->count();
            });
        
        $this->info("VFD Receipts Summary (Last {$hours} hours):");
        $this->line(str_repeat('-', 50));
        
        foreach ($statusCounts as $status => $count) {
            $this->line(sprintf("%-15s: %d", ucfirst($status), $count));
        }
        
        $this->newLine();
        $this->info("Recent Receipts:");
        $this->line(str_repeat('-', 120));
        $this->line(sprintf(
            "%-12s %-20s %-15s %-10s %-40s %-15s",
            'ID', 'Number', 'Type', 'Amount', 'Status', 'Created'
        ));
        $this->line(str_repeat('-', 120));
        
        foreach ($receipts as $receipt) {
            $this->line(sprintf(
                "%-12s %-20s %-15s %-10s %-40s %-15s",
                $receipt->id,
                $receipt->receipt_number,
                $receipt->receipt_type,
                number_format($receipt->amount / 100, 2) . ' TZS',
                $this->formatStatus($receipt->status),
                $receipt->created_at->diffForHumans()
            ));
            
            if ($receipt->status === 'failed' && $receipt->error_message) {
                $this->line("    Error: " . $receipt->error_message);
            }
        }
        
        $this->line(str_repeat('-', 120));
        $this->newLine();
        
        // Check for potential issues
        $pendingCount = $receipts->where('status', 'pending')->count();
        $failedCount = $receipts->where('status', 'failed')->count();
        
        if ($pendingCount > 10) {
            $this->warn("⚠️  High number of pending receipts: {$pendingCount}. Consider checking the queue worker.");
        }
        
        if ($failedCount > 0) {
            $this->error("❌ Failed receipts detected: {$failedCount}. Run 'php artisan vfd:retry-failed' to retry.");
        }
        
        return 0;
    }
    
    /**
     * Format status with color
     * 
     * @param string $status
     * @return string
     */
    protected function formatStatus($status)
    {
        switch (strtolower($status)) {
            case 'generated':
                return "<fg=green>" . strtoupper($status) . "</>";
            case 'failed':
                return "<fg=red>" . strtoupper($status) . "</>";
            case 'pending':
                return "<fg=yellow>" . strtoupper($status) . "</>";
            default:
                return strtoupper($status);
        }
    }
}
