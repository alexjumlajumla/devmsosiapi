<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing records to have default status
        DB::table('push_notifications')
            ->whereNull('status')
            ->update(['status' => 'sent']);
            
        // Set default values for existing records
        DB::table('push_notifications')
            ->whereNull('retry_attempts')
            ->update(['retry_attempts' => 0]);
            
        // Add any other necessary updates for existing data
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to reverse data updates
    }
};
