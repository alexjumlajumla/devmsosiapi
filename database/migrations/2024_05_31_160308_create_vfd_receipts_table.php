<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('vfd_receipts')) {
            Schema::create('vfd_receipts', function (Blueprint $table) {
                $table->id();
                $table->string('receipt_number')->unique();
                $table->string('receipt_url')->nullable();
                $table->text('vfd_response')->nullable();
                $table->string('receipt_type');
                $table->unsignedBigInteger('model_id');
                $table->string('model_type');
                $table->decimal('amount', 12, 2);
                $table->string('payment_method');
                $table->string('customer_name')->nullable();
                $table->string('customer_phone')->nullable();
                $table->string('customer_email')->nullable();
                $table->string('status')->default('pending');
                $table->text('error_message')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['model_id', 'model_type']);
                $table->index('receipt_type');
                $table->index('status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vfd_receipts');
    }
};
