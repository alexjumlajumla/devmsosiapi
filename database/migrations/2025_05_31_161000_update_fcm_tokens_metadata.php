<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class UpdateFcmTokensMetadata extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // No schema changes needed as we're using JSON fields
        // This migration is just for reference and to document the change
        
        // If you need to add a new column to store FCM tokens, you can do it like this:
        // Schema::table('users', function (Blueprint $table) {
        //     $table->json('fcm_tokens_metadata')->nullable()->after('firebase_token');
        // });
        
        // Note: We're using the existing firebase_token column which is already JSON
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // If you added a new column in the up() method, drop it here
        // Schema::table('users', function (Blueprint $table) {
        //     $table->dropColumn('fcm_tokens_metadata');
        // });
    }
}
