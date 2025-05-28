<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('calories')->nullable()->comment('Calories per serving');
            $table->json('ingredient_tags')->nullable()->comment('JSON array of ingredient tags');
            $table->json('allergen_flags')->nullable()->comment('JSON array of allergen tags');
            $table->integer('popularity_score')->default(0)->comment('AI-calculated popularity score');
            $table->string('representative_image')->nullable()->comment('AI-selected representative image');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'calories',
                'ingredient_tags',
                'allergen_flags',
                'popularity_score',
                'representative_image'
            ]);
        });
    }
}; 