<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products are either rental items or sales items. Existing "both"
     * rows keep their rental pricing and become rentals; any sale price
     * already recorded on them is left in place.
     */
    public function up(): void
    {
        DB::table('products')->where('product_type', 'both')->update(['product_type' => 'rental']);

        Schema::table('products', function (Blueprint $table) {
            $table->enum('product_type', ['rental', 'sale'])->default('rental')->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('product_type', ['rental', 'sale', 'both'])->default('both')->change();
        });
    }
};
