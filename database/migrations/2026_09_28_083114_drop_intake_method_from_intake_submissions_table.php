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
            $table->dropColumn('intake_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->string('intake_method')->default('download')->after('status');
        });
    }
};
