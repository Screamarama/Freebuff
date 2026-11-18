<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An account payment is one receipt split across several agreements: the
     * receipt row points at the customer, each allocation row at its agreement
     * and back at the receipt that paid it.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('reference_type', ['rental', 'sale', 'customer'])->change();
            $table->enum('kind', ['payment', 'deposit_received', 'deposit_refund', 'account_payment'])
                ->default('payment')
                ->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('account_payment_id')->nullable()->after('reversal_of_id')
                ->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_payment_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('reference_type', ['rental', 'sale'])->change();
            $table->enum('kind', ['payment', 'deposit_received', 'deposit_refund'])
                ->default('payment')
                ->change();
        });
    }
};
