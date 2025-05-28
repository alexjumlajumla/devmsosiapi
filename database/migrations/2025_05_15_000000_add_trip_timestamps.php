<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->timestamp('started_at')->nullable()->after('scheduled_at');
            $table->timestamp('completed_at')->nullable()->after('started_at');
        });

        Schema::table('trip_locations', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'completed_at']);
        });

        Schema::table('trip_locations', function (Blueprint $table) {
            $table->dropColumn('arrived_at');
        });
    }
}; 