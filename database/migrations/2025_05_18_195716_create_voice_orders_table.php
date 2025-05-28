<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateVoiceOrdersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('voice_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_id')->nullable();
            $table->text('transcription_text')->nullable();
            $table->json('intent_data')->nullable();
            $table->json('filters_detected')->nullable();
            $table->json('product_ids')->nullable();
            $table->text('recommendation_text')->nullable();
            $table->string('audio_url')->nullable()->comment('S3 URL of the stored audio file');
            $table->string('audio_format')->nullable()->comment('Format of the audio file (mp3, wav, webm, etc.)');
            $table->float('audio_duration')->nullable()->comment('Duration of the audio in seconds');
            $table->string('status')->default('pending')->comment('pending, fulfilled, failed, converted');
            $table->float('score')->nullable()->comment('AI-generated relevance score');
            $table->integer('processing_time_ms')->nullable();
            $table->float('confidence_score')->nullable()->comment('Transcription confidence score');
            $table->integer('transcription_duration_ms')->nullable();
            $table->integer('ai_processing_duration_ms')->nullable();
            $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_feedback_provided')->default(false);
            $table->json('feedback')->nullable();
            $table->boolean('was_helpful')->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete()->comment('Related order if converted');
            $table->foreignId('log_id')->nullable()->constrained('ai_assistant_logs')->nullOnDelete();
            $table->timestamps();

            // Indexes for faster queries
            $table->index('session_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('voice_orders');
    }
}
