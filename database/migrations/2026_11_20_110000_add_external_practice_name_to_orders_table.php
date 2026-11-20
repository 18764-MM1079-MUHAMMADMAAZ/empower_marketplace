<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The partner (CareCloud / talkEHR) practice's name, saved with the order next to its id, so
 *  admins see "Main Street Clinic" instead of "Partner practice 1011163". Filled for older orders
 *  the next time the client signs in through the partner and picks that practice. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('external_practice_name')->nullable()->after('external_practice_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('external_practice_name');
        });
    }
};
