<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class FixSmsCodesTableStructure extends Migration
{
    public function up()
    {
        // Create backup table if it doesn't exist
        if (!Schema::hasTable('sms_codes_backup_20250531')) {
            DB::statement('CREATE TABLE sms_codes_backup_20250531 LIKE sms_codes');
            DB::statement('INSERT sms_codes_backup_20250531 SELECT * FROM sms_codes');
        }

        // Add indexes if they don't exist
        Schema::table('sms_codes', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            $indexes = $sm->listTableIndexes('sms_codes');
            
            if (!isset($indexes['sms_codes_verifyid_index'])) {
                $table->index('verifyId', 'sms_codes_verifyid_index');
            }
            
            if (!isset($indexes['sms_codes_phone_index'])) {
                $table->index('phone', 'sms_codes_phone_index');
            }

            if (!Schema::hasColumn('sms_codes', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // Convert columns
        DB::statement("
            ALTER TABLE sms_codes 
            MODIFY COLUMN expiredAt TIMESTAMP NULL DEFAULT NULL,
            MODIFY COLUMN OTPCode VARCHAR(10) NOT NULL
        ");

        // Update existing timestamps if they're in the correct format
        try {
            DB::update("
                UPDATE sms_codes 
                SET expiredAt = STR_TO_DATE(expiredAt, '%Y-%m-%d %H:%i:%s')
                WHERE expiredAt REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$'
            ");
        } catch (\Exception $e) {
            \Log::warning('Failed to convert expiredAt timestamps: ' . $e->getMessage());
        }
    }

    public function down()
    {
        Schema::table('sms_codes', function (Blueprint $table) {
            $table->dropIndexIfExists('sms_codes_verifyid_index');
            $table->dropIndexIfExists('sms_codes_phone_index');
            $table->dropSoftDeletes();
        });

        DB::statement("ALTER TABLE sms_codes MODIFY COLUMN expiredAt VARCHAR(255)");
        DB::statement("ALTER TABLE sms_codes MODIFY COLUMN OTPCode VARCHAR(255)");
    }
}
