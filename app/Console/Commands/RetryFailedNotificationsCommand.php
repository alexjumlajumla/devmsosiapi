<?php

namespace App\Console\Commands;

use App\Jobs\RetryFailedNotifications;
use Illuminate\Console\Command;

class RetryFailedNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:retry-failed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retry sending failed push notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Dispatching job to retry failed notifications...');
        
        try {
            RetryFailedNotifications::dispatch();
            $this->info('Job to retry failed notifications has been queued.');
            return 0;
        } catch (\Throwable $e) {
            $this->error('Failed to queue retry job: ' . $e->getMessage());
            return 1;
        }
    }
}
