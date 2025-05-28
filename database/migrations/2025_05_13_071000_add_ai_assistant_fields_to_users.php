<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('ai_order_credits')->default(3)->comment('Number of AI order credits available');
            $table->boolean('is_premium')->default(false)->comment('Whether user has premium AI assistant');
            $table->timestamp('premium_expires_at')->nullable()->comment('When premium subscription expires');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'ai_order_credits',
                'is_premium',
                'premium_expires_at'
            ]);
        });
    }
}; 