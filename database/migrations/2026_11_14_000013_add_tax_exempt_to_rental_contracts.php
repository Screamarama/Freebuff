<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            // True when the agreement was priced with no tax because the
            // customer's exemption applied, so exempt sales can be reported.
            $table->boolean('tax_exempt')->default(false)->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('tax_exempt');
        });
    }
};
