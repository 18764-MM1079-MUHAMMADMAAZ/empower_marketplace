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
        Schema::create('intake_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intake_question_id')->constrained()->cascadeOnDelete();
            $table->text('response')->nullable();
            $table->boolean('has_documented_process')->default(false);
            $table->boolean('skipped')->default(false);
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['intake_submission_id', 'intake_question_id'], 'submission_question_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_answers');
    }
};
