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
            $table->string('legal_practice_name')->nullable();
            $table->string('dba_name')->nullable();
            $table->string('other_entities')->nullable();
            $table->string('main_phone')->nullable();
            $table->string('main_email')->nullable();
            $table->json('practice_locations')->nullable();

            // it_vendor_name (added earlier) doubles as the "company name" field for it_mode='vendor'.
            $table->string('it_mode')->nullable();
            $table->string('it_contact_name')->nullable();
            $table->string('it_contact_phone')->nullable();
            $table->string('it_contact_email')->nullable();

            $table->boolean('committee_none')->default(false);
            $table->string('board_mode')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('practices', function (Blueprint $table) {
            $table->dropColumn([
                'legal_practice_name', 'dba_name', 'other_entities', 'main_phone', 'main_email', 'practice_locations',
                'it_mode', 'it_contact_name', 'it_contact_phone', 'it_contact_email',
                'committee_none', 'board_mode',
            ]);
        });
    }
};
