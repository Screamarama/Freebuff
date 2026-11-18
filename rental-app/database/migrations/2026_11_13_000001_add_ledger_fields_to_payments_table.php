<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The receipt side of the ledger: who took the money, which receipt number
     * it was given, and — when a receipt is voided — which entry reverses it.
     * Nothing is ever deleted, so the history stays readable.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('clerk_id')->nullable()->after('payment_method')
                ->constrained('users')->nullOnDelete();
            $table->string('receipt_number', 50)->nullable()->after('clerk_id');
            $table->foreignId('reversal_of_id')->nullable()->after('receipt_number')
                ->constrained('payments')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('reversal_of_id');

            // Receipts restart per tenant, like SKUs and customer codes.
            $table->unique(['business_id', 'receipt_number']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'receipt_number']);
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropConstrainedForeignId('clerk_id');
            $table->dropColumn(['receipt_number', 'voided_at']);
        });
    }
};
