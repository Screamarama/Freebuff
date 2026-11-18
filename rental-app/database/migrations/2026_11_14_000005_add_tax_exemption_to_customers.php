<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('tax_exemption_number', 100)->nullable();
            // Tax is only left off when the certificate itself is on file.
            $table->boolean('tax_exempt_copy_on_file')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['tax_exemption_number', 'tax_exempt_copy_on_file']);
        });
    }
};
