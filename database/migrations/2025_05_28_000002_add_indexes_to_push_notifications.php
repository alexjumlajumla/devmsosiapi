<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('push_notifications', function (Blueprint $table) {
            // Add composite index for faster queries on user notifications
            $table->index(['user_id', 'status', 'created_at']);
            
            // Add index for cleanup queries
            $table->index(['status', 'created_at']);
            
            // Add index for retry logic
            $table->index(['status', 'last_retry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'created_at']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['status', 'last_retry_at']);
        });
    }
};
