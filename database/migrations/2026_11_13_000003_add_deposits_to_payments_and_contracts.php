<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deposits move through the same receipts table as payments — money in when
     * held, money out when refunded — but they are a liability, not rent paid.
     * `kind` keeps them apart; `deposit_applied` records how much of the hold
     * was kept against late and damage fees at return.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('kind', ['payment', 'deposit_received', 'deposit_refund'])
                ->default('payment')
                ->after('reference_id');
        });

        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->decimal('deposit_applied', 10, 2)->default(0)->after('deposit_paid');
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('deposit_applied');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
