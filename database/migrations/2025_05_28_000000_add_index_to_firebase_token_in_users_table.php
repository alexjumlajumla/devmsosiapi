<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddIndexToFirebaseTokenInUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Check if the index doesn't exist before creating it
        Schema::table('users', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes('users');
            
            if (!array_key_exists('users_firebase_token_index', $indexes)) {
                $table->index('firebase_token', 'users_firebase_token_index');
            }
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
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes('users');
            
            if (array_key_exists('users_firebase_token_index', $indexes)) {
                $table->dropIndex('users_firebase_token_index');
            }
        });
    }
}
