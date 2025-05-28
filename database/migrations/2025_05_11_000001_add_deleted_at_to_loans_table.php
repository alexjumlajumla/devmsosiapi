<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('loans', function (Blueprint $table) {
            if (!Schema::hasColumn('loans', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down() {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}; 