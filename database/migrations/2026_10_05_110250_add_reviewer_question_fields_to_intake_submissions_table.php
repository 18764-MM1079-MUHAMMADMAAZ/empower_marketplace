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
        Schema::table('intake_submissions', function (Blueprint $table) {
            // When the admin actually started reviewing — reviewed_at is only set at
            // approve/reject time, so this is the only record of when "In review" began.
            $table->timestamp('under_review_started_at')->nullable();

            // A single question/reply slot (overwritten by a new question, same convention as
            // reviewer_notes) — matches the reference prototype's "Questions for you" stage.
            $table->text('reviewer_question')->nullable();
            $table->timestamp('reviewer_question_asked_at')->nullable();
            $table->text('reviewer_question_reply')->nullable();
            $table->timestamp('reviewer_question_replied_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->dropColumn([
                'under_review_started_at',
                'reviewer_question',
                'reviewer_question_asked_at',
                'reviewer_question_reply',
                'reviewer_question_replied_at',
            ]);
        });
    }
};
