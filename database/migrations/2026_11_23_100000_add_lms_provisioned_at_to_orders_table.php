<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Gap 9 (lms-enrollment-plan.md): when each order's LMS provisioning finished (spec §5). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('lms_provisioned_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('lms_provisioned_at');
        });
    }
};
