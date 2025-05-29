<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveUniqueConstraintFromNotificationsType extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Remove the unique constraint from the type column
            $table->dropUnique('notifications_type_unique');
            
            // Change the column to a regular enum without unique
            $table->enum('type', \App\Models\Notification::TYPES)->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Add back the unique constraint if we need to rollback
            $table->unique('type');
        });
    }
}
