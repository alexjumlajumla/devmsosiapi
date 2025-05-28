<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('loan_repayments', function (Blueprint $table) {
            if (!Schema::hasColumn('loan_repayments', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down() {
        Schema::table('loan_repayments', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}; 