<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the client was shown "Congratulations! Your profile has been approved." — it's shown once. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->timestamp('approval_welcome_shown_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('intake_submissions', function (Blueprint $table) {
            $table->dropColumn('approval_welcome_shown_at');
        });
    }
};
