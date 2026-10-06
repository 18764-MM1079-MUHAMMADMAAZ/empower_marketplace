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
            // The "Your services" checklist — array of checked service keys. Whether the screen
            // itself has been completed yet is read from wizard_reached_screens (same convention
            // as every other wizard screen), not tracked separately here.
            $table->json('wizard_selected_services')->nullable()->after('wizard_skipped_question_ids');
            // Per-section gate choice: {"compliance_program": {"mode": "some", "picked": [12, 14]}, ...}.
            // A section absent from this map hasn't had its gate screen completed yet.
            $table->json('wizard_section_gates')->nullable()->after('wizard_selected_services');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->dropColumn(['wizard_selected_services', 'wizard_section_gates']);
        });
    }
};
