<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateTestFcmTokens extends Command
{
    protected $signature = 'fcm:tokens:update-test';
    protected $description = 'Update test FCM tokens for admin and shop owner users';

    public function handle()
    {
        // Update admin user (Alex Makule)
        $admin = User::find(101);
        if ($admin) {
            $admin->addFcmToken('test_fcm_token_admin_101');
            $admin->save();
            $this->info('Updated FCM token for admin user (ID: 101)');
            Log::info('Updated FCM token for admin user', ['user_id' => 101]);
        } else {
            $this->error('Admin user not found (ID: 101)');
        }

        // Update shop owner (Sokoni Investments)
        $shopOwner = User::find(110);
        if ($shopOwner) {
            $shopOwner->addFcmToken('test_fcm_token_shop_owner_110');
            $shopOwner->save();
            $this->info('Updated FCM token for shop owner (ID: 110)');
            Log::info('Updated FCM token for shop owner', ['user_id' => 110]);
        } else {
            $this->error('Shop owner not found (ID: 110)');
        }

        // Verify the updates
        $this->line('\nVerifying token updates:');
        $this->verifyTokenUpdates();

        return Command::SUCCESS;
    }

    protected function verifyTokenUpdates()
    {
        $admin = User::find(101);
        $shopOwner = User::find(110);

        $this->table(
            ['User ID', 'Name', 'Email', 'Role', 'FCM Tokens Count', 'Sample Token'],
            [
                [
                    $admin->id ?? 'N/A',
                    $admin ? $admin->firstname . ' ' . $admin->lastname : 'N/A',
                    $admin->email ?? 'N/A',
                    $admin ? $admin->getRoleAttribute() : 'N/A',
                    $admin ? count($admin->firebase_token ?? []) : 0,
                    $admin ? substr(($admin->firebase_token[0] ?? 'No token'), 0, 20) . '...' : 'N/A'
                ],
                [
                    $shopOwner->id ?? 'N/A',
                    $shopOwner ? $shopOwner->firstname . ' ' . $shopOwner->lastname : 'N/A',
                    $shopOwner->email ?? 'N/A',
                    $shopOwner ? $shopOwner->getRoleAttribute() : 'N/A',
                    $shopOwner ? count($shopOwner->firebase_token ?? []) : 0,
                    $shopOwner ? substr(($shopOwner->firebase_token[0] ?? 'No token'), 0, 20) . '...' : 'N/A'
                ]
            ]
        );
    }
}
