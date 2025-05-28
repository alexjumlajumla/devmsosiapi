<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateComboProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
		Schema::create('combos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('img')->nullable();
            $table->boolean('active')->index();
            $table->dateTime('expired_at')->index();
            $table->timestamps();
        });

		Schema::create('combo_translations', function (Blueprint $table) {
			$table->id();
			$table->foreignId('combo_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
			$table->string('locale')->index();
			$table->string('title')->index();
			$table->text('description')->nullable();
		});

        Schema::create('combo_stocks', function (Blueprint $table) {
            $table->foreignId('combo_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::table('order_details', function (Blueprint $table) {
            $table->foreignId('combo_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
		Schema::create('order_details', function (Blueprint $table) {
			$table->dropColumn('combo_id');
		});
        Schema::dropIfExists('combo_translations');
        Schema::dropIfExists('combo_stocks');
		Schema::dropIfExists('combos');
	}
}
