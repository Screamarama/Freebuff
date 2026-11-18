<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\RentalContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Agreements priced before tax_exempt existed were all stored as false, so the
 * report ignored their exempt sales. The backfill re-reads each one.
 */
class TaxExemptBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function agreement(Business $business, Customer $customer, array $attributes = []): RentalContract
    {
        return RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => 'active',
            'subtotal' => 100.00,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_amount' => 100.00,
            ...$attributes,
        ]);
    }

    private function customer(Business $business, array $attributes = []): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => true,
            ...$attributes,
        ]);
    }

    public function test_untaxed_agreements_of_exempt_customers_are_flagged(): void
    {
        $business = Business::create(['name' => 'Acme Rentals', 'currency' => 'USD', 'is_active' => true]);

        $exempt = $this->customer($business);
        $expired = $this->customer($business, ['tax_exemption_expires_on' => '2026-10-31']);
        $covered = $this->customer($business, ['tax_exemption_expires_on' => '2026-11-01']);
        $noCopy = $this->customer($business, ['tax_exempt_copy_on_file' => false]);

        $qualifies = $this->agreement($business, $exempt);
        // Untaxed with a rate still on it: the widened pass catches this one.
        $zeroBase = $this->agreement($business, $exempt, [
            'subtotal' => 0,
            'tax_rate' => 9,
            'tax_amount' => 0,
            'total_amount' => 0,
        ]);
        $lapsedBeforeRental = $this->agreement($business, $expired);
        $validOnRentalDate = $this->agreement($business, $covered);
        $missingCopy = $this->agreement($business, $noCopy);
        $alreadyTaxed = $this->agreement($business, $exempt, [
            'tax_rate' => 9,
            'tax_amount' => 9,
            'total_amount' => 109,
        ]);
        $notExempt = $this->agreement($business, $this->customer($business, [
            'tax_exemption_number' => null,
            'tax_exempt_copy_on_file' => false,
        ]));

        // The first backfill ran before this one existed; both are replayed here
        // so the widening is what flags the zero-base agreement.
        (require database_path('migrations/2026_11_14_000014_backfill_tax_exempt_on_rental_contracts.php'))->up();

        $this->assertFalse($zeroBase->fresh()->tax_exempt);

        (require database_path('migrations/2026_11_14_000015_widen_tax_exempt_backfill.php'))->up();

        $this->assertTrue($zeroBase->fresh()->tax_exempt);
        $this->assertTrue($qualifies->fresh()->tax_exempt);
        // A certificate valid through the rental's own start date still counts.
        $this->assertTrue($validOnRentalDate->fresh()->tax_exempt);
        $this->assertFalse($lapsedBeforeRental->fresh()->tax_exempt);
        $this->assertFalse($missingCopy->fresh()->tax_exempt);
        $this->assertFalse($alreadyTaxed->fresh()->tax_exempt);
        $this->assertFalse($notExempt->fresh()->tax_exempt);
    }
}
