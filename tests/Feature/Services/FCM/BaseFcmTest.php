<?php

namespace Tests\Feature\Services\FCM;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BaseFcmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create the users table if it doesn't exist
        if (!\Schema::hasTable('users')) {
            // Load the users table migration
            $this->artisan('migrate:install');
            $this->artisan('migrate', ['--path' => 'database/migrations/2014_10_12_000000_create_users_table.php']);
        }
        
        // Run our specific migration for firebase_token
        if (!\Schema::hasColumn('users', 'firebase_token')) {
            $this->artisan('migrate', ['--path' => 'database/migrations/2025_05_31_161500_add_firebase_token_to_users_table.php']);
        }
    }
}
