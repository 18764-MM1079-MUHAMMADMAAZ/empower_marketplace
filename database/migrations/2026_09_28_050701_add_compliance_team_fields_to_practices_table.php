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
        Schema::table('practices', function (Blueprint $table) {
            foreach (['compliance_officer', 'hipaa_privacy_officer', 'hipaa_security_officer', 'release_of_info_officer'] as $role) {
                $table->string("{$role}_name")->nullable();
                $table->string("{$role}_phone")->nullable();
                $table->string("{$role}_email")->nullable();
            }

            $table->string('it_vendor_name')->nullable();
            $table->string('compliance_hotline_number')->nullable();
            $table->string('compliance_hotline_email')->nullable();
            $table->boolean('uses_ehcp_hotline')->default(false);
            $table->unsignedTinyInteger('hotline_poster_count')->nullable();
            $table->json('compliance_committee_members')->nullable();
            $table->json('compliance_governing_board_members')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('practices', function (Blueprint $table) {
            $columns = ['it_vendor_name', 'compliance_hotline_number', 'compliance_hotline_email', 'uses_ehcp_hotline', 'hotline_poster_count', 'compliance_committee_members', 'compliance_governing_board_members'];

            foreach (['compliance_officer', 'hipaa_privacy_officer', 'hipaa_security_officer', 'release_of_info_officer'] as $role) {
                $columns = array_merge($columns, ["{$role}_name", "{$role}_phone", "{$role}_email"]);
            }

            $table->dropColumn($columns);
        });
    }
};
