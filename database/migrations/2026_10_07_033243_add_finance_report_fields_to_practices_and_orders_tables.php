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
        // Minimal subset of sso.md §4's full migration list — just the columns the §10 daily
        // Finance report needs for its "Source system"/"Practice ID + Subscription/account ID"/
        // "Preferred billing option" columns. All nullable and unused by any SSO flow yet (that's
        // the broader, deferred §1-3/§6-9 work) — they default to null, which the report displays
        // as "Direct" / "—" for today's regular Clover-paid orders.
        Schema::table('practices', function (Blueprint $table) {
            $table->string('source_system')->nullable()->after('user_id');
            $table->string('subscription_account_id')->nullable()->after('source_system');
            $table->string('preferred_billing_option')->nullable()->after('subscription_account_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('finance_processed_at')->nullable()->after('cancelled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('practices', function (Blueprint $table) {
            $table->dropColumn(['source_system', 'subscription_account_id', 'preferred_billing_option']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('finance_processed_at');
        });
    }
};
