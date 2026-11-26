<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A disposed unit leaves the fleet, but what it fetched, what it was worth
     * that day and the resulting gain or loss stay on record.
     */
    public function up(): void
    {
        Schema::create('fleet_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->date('disposed_on');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('book_value', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'disposed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_disposals');
    }
};
