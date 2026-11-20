<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When Marketplace last suspended the user's Empower LMS (Moodle) account because they lost
 *  LMS access; null while the account is active. See lms-enrollment-plan.md, gap 6. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('lms_suspended_at')->nullable()->after('moodle_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lms_suspended_at');
        });
    }
};
