<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The currency each charge and receipt was actually made in. A statement
     * reprint reads these, so changing the business currency later cannot
     * redraw a document that was already handed to the customer.
     */
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('charged_amount');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('amount');
        });

        // Existing rows are taken as being in the business's current currency:
        // there is no older record of what they were billed in.
        DB::table('rental_contracts')
            ->whereNotNull('charged_to_account_at')
            ->update(['currency' => DB::raw('(SELECT currency FROM businesses WHERE businesses.id = rental_contracts.business_id)')]);

        DB::table('payments')->update([
            'currency' => DB::raw('(SELECT currency FROM businesses WHERE businesses.id = payments.business_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
