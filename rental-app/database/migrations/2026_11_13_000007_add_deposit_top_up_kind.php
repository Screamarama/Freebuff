<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A top-up is deposit taken in after the return, so it still counts as
     * money received — but it reads as "Deposit top-up", not "Deposit held".
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('kind', ['payment', 'deposit_received', 'deposit_refund', 'deposit_top_up', 'account_payment'])
                ->default('payment')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('kind', ['payment', 'deposit_received', 'deposit_refund', 'account_payment'])
                ->default('payment')
                ->change();
        });
    }
};
