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
        Schema::table('intake_questions', function (Blueprint $table) {
            // One of the 12 "Your services" checklist keys — set only for the 12 questions the
            // checklist gates (1:1, not many-to-many). Null means the question is never
            // services-gated.
            $table->string('service_key')->nullable()->after('why_we_ask');
            // True for the 10 technical-security questions that collapse into one "What your IT
            // company manages" question when Section 1 says IT is outsourced.
            $table->boolean('is_it_managed_topic')->default(false)->after('service_key');
            // True only for "Compliance Committee" (#4) — skipped outright when Section 1 says
            // the practice has no committee, independent of any section gate.
            $table->boolean('requires_compliance_committee')->default(false)->after('is_it_managed_topic');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intake_questions', function (Blueprint $table) {
            $table->dropColumn(['service_key', 'is_it_managed_topic', 'requires_compliance_committee']);
        });
    }
};
