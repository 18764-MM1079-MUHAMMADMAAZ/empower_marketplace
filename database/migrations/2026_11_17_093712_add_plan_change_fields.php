<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * My Account plan changes that wait for the next renewal (see PlanChangeService):
     * - practices.scheduled_billable_providers: a lower provider count, applied at renewal.
     * - orders.scheduled_billing_cycle: a switch between monthly and annual, applied at renewal.
     * - orders.cancels_at: the client cancelled; the subscription ends (instead of renewing) on
     *   this date and stays active until then.
     */
    public function up(): void
    {
        Schema::table('practices', function (Blueprint $table) {
            $table->unsignedInteger('scheduled_billable_providers')->nullable()->after('billable_providers_count');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('scheduled_billing_cycle', 20)->nullable()->after('billing_cycle');
            $table->timestamp('cancels_at')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('practices', function (Blueprint $table) {
            $table->dropColumn('scheduled_billable_providers');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['scheduled_billing_cycle', 'cancels_at']);
        });
    }
};
