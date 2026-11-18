<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTaxReportTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $name = 'Acme Rentals'): Business
    {
        return Business::create([
            'name' => $name,
            'currency' => 'USD',
            'country' => 'US',
            'state_sales_tax' => 9,
            'is_active' => true,
        ]);
    }

    private function manager(Business $business, string $email = 'manager@acme.test'): User
    {
        $user = new User(['name' => 'Mia Manager', 'email' => $email, 'password' => 'password']);
        $user->role = 'manager';
        $user->business_id = $business->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function customer(Business $business): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-TAX-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0101',
            'account_type' => 'charge',
            'credit_limit' => '5000.00',
        ]);
    }

    private function agreement(Business $business, Customer $customer, array $attributes = []): RentalContract
    {
        $attributes = [
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05',
            'status' => 'active',
            'subtotal' => 100.00,
            'tax_rate' => 9.00,
            'tax_amount' => 9.00,
            'total_amount' => 109.00,
            ...$attributes,
        ];

        // Billed agreements carry the tax and the sale frozen at billing; tests
        // that set a billing date get that snapshot unless they set their own.
        if (isset($attributes['charged_to_account_at'])) {
            if (! array_key_exists('charged_tax_amount', $attributes)) {
                $attributes['charged_tax_amount'] = $attributes['tax_amount'];
            }

            if (! array_key_exists('charged_sales_amount', $attributes)) {
                $attributes['charged_sales_amount'] = round(max(0,
                    (float) $attributes['subtotal'] - (float) ($attributes['discount_amount'] ?? 0)), 2);
            }
        }

        return RentalContract::create($attributes);
    }

    public function test_tax_is_reported_per_month_and_per_agreement(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $november = $this->agreement($business, $customer, [
            'charged_to_account_at' => '2026-11-03 10:00:00',
            'charged_tax_label' => 'Sales tax',
        ]);

        $october = $this->agreement($business, $customer, [
            'contract_number' => 'RA-OCT',
            'tax_rate' => 20.00,
            'tax_amount' => 20.00,
            'total_amount' => 120.00,
            'charged_to_account_at' => '2026-10-15 10:00:00',
            'charged_tax_label' => 'VAT',
        ]);

        $other = $this->business('Other Rentals');
        $this->agreement($other, $this->customer($other), [
            'contract_number' => 'RA-OTHER',
            'charged_to_account_at' => '2026-11-02 10:00:00',
            'tax_amount' => 999.00,
        ]);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSeeInOrder(['November 2026', $november->contract_number, 'Sales tax', '$9.00'])
            ->assertSeeInOrder(['October 2026', $october->contract_number, 'VAT', '$20.00'])
            ->assertSee('Tax billed on 2 agreements')
            ->assertSee('$29.00')
            ->assertDontSee('RA-OTHER')
            ->assertDontSee('$999.00');
    }

    public function test_a_reversed_charge_is_corrected_in_the_month_it_was_reversed(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $contract = $this->agreement($business, $customer, [
            'contract_number' => 'RA-REVERSED',
            'charged_to_account_at' => '2026-11-04 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
            'charge_reversed_at' => '2026-12-06 10:00:00',
        ]);

        // A later edit must not turn the credit into a bigger figure.
        $contract->update(['tax_amount' => 18.00, 'total_amount' => 118.00]);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            // November keeps the tax that was billed…
            ->assertSee('November 2026')
            ->assertSee('RA-REVERSED')
            ->assertSee('$9.00')
            // …and December carries the credit that cancels it.
            ->assertSee('December 2026')
            ->assertSee('-$9.00')
            ->assertSee('Post-billing adjustments')
            ->assertSee('Tax billed on 1 agreement')
            // The edit inside the agreement is moot once the charge is reversed.
            ->assertDontSee('+$9.00')
            ->assertDontSee('$18.00');
    }

    public function test_a_later_reversal_credits_sales_in_the_reversal_month(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $this->agreement($business, $customer, [
            'contract_number' => 'RA-NOV-REVERSED',
            'charged_to_account_at' => '2026-11-04 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
            'charge_reversed_at' => '2026-12-06 10:00:00',
        ]);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            // November keeps the sale and the tax it reported.
            ->assertSeeInOrder(['November 2026', 'Gross sales', '$100.00'])
            ->assertSeeInOrder(['November 2026', 'Taxable sales', '$100.00'])
            // December gives the sale back, so the two months net to zero.
            ->assertSeeInOrder(['December 2026', 'Gross sales', '-$100.00'])
            ->assertSeeInOrder(['December 2026', 'Taxable sales', '-$100.00'])
            ->assertSee('-$9.00');
    }

    public function test_an_adjustment_survives_a_later_reversal_of_the_charge(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $contract = $this->agreement($business, $customer, [
            'contract_number' => 'RA-ADJUSTED-REVERSED',
            'charged_to_account_at' => '2026-11-04 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
            'tax_amount' => 18.00,
            'total_amount' => 118.00,
            'charge_reversed_at' => '2027-01-15 10:00:00',
        ]);
        $contract->taxAdjustments()->create(['amount' => 9.00, 'adjusted_at' => '2026-12-02 09:00:00']);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            // The December adjustment is not hidden by the January reversal…
            ->assertSee('December 2026')
            ->assertSee('+$9.00')
            // …and January credits both figures, so the agreement nets to zero.
            ->assertSee('January 2027')
            ->assertSee('-$18.00')
            ->assertSee('Tax billed on 1 agreement');
    }

    public function test_each_post_billing_change_is_reported_in_its_own_month(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $contract = $this->agreement($business, $customer, [
            'contract_number' => 'RA-TWICE',
            'charged_to_account_at' => '2026-11-03 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
        ]);

        // Tax rose to $18 in December, then to $27 in January.
        $contract->update(['tax_amount' => 27.00, 'total_amount' => 127.00]);
        $contract->taxAdjustments()->create(['amount' => 9.00, 'adjusted_at' => '2026-12-02 09:00:00']);
        $contract->taxAdjustments()->create(['amount' => 9.00, 'adjusted_at' => '2027-01-05 09:00:00']);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            // The billing stays put and neither month's adjustment is lost.
            ->assertSeeInOrder(['November 2026', '$9.00'])
            ->assertSeeInOrder(['December 2026', '+$9.00'])
            ->assertSeeInOrder(['January 2027', '+$9.00'])
            ->assertSee('+$18.00')
            ->assertDontSee('+$27.00');
    }

    public function test_a_post_billing_edit_does_not_change_reported_sales(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $contract = $this->agreement($business, $customer, [
            'contract_number' => 'RA-EDITED',
            'charged_to_account_at' => '2026-11-03 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
        ]);

        // Editing the items after billing must not rewrite the month's sales.
        $contract->update(['subtotal' => 150.00, 'total_amount' => 159.00]);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSeeInOrder(['Gross sales', '$100.00'])
            ->assertSeeInOrder(['Taxable sales', '$100.00'])
            ->assertSeeInOrder(['Tax charged', '$9.00'])
            ->assertDontSee('$150.00');
    }

    public function test_a_post_billing_edit_does_not_change_the_reported_tax(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $contract = $this->agreement($business, $customer, [
            'charged_to_account_at' => '2026-11-03 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
        ]);

        // Someone edits the agreement after it was billed: the frozen $9 stands.
        $contract->update(['tax_rate' => 18.00, 'tax_amount' => 18.00, 'total_amount' => 118.00]);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee($contract->contract_number)
            ->assertSee('$9.00')
            ->assertDontSee('$18.00');
    }

    public function test_post_billing_tax_adjustments_show_apart_from_the_billed_figure(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $added = $this->agreement($business, $customer, [
            'contract_number' => 'RA-ADDED',
            'charged_to_account_at' => '2026-11-03 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
        ]);
        // A charge added after billing: $9 more tax is owed.
        $added->update(['tax_amount' => 18.00, 'total_amount' => 118.00]);
        $added->taxAdjustments()->create(['amount' => 9.00, 'adjusted_at' => '2026-12-02 09:00:00']);

        $credited = $this->agreement($business, $customer, [
            'contract_number' => 'RA-CREDITED',
            'charged_to_account_at' => '2026-11-04 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
        ]);
        // Reduced after billing: $5 of tax is credited back.
        $credited->update(['tax_amount' => 4.00, 'total_amount' => 104.00]);
        $credited->taxAdjustments()->create(['amount' => -5.00, 'adjusted_at' => '2026-12-03 09:00:00']);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            // Billed tax stays in November…
            ->assertSee('November 2026')
            ->assertSee('$18.00')
            // …while both adjustments land in the month they happened.
            ->assertSeeInOrder(['December 2026', '-$5.00', '+$9.00'])
            ->assertSee('+$4.00')
            ->assertSee('Post-billing adjustments')
            // Billed $18 and adjustments +$4 stay separate, never netted.
            ->assertDontSee('$22.00')
            ->assertDontSee('$13.00');
    }

    public function test_a_cash_agreement_is_reported_when_it_was_paid_or_completed(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        // Written in October, paid in November: the money arrived in November.
        $paid = $this->agreement($business, $customer, ['contract_number' => 'RA-PAID-LATE']);
        $paid->update(['created_at' => '2026-10-20 10:00:00']);
        Payment::create([
            'business_id' => $business->id,
            'reference_type' => 'rental',
            'reference_id' => $paid->id,
            'kind' => 'payment',
            'amount' => 109.00,
            'payment_method' => 'cash',
            'payment_date' => '2026-11-05 09:00:00',
        ]);

        // Written in September, completed in October: no money to wait for.
        $returned = $this->agreement($business, $customer, ['contract_number' => 'RA-RETURNED']);
        $returned->update(['created_at' => '2026-09-15 10:00:00', 'actual_return_date' => '2026-10-02']);

        $this->actingAs($manager)
            ->get(route('sales-tax.index', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()
            ->assertSee('RA-PAID-LATE')
            ->assertDontSee('RA-RETURNED');

        $this->actingAs($manager)
            ->get(route('sales-tax.index', ['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertSee('RA-RETURNED')
            ->assertDontSee('RA-PAID-LATE');
    }

    public function test_exempt_sales_are_reported_beside_the_tax(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $customer->update([
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => true,
        ]);

        $exempt = $this->agreement($business, $customer, [
            'contract_number' => 'RA-EXEMPT',
            'tax_exempt' => true,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_amount' => 100.00,
            'charged_to_account_at' => '2026-11-06 10:00:00',
        ]);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('RA-EXEMPT')
            ->assertSee('Exempt sales — no tax charged')
            ->assertSee('Exempt sales')
            // The tax line totals nothing; the exempt sale is its own figure.
            ->assertSee('Tax billed on 1 agreement')
            ->assertSee('$100.00');

        $csv = $this->actingAs($manager)->get(route('sales-tax.export'))->streamedContent();
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($csv, 3)))));

        $this->assertSame(
            ['November 2026', '2026-11-06', 'RA-EXEMPT', $customer->full_name, 'Exempt sale', '', 'USD', '', '', '100.00', '', '', 'Current', '', 'EX-4471', '', ''],
            $lines[1]
        );
    }

    public function test_a_billed_agreement_repriced_to_zero_is_not_also_an_exempt_sale(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        // Tax was billed, then the agreement was repriced to zero while the
        // customer's exemption applied: tax_exempt is true, but $9 was billed.
        $this->agreement($business, $customer, [
            'contract_number' => 'RA-BILLED-THEN-EXEMPT',
            'charged_to_account_at' => '2026-11-06 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
            'tax_exempt' => true,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'total_amount' => 100.00,
        ]);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            // The tax row stands on its own…
            ->assertSeeInOrder(['November 2026', 'RA-BILLED-THEN-EXEMPT', '$9.00'])
            // …and the same sale is not repeated as an exempt one.
            ->assertDontSee('Exempt sales — no tax charged');
    }

    public function test_an_exempt_sale_keeps_the_certificate_it_was_billed_with(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $contract = $this->agreement($business, $customer, [
            'contract_number' => 'RA-EXEMPT-CERT',
            'subtotal' => 100.00,
            'total_amount' => 100.00,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'tax_exempt' => true,
            'charged_to_account_at' => '2026-11-04 10:00:00',
            'charged_amount' => 100.00,
            'charged_tax_amount' => 0,
            'tax_exemption_number' => 'EX-OLD',
            'tax_exemption_expires_on' => '2027-01-01',
            'tax_exemption_reason' => 'Resale',
        ]);

        // The customer's record is corrected afterwards; the sale keeps its own.
        $customer->update([
            'tax_exemption_number' => 'EX-NEW',
            'tax_exemption_expires_on' => '2028-06-30',
            'tax_exemption_reason' => 'Government',
        ]);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee($contract->contract_number)
            ->assertSee('EX-OLD')
            ->assertSee('2027-01-01')
            ->assertSee('Resale')
            ->assertDontSee('EX-NEW')
            ->assertDontSee('Government');

        $csv = $this->actingAs($manager)->get(route('sales-tax.export'))->streamedContent();
        $this->assertStringContainsString('EX-OLD', $csv);
        $this->assertStringContainsString('Resale', $csv);
        $this->assertStringNotContainsString('EX-NEW', $csv);
    }

    public function test_each_month_states_gross_taxable_exempt_and_tax(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $customer->update(['tax_exemption_number' => 'EX-4471', 'tax_exempt_copy_on_file' => true]);

        $this->agreement($business, $customer, [
            'contract_number' => 'RA-TAXABLE',
            'charged_to_account_at' => '2026-11-03 10:00:00',
        ]);

        $this->agreement($business, $customer, [
            'contract_number' => 'RA-EXEMPT',
            'subtotal' => 50.00,
            'total_amount' => 50.00,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'tax_exempt' => true,
            'charged_to_account_at' => '2026-11-04 10:00:00',
        ]);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSeeInOrder(['Gross sales', '$150.00'])
            ->assertSeeInOrder(['Taxable sales', '$100.00'])
            ->assertSeeInOrder(['Exempt sales', '$50.00'])
            ->assertSeeInOrder(['Tax charged', '$9.00']);
    }

    public function test_an_unbilled_agreement_reports_its_current_tax(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $contract = $this->agreement($business, $customer, ['contract_number' => 'RA-CASH']);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('RA-CASH')
            ->assertSee('$9.00');
    }

    public function test_totals_are_kept_apart_per_currency(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $this->agreement($business, $customer, [
            'contract_number' => 'RA-USD',
            'currency' => 'USD',
            'charged_to_account_at' => '2026-11-03 10:00:00',
        ]);
        $this->agreement($business, $customer, [
            'contract_number' => 'RA-EUR',
            'currency' => 'EUR',
            'tax_amount' => 20.00,
            'total_amount' => 120.00,
            'charged_to_account_at' => '2026-11-04 10:00:00',
        ]);

        $response = $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('$9.00')
            ->assertSee('€20.00')
            ->assertSee('Tax billed on 2 agreements');

        // Never one figure that adds dollars to euros.
        $response->assertDontSee('$29.00')->assertDontSee('€29.00');
    }

    public function test_the_report_downloads_as_csv_with_the_same_figures(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $contract = $this->agreement($business, $customer, [
            'contract_number' => 'RA-CSV',
            'charged_to_account_at' => '2026-11-03 10:00:00',
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
        ]);
        $contract->update(['tax_amount' => 18.00, 'total_amount' => 118.00]);
        $contract->taxAdjustments()->create(['amount' => 9.00, 'adjusted_at' => '2026-12-02 09:00:00']);

        $response = $this->actingAs($manager)->get(route('sales-tax.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('sales-tax-start-latest.csv', $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();

        // The BOM is what tells Excel the file is UTF-8.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($csv, 3)))));

        $this->assertSame(
            ['Month', 'Date', 'Agreement', 'Customer', 'Tax', 'Rate %', 'Currency', 'Billed tax', 'Adjustment', 'Exempt sales', 'Gross sales', 'Taxable sales', 'Figures', 'Filed', 'Certificate', 'Certificate expires', 'Certificate reason'],
            $lines[0]
        );

        // Newest first: the December adjustment, then the November billing.
        $this->assertSame(['December 2026', '2026-12-02', 'RA-CSV', $customer->full_name, 'Sales tax', '9', 'USD', '', '9.00', '', '', '', 'Current', '', '', '', ''], $lines[1]);
        $this->assertSame(['November 2026', '2026-11-03', 'RA-CSV', $customer->full_name, 'Sales tax', '9', 'USD', '9.00', '', '', '', '', 'Current', '', '', '', ''], $lines[2]);

        // One summary line per month follows the rows.
        $summaries = collect($lines)->filter(fn (array $line) => ($line[2] ?? '') === 'Month summary');

        $this->assertCount(2, $summaries);
        $this->assertSame(
            ['November 2026', '', 'Month summary', '', '', '', 'USD', '9.00', '', '', '100.00', '100.00', 'Current', '', '', '', ''],
            $summaries->first(fn (array $line) => $line[0] === 'November 2026')
        );
        $this->assertSame(
            // December holds only the adjustment, so it has tax but no sales.
            ['December 2026', '', 'Month summary', '', '', '', 'USD', '9.00', '', '', '', '', 'Current', '', '', '', ''],
            $summaries->first(fn (array $line) => $line[0] === 'December 2026')
        );
        $this->assertCount(5, $lines);

        // The download honours the same date filter as the page.
        $filtered = $this->actingAs($manager)->get(route('sales-tax.export', ['from' => '2026-12-01']));
        $filteredLines = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($filtered->streamedContent(), 3)))));

        $this->assertCount(3, $filteredLines);
        $this->assertSame('2026-12-02', $filteredLines[1][1]);
    }

    public function test_the_filter_limits_the_report_to_a_date_range(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $this->agreement($business, $customer, ['charged_to_account_at' => '2026-11-03 10:00:00']);
        $this->agreement($business, $customer, ['contract_number' => 'RA-OCT', 'charged_to_account_at' => '2026-10-15 10:00:00']);

        $this->actingAs($manager)
            ->get(route('sales-tax.index', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()
            ->assertSee('November 2026')
            ->assertDontSee('October 2026')
            ->assertSee('$9.00');
    }

    public function test_the_report_is_tenant_scoped_and_hidden_from_super_admins(): void
    {
        $business = $this->business();
        $customer = $this->customer($business);
        $this->agreement($business, $customer, ['charged_to_account_at' => '2026-11-03 10:00:00']);

        $intruder = $this->manager($this->business('Other Rentals'), 'other@test.test');

        $this->actingAs($intruder)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('No tax has been billed in this period.')
            ->assertDontSee('RA-');
    }
}
