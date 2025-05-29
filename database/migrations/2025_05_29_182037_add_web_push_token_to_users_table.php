<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddWebPushTokenToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Add a JSON column to store web push tokens
            // This allows storing multiple tokens per user (for multiple devices/browsers)
            $table->json('web_push_token')->nullable()->after('firebase_token');
            
            // Add index for faster lookups
            $table->index('web_push_token', 'users_web_push_token_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the index first
            $table->dropIndex('users_web_push_token_index');
            
            // Then drop the column
            $table->dropColumn('web_push_token');
        });
    }
}
