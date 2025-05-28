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
        Schema::create('shop_deliveryman', function (Blueprint $table) {
            $table->id();
            
            // Foreign keys
            $table->foreignId('shop_id')
                ->constrained('shops')
                ->onDelete('cascade');
                
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            // Additional fields    
            $table->enum('status', ['active', 'inactive', 'blocked'])
                ->default('active')
                ->index();

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->unique(['shop_id', 'user_id'], 'shop_deliveryman_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shop_deliveryman');
    }
};