<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The first backfill also required a zero stored rate, which pricing does
     * not: syncTotals flags an agreement whenever the exemption left it untaxed,
     * whatever rate is on it. This catches the agreements that clause skipped.
     */
    public function up(): void
    {
        DB::table('rental_contracts')
            ->join('customers', 'customers.id', '=', 'rental_contracts.customer_id')
            ->where('rental_contracts.tax_exempt', false)
            ->where('rental_contracts.tax_amount', 0)
            ->where('customers.tax_exemption_number', '!=', '')
            ->where('customers.tax_exempt_copy_on_file', true)
            // The certificate has to cover the rental's own start date, the
            // same rule pricing uses.
            ->where(fn ($query) => $query->whereNull('customers.tax_exemption_expires_on')
                ->orWhereColumn('customers.tax_exemption_expires_on', '>=', 'rental_contracts.start_date'))
            ->update(['rental_contracts.tax_exempt' => true]);
    }

    public function down(): void
    {
        // Additive, like the first backfill: clearing every flag would also
        // erase ones set by normal pricing since.
    }
};
