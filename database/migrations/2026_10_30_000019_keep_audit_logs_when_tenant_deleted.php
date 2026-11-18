<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An audit trail is worthless if removing the thing it describes erases
     * the record. The tenant name is also kept in `details`, so the entry
     * still reads correctly once business_id is null.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->foreign('business_id')->references('id')->on('businesses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }
};
