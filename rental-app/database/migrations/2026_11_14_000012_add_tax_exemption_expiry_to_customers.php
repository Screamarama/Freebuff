<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Null means no expiry was recorded: the certificate stays valid.
            $table->date('tax_exemption_expires_on')->nullable()->after('tax_exempt_copy_on_file');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('tax_exemption_expires_on');
        });
    }
};
