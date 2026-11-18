<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The agreement's charges at the moment it went on the account. Later late
     * fees and damage are kept apart so a printed statement is never rewritten
     * by something added after it went out.
     */
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->decimal('charged_amount', 10, 2)->nullable()->after('charge_reversed_reason');
        });

        // Existing charges are taken as billed at their current figure; only
        // changes made from here on can appear as separate adjustment lines.
        DB::table('rental_contracts')
            ->whereNotNull('charged_to_account_at')
            ->update([
                'charged_amount' => DB::raw(
                    'total_amount + COALESCE((SELECT SUM(damage_fee) FROM rental_items WHERE rental_items.contract_id = rental_contracts.id), 0)'
                ),
            ]);
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('charged_amount');
        });
    }
};
