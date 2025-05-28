<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->foreignId('vehicle_id')->nullable()->constrained('deliveryman_settings', 'user_id')->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('start_address');
            $table->decimal('start_lat',10,7);
            $table->decimal('start_lng',10,7);
            $table->dateTime('scheduled_at')->nullable();
            $table->json('meta')->nullable(); // AI optimisation stats, load, etc
            $table->enum('status', ['planned','in_progress','completed','canceled'])->default('planned');
            $table->timestamps();
        });

        Schema::create('trip_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('address');
            $table->decimal('lat',10,7);
            $table->decimal('lng',10,7);
            $table->integer('sequence')->default(0);
            $table->integer('eta_minutes')->nullable();
            $table->enum('status',['pending','arrived','skipped'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_locations');
        Schema::dropIfExists('trips');
    }
}; 