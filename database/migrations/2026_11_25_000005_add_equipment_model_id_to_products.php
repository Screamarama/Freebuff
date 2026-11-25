<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link units to their catalogue model, then fold every group of existing
     * rental units with the same make/model into a model record.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('equipment_model_id')->nullable()->after('business_id')
                ->constrained()->nullOnDelete();
        });

        $rentals = DB::table('products')
            ->where('product_type', 'rental')
            ->orderBy('id')
            ->get();

        $groups = $rentals->groupBy(fn ($product) => implode('|', [
            $product->business_id,
            $product->name,
            $product->manufacturer,
            $product->model_number,
        ]));

        foreach ($groups as $units) {
            $sample = $units->first();

            $modelId = DB::table('equipment_models')->insertGetId([
                'business_id' => $sample->business_id,
                'name' => $sample->name,
                'manufacturer' => $sample->manufacturer,
                'model_number' => $sample->model_number,
                'category' => $sample->category,
                'description' => $sample->description,
                'rental_price_hour' => $sample->rental_price_hour,
                'rental_price_day' => $sample->rental_price_day,
                'rental_price_week' => $sample->rental_price_week,
                'rental_price_month' => $sample->rental_price_month,
                'deposit_amount' => $sample->deposit_amount,
                'default_cost_price' => $sample->cost_price,
                'useful_life_months' => $sample->useful_life_months,
                'salvage_value' => $sample->salvage_value,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('products')
                ->whereIn('id', $units->pluck('id'))
                ->update(['equipment_model_id' => $modelId]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('equipment_model_id');
        });
    }
};
