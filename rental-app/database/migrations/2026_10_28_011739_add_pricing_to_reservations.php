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
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('total_amount', 10, 2)->default(0)->after('quantity');
            $table->string('billing_unit', 20)->nullable()->after('total_amount');
            $table->text('price_breakdown')->nullable()->after('billing_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['total_amount', 'billing_unit', 'price_breakdown']);
        });
    }
};
