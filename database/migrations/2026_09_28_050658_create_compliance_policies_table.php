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
        Schema::create('compliance_policies', function (Blueprint $table) {
            $table->id();
            // Matches App\Enums\DocumentType values: compliance_ethics_manual, hipaa_privacy_policy,
            // hipaa_security_manual.
            $table->string('manual');
            $table->string('code')->unique(); // e.g. "CMP-01", "PRV-36", "SEC-26"
            $table->string('title');
            $table->string('page_reference')->nullable(); // e.g. "pp. 127-132"
            $table->json('requirements'); // the "your response should cover" bullets
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('compliance_policies');
    }
};
