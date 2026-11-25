<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rental lines order a catalogue model, not an item already owned, so the
     * product link becomes optional and the depreciation terms are captured on
     * the line at order time.
     */
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('equipment_model_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('useful_life_months')->nullable()->after('unit_cost');
            $table->decimal('salvage_value', 12, 2)->nullable()->after('useful_life_months');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });

        // Existing rental lines pointed at a unit; carry its model and terms over.
        // Done row by row so the migration runs on SQLite as well as MySQL.
        $units = DB::table('products')
            ->select('id', 'equipment_model_id', 'useful_life_months', 'salvage_value')
            ->get()
            ->keyBy('id');

        DB::table('purchase_order_items')
            ->whereNotNull('product_id')
            ->whereNull('equipment_model_id')
            ->orderBy('id')
            ->each(function ($item) use ($units) {
                $unit = $units->get($item->product_id);

                if (! $unit) {
                    return;
                }

                DB::table('purchase_order_items')->where('id', $item->id)->update([
                    'equipment_model_id' => $unit->equipment_model_id,
                    'useful_life_months' => $item->useful_life_months ?? $unit->useful_life_months,
                    'salvage_value' => $item->salvage_value ?? $unit->salvage_value,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipment_model_id');
            $table->dropColumn(['useful_life_months', 'salvage_value']);
        });

        // product_id stays nullable: rental lines legitimately have no product.
    }
};
