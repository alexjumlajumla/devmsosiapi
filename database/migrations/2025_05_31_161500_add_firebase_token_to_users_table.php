<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFirebaseTokenToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('users', 'firebase_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('firebase_token')->nullable()->after('remember_token');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('users', 'firebase_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('firebase_token');
            });
        }
    }
}
