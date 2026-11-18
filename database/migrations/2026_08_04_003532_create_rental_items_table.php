<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained('rental_contracts')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('restrict');
            $table->integer('quantity')->default(1);
            $table->enum('rate_type', ['day', 'week', 'month'])->default('day');
            $table->decimal('unit_price', 10, 2);
            $table->integer('duration')->default(1);
            $table->decimal('subtotal', 10, 2);
            $table->integer('returned_quantity')->default(0);
            $table->decimal('damage_fee', 10, 2)->default(0);
            $table->text('condition_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_items');
    }
};

