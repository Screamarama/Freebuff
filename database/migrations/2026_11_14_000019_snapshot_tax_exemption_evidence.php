<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Why the customer is exempt, for the exemption record itself.
            $table->string('tax_exemption_reason', 150)->nullable()->after('tax_exemption_expires_on');
        });

        Schema::table('rental_contracts', function (Blueprint $table) {
            // The exemption as it stood when the sale was billed: the report
            // keeps the support a past exempt sale was given, not today's.
            $table->string('tax_exemption_number', 100)->nullable()->after('charged_sales_amount');
            $table->date('tax_exemption_expires_on')->nullable()->after('tax_exemption_number');
            $table->string('tax_exemption_reason', 150)->nullable()->after('tax_exemption_expires_on');
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn(['tax_exemption_number', 'tax_exemption_expires_on', 'tax_exemption_reason']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('tax_exemption_reason');
        });
    }
};
