<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FCM\FcmTokenServiceInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestFcmTokenService extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:test-service {userId} {token} {action=add} {--D|device=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test the FCM token service';

    /**
     * Execute the console command.
     */
    public function handle(FcmTokenServiceInterface $fcmService)
    {
        $userId = $this->argument('userId');
        $token = $this->argument('token');
        $action = $this->argument('action');
        $deviceId = $this->option('device');

        $user = User::findOrFail($userId);

        $this->info("Testing FCM Token Service for User #{$user->id} ({$user->email})");
        $this->line("Action: {$action}");
        $this->line("Token: " . substr($token, 0, 10) . '...' . substr($token, -5));
        if ($deviceId) {
            $this->line("Device ID: {$deviceId}");
        }

        try {
            switch ($action) {
                case 'add':
                    $result = $fcmService->addToken($user, $token, $deviceId);
                    $message = $result ? 'Token added successfully' : 'Failed to add token';
                    $this->{$result ? 'info' : 'error'}($message);
                    break;

                case 'remove':
                    $result = $fcmService->removeToken($user, $token);
                    $message = $result ? 'Token removed successfully' : 'Failed to remove token (may not exist)';
                    $this->{$result ? 'info' : 'warn'}($message);
                    break;

                case 'list':
                    $tokens = $fcmService->getUserTokensWithMetadata($user);
                    $this->info("User has " . count($tokens) . " FCM tokens:");
                    
                    $rows = [];
                    foreach ($tokens as $i => $tokenData) {
                        $rows[] = [
                            '#' => $i + 1,
                            'token' => substr($tokenData['token'], 0, 10) . '...' . substr($tokenData['token'], -5),
                            'platform' => $tokenData['platform'] ?? 'unknown',
                            'device_id' => $tokenData['device_id'] ?? 'N/A',
                            'created_at' => $tokenData['created_at'] ?? 'N/A',
                            'last_used' => $tokenData['last_used_at'] ?? 'N/A',
                        ];
                    }
                    
                    $this->table(
                        ['#', 'Token', 'Platform', 'Device ID', 'Created At', 'Last Used'],
                        $rows
                    );
                    return 0;

                default:
                    $this->error("Invalid action. Use 'add', 'remove', or 'list'.");
                    return 1;
            }

            // Show updated token list
            $tokens = $fcmService->getUserTokensWithMetadata($user);
            $this->info("\nUser now has " . count($tokens) . " FCM tokens");
            
            return 0;

        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
