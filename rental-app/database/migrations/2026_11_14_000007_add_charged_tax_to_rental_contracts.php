<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            // The tax inside charged_amount at billing, so the statement can
            // print it as its own line on every reprint.
            $table->decimal('charged_tax_amount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('charged_tax_amount');
        });
    }
};
