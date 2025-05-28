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
            $table->string('status')->default('pending')->after('user_id');
            $table->timestamp('sent_at')->nullable()->after('status');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->string('error_message')->nullable()->after('read_at');
            $table->index('status');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('push_notifications', function (Blueprint $table) {
            $table->dropColumn(['status', 'sent_at', 'delivered_at', 'error_message']);
            $table->dropIndex(['status', 'user_id']);
        });
    }
};
