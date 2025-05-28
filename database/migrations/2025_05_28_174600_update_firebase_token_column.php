<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Create a backup of existing tokens
        \DB::statement('CREATE TABLE IF NOT EXISTS user_firebase_tokens_backup_'.date('YmdHis').' AS SELECT id, firebase_token FROM users WHERE firebase_token IS NOT NULL');

        // Update the column to JSON type
        Schema::table('users', function (Blueprint $table) {
            $table->json('firebase_token_new')->nullable()->after('firebase_token');
        });

        // Convert existing tokens to JSON array format
        $users = \DB::table('users')
            ->whereNotNull('firebase_token')
            ->get();

        foreach ($users as $user) {
            $token = $user->firebase_token;
            
            // Clean up the token
            $token = trim($token, '[]"\'');
            
            // Only process if token is not empty
            if (!empty($token)) {
                \DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'firebase_token_new' => json_encode([$token])
                    ]);
            }
        }

        // Drop the old column and rename the new one
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('firebase_token');
            $table->renameColumn('firebase_token_new', 'firebase_token');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // There's no safe way to revert this, as we can't guarantee the original format
        // A backup table will be created with the original data
    }
};
