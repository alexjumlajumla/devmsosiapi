<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWaiterTablesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
	{
        Schema::create('waiter_tables', function (Blueprint $table) {
			$table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
			$table->foreignId('table_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
        });
		Schema::table('users', function (Blueprint $table) {
			$table->dropColumn('table_id');
		});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
	{
        Schema::dropIfExists('waiter_tables');
    }
}
