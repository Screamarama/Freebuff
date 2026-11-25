<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Received rental equipment is not in the fleet yet: it waits in service
     * hold until someone sets its rates, depreciation terms and resale value.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('fleet_status', 20)->default('active')->after('is_active');
            $table->decimal('used_sale_price', 12, 2)->nullable()->after('sale_price');

            $table->index(['business_id', 'fleet_status']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'fleet_status']);
            $table->dropColumn(['fleet_status', 'used_sale_price']);
        });
    }
};
