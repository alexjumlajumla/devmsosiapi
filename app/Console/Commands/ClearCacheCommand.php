<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ClearCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-all-cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear all application caches';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Clearing all caches...');
        
        $this->call('config:clear');
        $this->info('✓ Config cache cleared');
        
        $this->call('cache:clear');
        $this->info('✓ Application cache cleared');
        
        $this->call('route:clear');
        $this->info('✓ Route cache cleared');
        
        $this->call('view:clear');
        $this->info('✓ View cache cleared');
        
        $this->call('config:cache');
        $this->info('✓ Config cached successfully');
        
        $this->newLine();
        $this->info('All caches have been cleared and rebuilt!');
        
        return 0;
    }
}
