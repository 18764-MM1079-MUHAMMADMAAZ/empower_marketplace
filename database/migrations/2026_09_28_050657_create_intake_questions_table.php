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
        Schema::create('intake_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_section_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->string('title');
            $table->text('prompt_summary')->nullable();
            $table->text('why_we_ask')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_questions');
    }
};
