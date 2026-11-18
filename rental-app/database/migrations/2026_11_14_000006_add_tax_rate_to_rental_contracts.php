<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            // The combined percentage applied when the agreement was priced, so
            // a later rate change never reprices an agreement already issued.
            $table->decimal('tax_rate', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('tax_rate');
        });
    }
};
