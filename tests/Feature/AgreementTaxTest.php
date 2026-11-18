<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgreementTaxTest extends TestCase
{
    use RefreshDatabase;

    private function business(array $overrides = []): Business
    {
        return Business::create([
            'name' => 'Acme Rentals',
            'currency' => 'USD',
            'country' => 'US',
            'state' => 'Ohio',
            'state_sales_tax' => 6,
            'county_sales_tax' => 2,
            'city_sales_tax' => 1,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function customer(Business $business, array $overrides = []): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-TAX1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
            'account_type' => 'cash',
            ...$overrides,
        ]);
    }

    private function contract(Business $business, Customer $customer, float $itemSubtotal = 100.00): RentalContract
    {
        $product = Product::create([
            'business_id' => $business->id,
            'equipment_number' => 'EXC-1',
            'name' => 'Excavator',
            'product_type' => 'rental',
        ]);

        $contract = RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => 'active',
        ]);

        $contract->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => $itemSubtotal,
            'duration' => 1,
            'subtotal' => $itemSubtotal,
        ]);

        return $contract;
    }

    public function test_us_sales_taxes_are_applied_to_the_agreement_subtotal(): void
    {
        $business = $this->business();
        $contract = $this->contract($business, $this->customer($business));

        $contract->syncTotals();

        $contract->refresh();

        $this->assertSame('9.000', $contract->tax_rate);
        $this->assertSame('9.00', $contract->tax_amount);
        $this->assertSame('109.00', $contract->total_amount);
    }

    public function test_a_fractional_rate_is_applied_and_snapshotted_with_its_decimals(): void
    {
        $business = $this->business([
            'state_sales_tax' => 8.875,
            'county_sales_tax' => null,
            'city_sales_tax' => null,
        ]);
        $contract = $this->contract($business, $this->customer($business));

        $contract->syncTotals();
        $contract->refresh();

        $this->assertSame('8.875', $contract->tax_rate);
        $this->assertSame('8.88', $contract->tax_amount);
        $this->assertSame('108.88', $contract->total_amount);
    }

    public function test_a_discount_is_removed_before_tax_is_calculated(): void
    {
        $business = $this->business();
        $contract = $this->contract($business, $this->customer($business));

        $contract->update(['discount_amount' => 20.00]);
        $contract->syncTotals();

        $contract->refresh();

        // 9% of the discounted $80, not of the $100 list price.
        $this->assertSame('7.20', $contract->tax_amount);
        $this->assertSame('87.20', $contract->total_amount);
    }

    public function test_a_discount_larger_than_the_subtotal_taxes_nothing(): void
    {
        $business = $this->business();
        $contract = $this->contract($business, $this->customer($business));

        $contract->update(['discount_amount' => 150.00]);
        $contract->syncTotals();

        $contract->refresh();

        $this->assertSame('0.00', $contract->tax_amount);
        $this->assertSame('-50.00', $contract->total_amount);
    }

    public function test_a_non_us_tenant_uses_its_vat_rate(): void
    {
        $business = $this->business([
            'country' => 'CA',
            'state' => 'Ontario',
            'state_sales_tax' => null,
            'county_sales_tax' => null,
            'city_sales_tax' => null,
            'vat' => 20,
        ]);
        $contract = $this->contract($business, $this->customer($business));

        $contract->syncTotals();

        $contract->refresh();

        $this->assertSame('20.000', $contract->tax_rate);
        $this->assertSame('20.00', $contract->tax_amount);
        $this->assertSame('120.00', $contract->total_amount);
    }

    public function test_an_exempt_customer_pays_no_tax(): void
    {
        $business = $this->business();
        $customer = $this->customer($business, [
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => true,
        ]);
        $contract = $this->contract($business, $customer);

        $contract->syncTotals();

        $contract->refresh();

        $this->assertSame('0.000', $contract->tax_rate);
        $this->assertSame('0.00', $contract->tax_amount);
        $this->assertSame('100.00', $contract->total_amount);
    }

    public function test_the_rate_is_snapshotted_so_a_later_change_does_not_reprice_it(): void
    {
        $business = $this->business();
        $contract = $this->contract($business, $this->customer($business));

        $contract->syncTotals();
        $contract->refresh();
        $this->assertSame('9.00', $contract->tax_amount);

        $business->update(['state_sales_tax' => 20, 'county_sales_tax' => 0, 'city_sales_tax' => 0]);

        // Repricing the same agreement keeps the rate it was issued under…
        $contract->syncTotals();
        $contract->refresh();
        $this->assertSame('9.000', $contract->tax_rate);
        $this->assertSame('9.00', $contract->tax_amount);
        $this->assertSame('109.00', $contract->total_amount);

        // …and extra lines are taxed at that same snapshotted rate.
        $contract->items()->create([
            'product_id' => Product::where('business_id', $business->id)->value('id'),
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 100.00,
            'duration' => 1,
            'subtotal' => 100.00,
        ]);

        $contract->syncTotals();
        $contract->refresh();

        $this->assertSame('18.00', $contract->tax_amount);
        $this->assertSame('218.00', $contract->total_amount);
    }

    public function test_tax_changes_after_billing_are_dated_for_the_report(): void
    {
        $business = $this->business();
        $contract = $this->contract($business, $this->customer($business));

        $contract->syncTotals();
        $contract->update([
            'charged_to_account_at' => now(),
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
        ]);

        $this->assertCount(0, $contract->fresh()->taxAdjustments);

        // A late fee does not change the tax, so nothing is recorded.
        $contract->update(['late_fee' => 10.00]);
        $contract->syncTotals();
        $this->assertCount(0, $contract->fresh()->taxAdjustments);

        // Extra lines do change it, and the change gets its own entry.
        $this->addItem($contract, $business);
        $contract->syncTotals();

        $this->assertSame('18.00', $contract->fresh()->tax_amount);
        $entries = $contract->fresh()->taxAdjustments;
        $this->assertCount(1, $entries);
        $this->assertSame('9.00', $entries[0]->amount);
        $this->assertNotNull($entries[0]->adjusted_at);

        // A second change adds a second entry rather than replacing the first.
        $this->addItem($contract, $business);
        $contract->syncTotals();

        $this->assertSame('27.00', $contract->fresh()->tax_amount);
        $this->assertSame(['9.00', '9.00'], $contract->fresh()->taxAdjustments->pluck('amount')->all());
    }

    private function addItem(RentalContract $contract, Business $business): void
    {
        $contract->items()->create([
            'product_id' => Product::where('business_id', $business->id)->value('id'),
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 100.00,
            'duration' => 1,
            'subtotal' => 100.00,
        ]);
    }

    public function test_converting_a_reservation_prices_the_agreement_with_tax(): void
    {
        $business = $this->business();
        $customer = $this->customer($business);

        $admin = new User(['name' => 'Ada Admin', 'email' => 'admin@acme.test', 'password' => 'password']);
        $admin->role = 'admin';
        $admin->business_id = $business->id;
        $admin->email_verified_at = now();
        $admin->save();

        $product = Product::create([
            'business_id' => $business->id,
            'equipment_number' => 'EXC-2',
            'name' => 'Mixer',
            'product_type' => 'rental',
            'rental_price_day' => 10.00,
        ]);

        $reservation = Reservation::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'created_by' => $admin->id,
            'quantity' => 1,
            'starts_at' => '2026-11-01 09:00:00',
            'ends_at' => '2026-11-03 09:00:00',
            'status' => 'upcoming',
        ]);

        $this->actingAs($admin)
            ->post(route('reservations.convert', $reservation))
            ->assertRedirect();

        $contract = RentalContract::where('reservation_id', $reservation->id)->sole();

        $this->assertSame('20.00', $contract->subtotal);
        $this->assertSame('9.000', $contract->tax_rate);
        $this->assertSame('1.80', $contract->tax_amount);
        $this->assertSame('21.80', $contract->total_amount);

        // The agreement page explains the tax instead of hiding it in the total.
        $this->actingAs($admin)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertSee('How the total is made up')
            ->assertSee('Tax (9%)')
            ->assertSee('$1.80')
            ->assertSee('$21.80');
    }
}
