<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A unit away for repair leaves availability but stays on the books. Each
     * stint is its own record so downtime and repair cost add up over its life.
     */
    public function up(): void
    {
        Schema::create('fleet_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 200);
            $table->string('vendor', 150)->nullable();
            $table->date('started_on');
            $table->date('expected_return_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'completed_on']);
            $table->index(['product_id', 'started_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_maintenances');
    }
};
