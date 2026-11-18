<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_business_sku_unique');
        });

        // "SKU" reads as retail stock keeping; rental fleets track an
        // Equipment Number instead.
        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('sku', 'equipment_number');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['business_id', 'equipment_number'], 'products_business_equipment_number_unique');

            $table->string('manufacturer', 150)->nullable()->after('equipment_number');
            $table->string('model_number', 150)->nullable()->after('manufacturer');
            $table->string('serial_number', 150)->nullable()->after('model_number');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_business_equipment_number_unique');
            $table->dropColumn(['manufacturer', 'model_number', 'serial_number']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->renameColumn('equipment_number', 'sku');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['business_id', 'sku'], 'products_business_sku_unique');
        });
    }
};
