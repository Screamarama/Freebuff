<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\RentalContract;
use App\Models\TaxFiling;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTaxFilingTest extends TestCase
{
    use RefreshDatabase;

    private function manager(string $name = 'Acme Rentals', string $email = 'manager@acme.test'): User
    {
        $business = Business::create(['name' => $name, 'currency' => 'USD', 'is_active' => true]);

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
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'charge',
            'credit_limit' => '5000.00',
        ]);
    }

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
            'tax_rate' => 9,
            'tax_amount' => 9.00,
            'total_amount' => 109.00,
            'charged_amount' => 109.00,
            'charged_tax_amount' => 9.00,
            'charged_tax_label' => 'Sales tax',
            'charged_to_account_at' => '2026-11-03 10:00:00',
            ...$attributes,
        ]);
    }

    private function file(string $period = '2026-11')
    {
        // The month being filed has to be over, so file it from December.
        $this->travelTo('2026-12-01 09:00:00');

        return $this->post(route('sales-tax.filings.store'), ['period' => $period]);
    }

    public function test_marking_a_month_as_filed_snapshots_its_figures(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)
            ->file()
            ->assertRedirect(route('sales-tax.index'));

        $filing = TaxFiling::sole();
        $this->assertSame('2026-11', $filing->period);
        $this->assertSame($manager->id, $filing->filed_by_id);
        $this->assertSame(9.0, (float) $filing->snapshot['summary']['tax']['USD']);
        $this->assertSame(100.0, (float) $filing->snapshot['summary']['gross']['USD']);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('Filed')
            ->assertSee('No changes since it was filed');
    }

    public function test_an_adjustment_after_filing_is_shown_against_the_filed_figures(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $contract = $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)->file();

        // Tax rose by $5 after the month had been filed.
        $contract->update(['tax_amount' => 14.00, 'total_amount' => 114.00]);
        $contract->taxAdjustments()->create(['amount' => 5.00, 'adjusted_at' => '2026-11-20 09:00:00']);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('Changed since this month was filed')
            ->assertSee($contract->contract_number)
            ->assertSee('(new)')
            // The summary moved by the same amount.
            ->assertSee('Tax +$5.00')
            ->assertDontSee('No changes since it was filed');
    }

    public function test_a_reversal_after_filing_is_shown_as_a_change(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $contract = $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)->file();

        $contract->update(['charge_reversed_at' => '2026-11-25 09:00:00']);

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('Changed since this month was filed')
            ->assertSee('-$9.00')
            ->assertSee('Tax -$9.00');
    }

    public function test_refiling_accepts_the_current_figures(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $contract = $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)->file();

        $contract->update(['tax_amount' => 14.00, 'total_amount' => 114.00]);
        $contract->taxAdjustments()->create(['amount' => 5.00, 'adjusted_at' => '2026-11-20 09:00:00']);

        $this->actingAs($manager)->file();

        $this->assertSame(14.0, (float) TaxFiling::current()->sole()->snapshot['summary']['tax']['USD']);
        $this->assertSame(2, TaxFiling::count());

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('No changes since it was filed')
            ->assertDontSee('Changed since this month was filed');
    }

    public function test_a_filed_month_downloads_exactly_as_filed(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $contract = $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)->file();

        // The month moves after filing; the filed download must not.
        $contract->update(['tax_amount' => 14.00, 'total_amount' => 114.00]);
        $contract->taxAdjustments()->create(['amount' => 5.00, 'adjusted_at' => '2026-11-20 09:00:00']);

        $response = $this->actingAs($manager)->get(route('sales-tax.filings.export', TaxFiling::current()->sole()));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('sales-tax-filed-2026-11.csv', $response->headers->get('content-disposition'));

        $csv = $response->streamedContent();
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($csv, 3)))));

        $this->assertSame(['Month', 'Filed at', 'Filed by', 'Item', 'Currency', 'Amount', 'Figures'], $lines[0]);

        $items = collect(array_slice($lines, 1))->keyBy(fn (array $line) => $line[3]);

        $this->assertSame(['November 2026', now()->format('Y-m-d'), 'Mia Manager', 'Tax charged', 'USD', '9.00', 'Filed'], $items['Tax charged']);
        $this->assertSame(['November 2026', now()->format('Y-m-d'), 'Mia Manager', 'Gross sales', 'USD', '100.00', 'Filed'], $items['Gross sales']);
        // The adjustment made after filing is not in the filed figures.
        $this->assertSame(
            ['November 2026', now()->format('Y-m-d'), 'Mia Manager', $contract->contract_number.' · Sales tax', 'USD', '9.00', 'Filed'],
            $items[$contract->contract_number.' · Sales tax']
        );
    }

    public function test_the_live_export_marks_a_filed_month_as_current(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $contract = $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)->file();

        $contract->update(['tax_amount' => 14.00, 'total_amount' => 114.00]);
        $contract->taxAdjustments()->create(['amount' => 5.00, 'adjusted_at' => '2026-11-20 09:00:00']);

        $response = $this->actingAs($manager)->get(route('sales-tax.export'));
        $csv = $response->streamedContent();
        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($csv, 3)))));
        $month = collect($lines)->first(fn (array $line) => ($line[2] ?? '') === 'Month summary');

        // Current figures, but the file names the filing they differ from.
        $this->assertSame('Current', $month[12]);
        $this->assertSame(now()->format('Y-m-d'), $month[13]);
        $this->assertSame('14.00', $month[7]);
    }

    public function test_a_filed_download_is_tenant_scoped(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $this->agreement($business, $this->customer($business));
        $this->actingAs($manager)->file();

        $other = $this->manager('Other Rentals', 'other@acme.test');

        $this->actingAs($other)
            ->get(route('sales-tax.filings.export', TaxFiling::where('business_id', $business->id)->current()->sole()))
            ->assertNotFound();
    }

    public function test_refiling_keeps_the_earlier_filed_figures_recoverable(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $contract = $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)->file();
        $first = TaxFiling::current()->sole();

        $contract->update(['tax_amount' => 14.00, 'total_amount' => 114.00]);
        $contract->taxAdjustments()->create(['amount' => 5.00, 'adjusted_at' => '2026-11-20 09:00:00']);

        $this->actingAs($manager)->file();

        // The earlier version is kept, with the figures it reported.
        $this->assertSame(2, TaxFiling::count());
        $this->assertNotNull($first->fresh()->superseded_at);
        $this->assertSame(9.0, (float) $first->fresh()->snapshot['summary']['tax']['USD']);
        $this->assertSame(14.0, (float) TaxFiling::current()->sole()->snapshot['summary']['tax']['USD']);

        // The replaced version still downloads exactly as it was filed…
        $old = $this->actingAs($manager)->get(route('sales-tax.filings.export', $first->fresh()))->streamedContent();
        $this->assertStringContainsString('9.00', $old);
        $this->assertStringNotContainsString('14.00', $old);

        // …and the page offers it beside the current filing.
        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('Earlier filings')
            ->assertSee('by Mia Manager');

        // Filing and refiling are both audited.
        $this->assertDatabaseHas('audit_logs', ['action' => 'tax.filed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tax.refiled']);
    }

    public function test_the_amendment_lists_what_moved_since_filing(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $contract = $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)->file();
        $filing = TaxFiling::current()->sole();

        $contract->update(['tax_amount' => 14.00, 'total_amount' => 114.00]);
        $contract->taxAdjustments()->create(['amount' => 5.00, 'adjusted_at' => '2026-11-20 09:00:00']);

        $response = $this->actingAs($manager)->get(route('sales-tax.filings.amendment', $filing));
        $response->assertOk();
        $this->assertStringContainsString('sales-tax-amendment-2026-11.csv', $response->headers->get('content-disposition'));

        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", substr($response->streamedContent(), 3)))));

        $this->assertSame(['Month', 'Filed at', 'Filed by', 'Change', 'Item', 'Currency', 'Amount', 'Was', 'Figures'], $lines[0]);

        $summary = collect($lines)->firstWhere(4, 'Tax charged');
        $this->assertSame(['November 2026', now()->format('Y-m-d'), 'Mia Manager', 'Summary', 'Tax charged', 'USD', '5.00', '', 'Amendment'], $summary);

        $added = collect($lines)->first(fn (array $line) => $line[3] === 'Added');
        $this->assertSame(
            ['November 2026', now()->format('Y-m-d'), 'Mia Manager', 'Added', $contract->contract_number.' · Adjustment', 'USD', '5.00', '', 'Amendment'],
            $added
        );

        // The page offers the statement beside the changes it describes.
        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('Download amendment CSV');
    }

    public function test_the_amendment_lists_rows_that_disappeared(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $contract = $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)->file();
        $filing = TaxFiling::current()->sole();

        $contract->delete();

        $lines = array_map('str_getcsv', array_values(array_filter(explode("\n", substr(
            $this->actingAs($manager)->get(route('sales-tax.filings.amendment', $filing))->streamedContent(), 3
        )))));

        $removed = collect($lines)->firstWhere(3, 'Removed');
        $this->assertSame(
            ['November 2026', now()->format('Y-m-d'), 'Mia Manager', 'Removed', $contract->contract_number.' · Sales tax', 'USD', '9.00', '', 'Amendment'],
            $removed
        );

        $tax = collect($lines)->firstWhere(4, 'Tax charged');
        $this->assertSame('-9.00', $tax[6]);
        $gross = collect($lines)->firstWhere(4, 'Gross sales');
        $this->assertSame('-100.00', $gross[6]);
    }

    public function test_an_amendment_is_tenant_scoped(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $this->agreement($business, $this->customer($business));
        $this->actingAs($manager)->file();

        $other = $this->manager('Other Rentals', 'other@acme.test');

        $this->actingAs($other)
            ->get(route('sales-tax.filings.amendment', TaxFiling::current()->sole()))
            ->assertNotFound();
    }

    public function test_a_month_that_has_not_ended_cannot_be_filed(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $this->agreement($business, $this->customer($business));

        // Mid-November: charges can still arrive for November.
        $this->travelTo('2026-11-16 12:00:00');

        $this->actingAs($manager)
            ->post(route('sales-tax.filings.store'), ['period' => '2026-11'])
            ->assertSessionHasErrors('period');

        $this->assertSame(0, TaxFiling::count());

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('Files after Nov 30, 2026')
            ->assertDontSee('Mark filed');
    }

    public function test_a_future_month_cannot_be_filed(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $this->agreement($business, $this->customer($business), [
            'charged_to_account_at' => '2026-12-03 10:00:00',
        ]);

        $this->travelTo('2026-11-16 12:00:00');

        $this->actingAs($manager)
            ->post(route('sales-tax.filings.store'), ['period' => '2026-12'])
            ->assertSessionHasErrors('period');

        $this->assertSame(0, TaxFiling::count());
    }

    public function test_a_closed_month_files_once_it_has_ended(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $this->agreement($business, $this->customer($business));

        $this->travelTo('2026-12-01 00:01:00');

        $this->actingAs($manager)
            ->post(route('sales-tax.filings.store'), ['period' => '2026-11'])
            ->assertRedirect(route('sales-tax.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-11', TaxFiling::sole()->period);
    }

    public function test_another_tenants_filing_does_not_mark_this_month(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $this->agreement($business, $this->customer($business));

        $other = $this->manager('Other Rentals', 'other@acme.test');
        $otherBusiness = Business::find($other->business_id);
        $this->agreement($otherBusiness, $this->customer($otherBusiness));

        $this->actingAs($other)->file();

        $this->actingAs($manager)
            ->get(route('sales-tax.index'))
            ->assertOk()
            ->assertSee('Mark filed')
            ->assertDontSee('No changes since it was filed');
    }

    public function test_a_month_with_nothing_to_file_is_refused(): void
    {
        $manager = $this->manager();
        $business = Business::find($manager->business_id);
        $this->agreement($business, $this->customer($business));

        $this->actingAs($manager)
            ->file('2025-01')
            ->assertSessionHasErrors('period');

        $this->assertSame(0, TaxFiling::count());
    }
}
