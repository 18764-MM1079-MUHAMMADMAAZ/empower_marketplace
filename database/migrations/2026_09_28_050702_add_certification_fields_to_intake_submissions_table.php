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
            $table->string('certified_by_name')->nullable();
            $table->string('certified_by_title')->nullable();
            $table->string('certified_signature')->nullable();
            $table->timestamp('certified_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->dropColumn(['certified_by_name', 'certified_by_title', 'certified_signature', 'certified_at']);
        });
    }
};
