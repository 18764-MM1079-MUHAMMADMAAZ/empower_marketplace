<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('topic', 30)->nullable()->after('phone');
            $table->string('practice_name')->nullable()->after('topic');
            $table->unsignedInteger('billable_providers')->nullable()->after('practice_name');
            $table->text('message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['topic', 'practice_name', 'billable_providers']);
            $table->text('message')->nullable(false)->change();
        });
    }
};
