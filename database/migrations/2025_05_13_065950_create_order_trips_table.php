<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('trip_id')->constrained('trips')->cascadeOnDelete();
            $table->integer('sequence')->default(0); // Order sequence in the trip
            $table->enum('status', ['pending', 'picked', 'delivered'])->default('pending');
            $table->timestamps();

            // Ensure each order can only be assigned to one trip
            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_trips');
    }
}; 