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
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes($table->getTable());

            // Add composite index for faster queries on user notifications
            $userStatusCreatedIndex = 'push_notifications_user_id_status_created_at_index';
            if (!array_key_exists($userStatusCreatedIndex, $indexes)) {
                $table->index(['user_id', 'status', 'created_at']);
            }
            
            // Add index for cleanup queries
            $statusCreatedIndex = 'push_notifications_status_created_at_index';
            if (!array_key_exists($statusCreatedIndex, $indexes)) {
                $table->index(['status', 'created_at']);
            }
            
            // Add index for retry logic
            $statusLastRetryIndex = 'push_notifications_status_last_retry_at_index';
            if (!array_key_exists($statusLastRetryIndex, $indexes)) {
                $table->index(['status', 'last_retry_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_notifications', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes($table->getTable());
            
            $indexesToDrop = [
                'push_notifications_user_id_status_created_at_index',
                'push_notifications_status_created_at_index',
                'push_notifications_status_last_retry_at_index',
            ];

            foreach ($indexesToDrop as $index) {
                if (array_key_exists($index, $indexes)) {
                    $table->dropIndex($index);
                }
            }
        });
    }
};
