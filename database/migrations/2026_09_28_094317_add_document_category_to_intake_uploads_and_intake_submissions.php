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
        Schema::table('intake_uploads', function (Blueprint $table) {
            $table->string('document_category')->nullable()->after('upload_type');
        });

        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->json('wizard_missing_document_categories')->nullable()->after('wizard_skipped_question_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intake_uploads', function (Blueprint $table) {
            $table->dropColumn('document_category');
        });

        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->dropColumn('wizard_missing_document_categories');
        });
    }
};
