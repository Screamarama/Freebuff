<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_filings', function (Blueprint $table) {
            // A refile keeps the version it replaces instead of overwriting it:
            // every snapshot stays downloadable, only one is current per month.
            $table->timestamp('superseded_at')->nullable()->after('filed_at');
            $table->index(['business_id', 'period', 'superseded_at']);
        });

        Schema::table('tax_filings', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::table('tax_filings', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'period', 'superseded_at']);
            $table->dropColumn('superseded_at');
            $table->unique(['business_id', 'period']);
        });
    }
};
