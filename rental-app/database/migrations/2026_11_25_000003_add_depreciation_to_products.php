<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental units are capital equipment: the cost and purchase date already
     * exist, these add the straight-line inputs for book value.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('useful_life_months')->nullable()->after('purchase_date');
            $table->decimal('salvage_value', 12, 2)->nullable()->after('useful_life_months');
            $table->date('depreciation_started_on')->nullable()->after('salvage_value');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['useful_life_months', 'salvage_value', 'depreciation_started_on']);
        });
    }
};
