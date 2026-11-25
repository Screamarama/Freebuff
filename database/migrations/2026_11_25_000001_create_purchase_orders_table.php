<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Purchase orders are kept per tenant and split by what is being bought:
     * rental units are capital equipment (record keeping + depreciation), sales
     * items are resale stock.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);              // rental | sales
            $table->string('number', 40);
            $table->string('supplier_name', 150)->nullable();
            $table->string('status', 20)->default('ordered'); // ordered | partially_received | received | cancelled
            $table->date('ordered_on')->nullable();
            $table->date('expected_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'number']);
            $table->index(['business_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
