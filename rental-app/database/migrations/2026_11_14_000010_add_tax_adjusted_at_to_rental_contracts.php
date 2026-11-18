<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            // When the tax last changed after the agreement was billed, so the
            // report files the adjustment in that month instead of the billing
            // month.
            $table->timestamp('tax_adjusted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('tax_adjusted_at');
        });
    }
};
