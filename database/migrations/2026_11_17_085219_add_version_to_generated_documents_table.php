<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shown as "v1", "v2"… on the My Account documents table. Every existing document counts as
     * its first version; GeneratedDocument bumps it each time a document is generated again.
     */
    public function up(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('generated_documents', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
