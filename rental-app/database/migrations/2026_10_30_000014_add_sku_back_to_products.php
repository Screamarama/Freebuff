<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental items are identified by an Equipment Number; sale items by a
     * SKU. A product can carry either or both depending on its type.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('equipment_number', 50)->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('sku', 50)->nullable()->after('equipment_number');

            $table->unique(['business_id', 'sku'], 'products_business_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_business_sku_unique');
            $table->dropColumn('sku');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('equipment_number', 50)->nullable(false)->change();
        });
    }
};
