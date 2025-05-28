<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CreditScoreService;

class RecalculateCreditScore extends Command
{
    protected $signature = 'credit-score:recalculate';

    protected $description = 'Recalculate credit scores for all sellers';

    public function handle(CreditScoreService $service): int
    {
        $this->info('Recalculating credit scores...');
        $service->recalculateAll();
        $this->info('Done!');
        return Command::SUCCESS;
    }
} 