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
            $table->string('wizard_screen')->nullable();
            $table->json('wizard_reached_screens')->nullable();
            $table->json('wizard_skipped_question_ids')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->dropColumn(['wizard_screen', 'wizard_reached_screens', 'wizard_skipped_question_ids']);
        });
    }
};
