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
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('rental_price_hour', 10, 2)->nullable()->after('product_type');
        });

        // Allow hourly billing on rental line items alongside day/week/month.
        Schema::table('rental_items', function (Blueprint $table) {
            $table->enum('rate_type', ['hour', 'day', 'week', 'month'])->default('day')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rental_items', function (Blueprint $table) {
            $table->enum('rate_type', ['day', 'week', 'month'])->default('day')->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('rental_price_hour');
        });
    }
};
