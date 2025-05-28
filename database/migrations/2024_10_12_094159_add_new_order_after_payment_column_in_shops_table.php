<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNewOrderAfterPaymentColumnInShopsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
	{
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('new_order_after_payment')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
	{
        Schema::table('shops', function (Blueprint $table) {
			$table->dropColumn('new_order_after_payment');
		});
    }
}
