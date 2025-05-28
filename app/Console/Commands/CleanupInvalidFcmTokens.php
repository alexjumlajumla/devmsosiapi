<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FCM\FcmTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupInvalidFcmTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:cleanup-tokens {--dry-run : List invalid tokens without removing them}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and remove invalid FCM tokens from the database';

    /**
     * @var FcmTokenService
     */
    protected $fcmTokenService;

    /**
     * Create a new command instance.
     *
     * @param FcmTokenService $fcmTokenService
     * @return void
     */
    public function __construct(FcmTokenService $fcmTokenService)
    {
        parent::__construct();
        $this->fcmTokenService = $fcmTokenService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $users = User::whereNotNull('firebase_token')->get();
        $invalidTokens = [];
        $totalInvalid = 0;
        $totalUsers = 0;

        $this->info('Scanning for invalid FCM tokens...');
        $this->newLine();

        $progressBar = $this->output->createProgressBar($users->count());
        $progressBar->start();

        foreach ($users as $user) {
            $tokens = $user->firebase_token;
            
            if (empty($tokens)) {
                $progressBar->advance();
                continue;
            }

            if (!is_array($tokens)) {
                $tokens = [$tokens];
            }

            $invalidUserTokens = [];
            
            foreach ($tokens as $token) {
                if (!$user->isValidFcmToken($token)) {
                    $invalidUserTokens[] = [
                        'token' => $token,
                        'error' => 'Invalid token format'
                    ];
                }
            }


            if (!empty($invalidUserTokens)) {
                $totalInvalid += count($invalidUserTokens);
                $totalUsers++;
                $invalidTokens[$user->id] = [
                    'user' => $user->only(['id', 'firstname', 'lastname', 'email']),
                    'tokens' => $invalidUserTokens
                ];
                
                if (!$dryRun) {
                    // Remove invalid tokens
                    $validTokens = array_diff($tokens, array_column($invalidUserTokens, 'token'));
                    $user->firebase_token = array_values($validTokens);
                    $user->save();
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if (empty($invalidTokens)) {
            $this->info('No invalid FCM tokens found.');
            return 0;
        }

        $this->warn(sprintf(
            'Found %d invalid token(s) across %d user(s).',
            $totalInvalid,
            $totalUsers
        ));

        if ($dryRun) {
            $this->info('\nThis was a dry run. No changes were made to the database.');
            $this->info('Remove the --dry-run option to remove these tokens.');
        } else {
            $this->info(sprintf('\nSuccessfully removed %d invalid token(s).', $totalInvalid));
            
            // Log the cleanup
            Log::info('Cleaned up invalid FCM tokens', [
                'total_invalid' => $totalInvalid,
                'affected_users' => $totalUsers,
                'dry_run' => $dryRun
            ]);
        }

        $this->newLine();
        $this->table(
            ['User ID', 'Name', 'Email', 'Invalid Tokens'],
            collect($invalidTokens)->map(function ($item) {
                return [
                    $item['user']['id'],
                    trim($item['user']['firstname'] . ' ' . $item['user']['lastname']),
                    $item['user']['email'],
                    count($item['tokens'])
                ];
            })
        );

        return 0;
    }
}
