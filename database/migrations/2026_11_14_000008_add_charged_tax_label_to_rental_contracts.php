<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            // What the tax was called when the charge was billed, so a reprint
            // does not rename it after the tenant moves country.
            $table->string('charged_tax_label', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('charged_tax_label');
        });
    }
};
