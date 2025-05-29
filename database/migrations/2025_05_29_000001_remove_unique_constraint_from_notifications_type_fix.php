<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RemoveUniqueConstraintFromNotificationsTypeFix extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // First, drop the unique constraint
        Schema::table('notifications', function ($table) {
            $table->dropUnique('notifications_type_unique');
        });
        
        // Then modify the column to remove the unique constraint
        // We'll use raw SQL to avoid the enum type issue
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE notifications MODIFY COLUMN `type` VARCHAR(255) NOT NULL");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN type TYPE VARCHAR(255)');
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // First, change the column back to enum
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        
        if ($driver === 'mysql') {
            // Get the enum values from the model
            $enumValues = \App\Models\Notification::TYPES;
            $enumString = "ENUM('" . implode("','", $enumValues) . "')";
            
            DB::statement("ALTER TABLE notifications MODIFY COLUMN `type` {$enumString} NOT NULL");
        } elseif ($driver === 'pgsql') {
            // For PostgreSQL, we'll use a check constraint instead of enum
            $enumValues = \App\Models\Notification::TYPES;
            $checkValues = "'" . implode("', '", $enumValues) . "'";
            
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type::text = ANY (ARRAY[{$checkValues}]::text[]))");
        }
        
        // Then add back the unique constraint
        Schema::table('notifications', function ($table) {
            $table->unique('type');
        });
    }
}
