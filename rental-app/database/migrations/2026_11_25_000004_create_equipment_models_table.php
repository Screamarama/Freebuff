<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rental catalogue: one row per make/model we hire out, independent of
     * the individual units. Units point here for their spec and default rates.
     */
    public function up(): void
    {
        Schema::create('equipment_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('manufacturer', 150)->nullable();
            $table->string('model_number', 150)->nullable();
            $table->string('category', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('rental_price_hour', 10, 2)->nullable();
            $table->decimal('rental_price_day', 10, 2)->nullable();
            $table->decimal('rental_price_week', 10, 2)->nullable();
            $table->decimal('rental_price_month', 10, 2)->nullable();
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->decimal('default_cost_price', 12, 2)->nullable();
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->decimal('salvage_value', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['business_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_models');
    }
};
