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
        Schema::create('intake_question_policy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('compliance_policy_id')->constrained()->cascadeOnDelete();
            $table->unique(['intake_question_id', 'compliance_policy_id'], 'question_policy_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_question_policy');
    }
};
