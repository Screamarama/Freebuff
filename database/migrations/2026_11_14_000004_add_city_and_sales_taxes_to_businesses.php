<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('city', 100)->nullable();

            // Whole percentage points, so 7 means 7%.
            $table->unsignedTinyInteger('state_sales_tax')->nullable();
            $table->unsignedTinyInteger('county_sales_tax')->nullable();
            $table->unsignedTinyInteger('city_sales_tax')->nullable();
            $table->unsignedTinyInteger('vat')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'city',
                'state_sales_tax',
                'county_sales_tax',
                'city_sales_tax',
                'vat',
            ]);
        });
    }
};
