<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Charge accounts: a customer either pays at the counter (cash) or runs a
     * credit account with a limit. Charging an agreement to the account turns
     * its balance into money owed on that account, with a due date.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->enum('account_type', ['cash', 'charge'])->default('cash')->after('is_active');
            $table->decimal('credit_limit', 10, 2)->nullable()->after('account_type');
        });

        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->timestamp('charged_to_account_at')->nullable()->after('signed_at');
            $table->date('due_on')->nullable()->after('charged_to_account_at');
        });
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn(['charged_to_account_at', 'due_on']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'credit_limit']);
        });
    }
};
