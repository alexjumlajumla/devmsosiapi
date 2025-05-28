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
            $table->unsignedInteger('retry_attempts')->nullable()->after('error_message');
            $table->timestamp('last_retry_at')->nullable()->after('retry_attempts');
            
            // Add index for faster queries on retry logic
            $table->index(['status', 'last_retry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_notifications', function (Blueprint $table) {
            $table->dropColumn(['retry_attempts', 'last_retry_at']);
            $table->dropIndex(['status', 'last_retry_at']);
        });
    }
};
