<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            if (!Schema::hasColumn('broadcasts', 'custom_emails')) {
                $table->json('custom_emails')->nullable()->after('groups');
            }
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            if (Schema::hasColumn('broadcasts', 'custom_emails')) {
                $table->dropColumn('custom_emails');
            }
        });
    }
}; 