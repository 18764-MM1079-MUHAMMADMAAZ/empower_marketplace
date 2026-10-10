<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('email')->index();
            $table->boolean('is_practice_admin')->default(false)->after('external_id');
        });

        Schema::table('practices', function (Blueprint $table) {
            $table->string('external_practice_id')->nullable()->after('source_system');
            $table->string('phone', 30)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('practices', function (Blueprint $table) {
            $table->dropColumn(['external_practice_id', 'phone']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['external_id', 'is_practice_admin']);
        });
    }
};
