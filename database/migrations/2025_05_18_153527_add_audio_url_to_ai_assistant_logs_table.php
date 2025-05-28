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
        Schema::table('ai_assistant_logs', function (Blueprint $table) {
            $table->string('audio_url')->nullable()->after('session_id')
                  ->comment('S3 URL of the stored audio file for voice orders');
            $table->string('audio_format')->nullable()->after('audio_url')
                  ->comment('Format of the audio file (mp3, wav, webm, etc.)');
            $table->boolean('audio_stored')->default(false)->after('audio_format')
                  ->comment('Whether the audio file was successfully stored');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_assistant_logs', function (Blueprint $table) {
            $table->dropColumn('audio_url');
            $table->dropColumn('audio_format');
            $table->dropColumn('audio_stored');
        });
    }
};
