<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFeedbackJsonToAiAssistantLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('ai_assistant_logs', function (Blueprint $table) {
            // Add feedback as JSON column that can store nested data about the user's feedback
            // This is in addition to the existing feedback_comment and was_helpful columns
            // for more structured feedback storage with timestamps and additional metadata
            if (!Schema::hasColumn('ai_assistant_logs', 'feedback')) {
                $table->json('feedback')->nullable()->after('feedback_comment');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('ai_assistant_logs', function (Blueprint $table) {
            if (Schema::hasColumn('ai_assistant_logs', 'feedback')) {
                $table->dropColumn('feedback');
            }
        });
    }
}
