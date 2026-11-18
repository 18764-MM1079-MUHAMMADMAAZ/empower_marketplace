<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shown on My Account's payment method ("Visa ending 4242 · Expires 12/28 · Jane Provider").
     * Neither is restricted card data. Cards saved before this stay null and show as "Card".
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('card_brand', 20)->nullable()->after('card_last_four');
            $table->string('card_holder_name')->nullable()->after('card_brand');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['card_brand', 'card_holder_name']);
        });
    }
};
