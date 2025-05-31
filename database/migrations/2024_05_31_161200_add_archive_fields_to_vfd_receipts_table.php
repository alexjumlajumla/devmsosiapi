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
        Schema::table('vfd_receipts', function (Blueprint $table) {
            $table->timestamp('synced_to_archive_at')->nullable()->after('error_message');
            $table->text('sync_error')->nullable()->after('synced_to_archive_at');
            $table->index('synced_to_archive_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vfd_receipts', function (Blueprint $table) {
            $table->dropIndex(['synced_to_archive_at']);
            $table->dropColumn(['synced_to_archive_at', 'sync_error']);
        });
    }
};
