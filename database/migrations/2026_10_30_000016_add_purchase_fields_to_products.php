<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchase price reuses the existing cost_price column; these two
     * record where and when the item was bought.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('purchase_order', 100)->nullable()->after('serial_number');
            $table->date('purchase_date')->nullable()->after('purchase_order');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['purchase_order', 'purchase_date']);
        });
    }
};
