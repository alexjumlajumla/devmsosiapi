<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'credit_score')) {
                // Place the column after a known existing column; fallback to placing at end if uncertain
                $afterColumn = Schema::hasColumn('users', 'balance') ? 'balance' : null;

                $definition = $table->unsignedSmallInteger('credit_score')->default(0);

                if ($afterColumn) {
                    $definition->after($afterColumn);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credit_score');
        });
    }
}; 