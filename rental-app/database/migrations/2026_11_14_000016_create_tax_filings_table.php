<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The figures a month was reported with, kept so later changes can be
        // shown against the filed version instead of rewriting it silently.
        Schema::create('tax_filings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7);
            $table->timestamp('filed_at');
            $table->foreignId('filed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['business_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_filings');
    }
};
