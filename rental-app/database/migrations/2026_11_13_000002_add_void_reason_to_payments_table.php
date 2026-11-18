<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why a receipt was voided, kept on the voided row itself so a reader does
     * not have to decode it out of the reversing entry's note.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('void_reason', 255)->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('void_reason');
        });
    }
};
