<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->decimal('interest_rate', 5, 2);
            $table->decimal('repayment_amount', 12, 2);
            $table->foreignId('disbursed_by')->constrained('users');
            $table->datetime('disbursed_at');
            $table->datetime('due_date');
            $table->enum('status', ['active', 'repaid', 'defaulted'])->default('active');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('loans');
    }
}; 