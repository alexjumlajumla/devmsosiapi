<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('ai_assistant_logs')) {
            Schema::create('ai_assistant_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable();
                $table->string('request_type')->nullable(); // voice_order, text_order, etc.
                $table->text('input')->nullable(); // For backward compatibility 
                $table->text('output')->nullable(); // For backward compatibility
                $table->text('request_content')->nullable(); // The audio transcription or text input
                $table->text('response_content')->nullable(); // The AI response
                $table->boolean('successful')->default(false); // Whether the request was successful
                $table->integer('processing_time_ms')->nullable(); // Processing time in milliseconds
                $table->json('filters_detected')->nullable(); // For backward compatibility
                $table->json('product_ids')->nullable(); // For backward compatibility
                $table->json('metadata')->nullable(); // Additional metadata (product recommendations, etc.)
                $table->boolean('is_feedback_provided')->default(false);
                $table->boolean('was_helpful')->nullable();
                $table->text('feedback_comment')->nullable();
                $table->string('session_id')->nullable(); // Session ID for voice orders
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ai_assistant_logs');
    }
}; 