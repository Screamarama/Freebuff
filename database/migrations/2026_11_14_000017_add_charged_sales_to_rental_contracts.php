<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            // The sale inside the charge: subtotal less discount at the moment
            // it was billed, so editing the agreement later cannot move the
            // gross and taxable sales a closed month already reported.
            $table->decimal('charged_sales_amount', 12, 2)->nullable()->after('charged_tax_amount');
        });

        // Existing billed agreements keep the figures they report today.
        DB::table('rental_contracts')
            ->whereNotNull('charged_to_account_at')
            ->update(['charged_sales_amount' => DB::raw('ROUND(subtotal - discount_amount, 2)')]);
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('charged_sales_amount');
        });
    }
};
