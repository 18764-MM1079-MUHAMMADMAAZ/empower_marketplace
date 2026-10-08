<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviewer_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('question');
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
        });

        // Carry over the one-question-per-submission data this table replaces, then drop it.
        DB::table('intake_submissions')->whereNotNull('reviewer_question')->get()->each(function ($submission) {
            DB::table('reviewer_questions')->insert([
                'intake_submission_id' => $submission->id,
                'question' => $submission->reviewer_question,
                'reply' => $submission->reviewer_question_reply,
                'replied_at' => $submission->reviewer_question_replied_at,
                'created_at' => $submission->reviewer_question_asked_at ?? now(),
                'updated_at' => $submission->reviewer_question_replied_at ?? $submission->reviewer_question_asked_at ?? now(),
            ]);
        });

        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->dropColumn(['reviewer_question', 'reviewer_question_asked_at', 'reviewer_question_reply', 'reviewer_question_replied_at']);
        });
    }

    public function down(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->text('reviewer_question')->nullable();
            $table->timestamp('reviewer_question_asked_at')->nullable();
            $table->text('reviewer_question_reply')->nullable();
            $table->timestamp('reviewer_question_replied_at')->nullable();
        });

        Schema::dropIfExists('reviewer_questions');
    }
};
