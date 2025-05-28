<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vfd_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->unique();
            $table->string('receipt_url')->nullable();
            $table->string('vfd_response')->nullable();
            $table->string('receipt_type'); // delivery, subscription
            $table->unsignedBigInteger('model_id'); // ID of the related model (delivery or subscription)
            $table->string('model_type'); // Model class name
            $table->decimal('amount', 10, 2);
            $table->string('payment_method');
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('status')->default('pending'); // pending, generated, failed
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vfd_receipts');
    }
}; 