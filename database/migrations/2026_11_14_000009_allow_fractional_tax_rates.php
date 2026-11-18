<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real rates carry decimals — 8.875%, 7.375% — so the columns hold
        // thousandths of a percent rather than whole points.
        Schema::table('businesses', function (Blueprint $table) {
            $table->decimal('state_sales_tax', 5, 3)->nullable()->change();
            $table->decimal('county_sales_tax', 5, 3)->nullable()->change();
            $table->decimal('city_sales_tax', 5, 3)->nullable()->change();
            $table->decimal('vat', 5, 3)->nullable()->change();
        });

        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->decimal('tax_rate', 6, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->change();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedTinyInteger('state_sales_tax')->nullable()->change();
            $table->unsignedTinyInteger('county_sales_tax')->nullable()->change();
            $table->unsignedTinyInteger('city_sales_tax')->nullable()->change();
            $table->unsignedTinyInteger('vat')->nullable()->change();
        });
    }
};
