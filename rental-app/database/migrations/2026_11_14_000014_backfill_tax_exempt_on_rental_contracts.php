<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Flag agreements that were already priced without tax because the
     * customer's exemption applied, so the report counts them as exempt sales.
     */
    public function up(): void
    {
        DB::table('rental_contracts')
            ->join('customers', 'customers.id', '=', 'rental_contracts.customer_id')
            ->where('rental_contracts.tax_amount', 0)
            ->where(fn ($query) => $query->whereNull('rental_contracts.tax_rate')
                ->orWhere('rental_contracts.tax_rate', 0))
            ->whereNotNull('customers.tax_exemption_number')
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
        // The flag is additive: the agreements it marks were genuinely exempt,
        // and clearing every flag would also erase ones set since this ran.
    }
};
