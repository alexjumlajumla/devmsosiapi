<?php

namespace App\Console\Commands;

use App\Models\AIAssistantLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupVoiceRecordings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'voice:cleanup-recordings 
                            {--days=30 : Number of days to retain recordings}
                            {--dry-run : Simulate deletion without actually removing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete voice recordings from S3 that are older than the specified retention period';

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
     */
    public function handle()
    {
        $retentionDays = $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoffDate = Carbon::now()->subDays($retentionDays);
        
        $this->info("Starting voice recording cleanup for files older than {$retentionDays} days");
        if ($dryRun) {
            $this->warn("DRY RUN MODE - No files will be deleted");
        }
        
        // Find logs with audio URLs older than the cutoff date
        $query = AIAssistantLog::where('created_at', '<', $cutoffDate)
            ->where('audio_stored', true)
            ->whereNotNull('audio_url');
            
        $count = $query->count();
        $this->info("Found {$count} audio files to process");
        
        if ($count === 0) {
            $this->info("No audio files need cleanup");
            return 0;
        }
        
        $deleted = 0;
        $errors = 0;
        $skipped = 0;
        
        // Process in batches to avoid memory issues
        $query->chunkById(100, function ($logs) use (&$deleted, &$errors, &$skipped, $dryRun) {
            foreach ($logs as $log) {
                try {
                    $url = $log->audio_url;
                    
                    // Extract the S3 path from the URL
                    $path = parse_url($url, PHP_URL_PATH);
                    $path = ltrim($path, '/');
                    
                    // Extract bucket name if it's part of the URL
                    $host = parse_url($url, PHP_URL_HOST);
                    if (strpos($host, '.s3.') !== false) {
                        // The bucket is part of the host, extract just the path
                        $parts = explode('/', $path);
                        $path = implode('/', array_slice($parts, 1)); // Skip the bucket name
                    }
                    
                    $this->info("Processing: {$path}");
                    
                    if (!$dryRun) {
                        // Delete from S3
                        if (Storage::disk('s3')->exists($path)) {
                            Storage::disk('s3')->delete($path);
                            
                            // Update the log record
                            $log->update([
                                'audio_stored' => false,
                                'audio_url' => null,
                                'metadata' => array_merge($log->metadata ?? [], [
                                    'audio_deleted_at' => Carbon::now()->toIso8601String(),
                                    'original_audio_url' => $url
                                ])
                            ]);
                            
                            $deleted++;
                            $this->info("Deleted: {$path}");
                        } else {
                            $this->warn("File not found in S3: {$path}");
                            $skipped++;
                        }
                    } else {
                        $this->line("Would delete: {$path}");
                        $deleted++; // Count as deleted for dry run statistics
                    }
                } catch (\Exception $e) {
                    $this->error("Error processing {$log->id}: {$e->getMessage()}");
                    Log::error("Voice recording cleanup error", [
                        'log_id' => $log->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $errors++;
                }
            }
        });
        
        $this->info("Cleanup completed:");
        $this->info("- Files processed: {$count}");
        $this->info("- Files " . ($dryRun ? "that would be deleted" : "deleted") . ": {$deleted}");
        $this->info("- Files skipped: {$skipped}");
        $this->info("- Errors: {$errors}");
        
        return 0;
    }
}
