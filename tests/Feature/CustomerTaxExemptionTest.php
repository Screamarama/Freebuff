<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTaxExemptionTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        $business = Business::create([
            'name' => 'Acme Rentals',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $user = new User(['name' => 'Mia Manager', 'email' => 'manager@acme.test', 'password' => 'password']);
        $user->role = 'manager';
        $user->business_id = $business->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_code' => 'C-TAX1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => '1',
        ], $overrides);
    }

    public function test_creating_a_customer_stores_the_exemption_and_the_copy_on_file(): void
    {
        $this->actingAs($this->manager())
            ->post(route('customers.store'), $this->payload())
            ->assertRedirect(route('customers.index'));

        $customer = Customer::where('customer_code', 'C-TAX1')->sole();

        $this->assertSame('EX-4471', $customer->tax_exemption_number);
        $this->assertTrue($customer->tax_exempt_copy_on_file);
        $this->assertTrue($customer->isTaxExempt());
    }

    public function test_an_expired_certificate_stops_exempting_the_customer(): void
    {
        $manager = $this->manager();

        $customer = Customer::create([
            'business_id' => $manager->business_id,
            'customer_code' => 'C-TAX1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => true,
            'tax_exemption_expires_on' => today()->subDay(),
        ]);

        $this->assertTrue($customer->hasTaxExemptionCertificate());
        $this->assertTrue($customer->taxExemptionHasExpired());
        $this->assertFalse($customer->isTaxExempt());

        // Valid through its own expiry date…
        $customer->update(['tax_exemption_expires_on' => today()]);
        $this->assertFalse($customer->fresh()->taxExemptionHasExpired());
        $this->assertTrue($customer->fresh()->isTaxExempt());

        // …and never expiring when no date was recorded.
        $customer->update(['tax_exemption_expires_on' => null]);
        $this->assertTrue($customer->fresh()->isTaxExempt());
    }

    public function test_the_customer_list_and_page_show_the_expiry_and_flag_expired_certificates(): void
    {
        $manager = $this->manager();

        $expired = Customer::create([
            'business_id' => $manager->business_id,
            'customer_code' => 'C-LAPSED',
            'first_name' => 'Ivy',
            'last_name' => 'Expired',
            'phone' => '555-0101',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-OLD',
            'tax_exempt_copy_on_file' => true,
            'tax_exemption_expires_on' => '2026-01-31',
        ]);

        $current = Customer::create([
            'business_id' => $manager->business_id,
            'customer_code' => 'C-CURRENT',
            'first_name' => 'Cal',
            'last_name' => 'Current',
            'phone' => '555-0102',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-NEW',
            'tax_exempt_copy_on_file' => true,
            'tax_exemption_expires_on' => '2027-06-30',
        ]);

        $this->actingAs($manager)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSeeInOrder(['Ivy Expired', '2026-01-31', 'Expired'])
            ->assertSee('2027-06-30')
            ->assertDontSee('2027-06-30 Expired');

        $this->actingAs($manager)
            ->get(route('customers.show', $expired))
            ->assertOk()
            ->assertSee('EX-OLD')
            ->assertSee('expires 2026-01-31')
            ->assertSee('Expired');

        $this->actingAs($manager)
            ->get(route('customers.show', $current))
            ->assertOk()
            ->assertSee('expires 2027-06-30')
            ->assertDontSee('Expired');
    }

    public function test_the_agreement_warns_when_the_certificate_has_expired(): void
    {
        $manager = $this->manager();

        $customer = Customer::create([
            'business_id' => $manager->business_id,
            'customer_code' => 'C-TAX1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => true,
            'tax_exemption_expires_on' => '2026-01-31',
        ]);

        $contract = RentalContract::create([
            'business_id' => $manager->business_id,
            'contract_number' => 'RA-EXPIRED',
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => 'active',
            'subtotal' => 100.00,
            'tax_rate' => 9.00,
            'tax_amount' => 9.00,
            'total_amount' => 109.00,
        ]);

        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertSee('exemption certificate expired on')
            ->assertSee('2026-01-31');

        // Repricing an agreement carries the same warning.
        $this->actingAs($manager)
            ->get(route('rental-agreements.edit', $contract))
            ->assertOk()
            ->assertSee('Repricing will')
            ->assertSee('2026-01-31');

        // A current certificate raises nothing.
        $customer->update(['tax_exemption_expires_on' => '2027-01-31']);

        $this->actingAs($manager)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertDontSee('exemption certificate expired on');
    }

    public function test_every_exemption_change_is_audited_with_old_and_new_values(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('customers.store'), $this->payload(['tax_exemption_expires_on' => '2026-12-31']))
            ->assertRedirect(route('customers.index'));

        $customer = Customer::where('customer_code', 'C-TAX1')->sole();
        $created = AuditLog::where('action', 'customer.tax_exemption_updated')->latest('id')->first();

        $this->assertSame('Jane Doe', $created->details['name']);
        $this->assertSame(['from' => null, 'to' => 'EX-4471'], $created->details['changes']['tax_exemption_number']);
        $this->assertSame(['from' => null, 'to' => '2026-12-31'], $created->details['changes']['tax_exemption_expires_on']);
        $this->assertSame(['from' => 'No', 'to' => 'Yes'], $created->details['changes']['tax_exempt_copy_on_file']);

        // Moving the expiry is recorded as a change, not a fresh grant.
        $this->actingAs($manager)
            ->put(route('customers.update', $customer), $this->payload(['tax_exemption_expires_on' => '2027-12-31']))
            ->assertRedirect(route('customers.index'));

        $updated = AuditLog::where('action', 'customer.tax_exemption_updated')->latest('id')->first();
        $this->assertSame(['from' => '2026-12-31', 'to' => '2027-12-31'], $updated->details['changes']['tax_exemption_expires_on']);
        $this->assertArrayNotHasKey('tax_exemption_number', $updated->details['changes']);

        // An ordinary edit says nothing about the exemption.
        $before = AuditLog::where('action', 'customer.tax_exemption_updated')->count();

        $this->actingAs($manager)
            ->put(route('customers.update', $customer), $this->payload([
                'tax_exemption_expires_on' => '2027-12-31',
                'phone' => '555-0199',
            ]))
            ->assertRedirect(route('customers.index'));

        $this->assertSame($before, AuditLog::where('action', 'customer.tax_exemption_updated')->count());
    }

    public function test_the_certificate_is_judged_on_the_agreements_own_date(): void
    {
        $manager = $this->manager();

        $business = Business::find($manager->business_id);
        $business->update(['country' => 'US', 'state' => 'PA', 'state_sales_tax' => 6.00]);

        $customer = Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-DATED',
            'first_name' => 'Dana',
            'last_name' => 'Dated',
            'phone' => '555-0100',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => true,
            'tax_exemption_expires_on' => '2026-11-10',
        ]);

        $product = Product::create([
            'business_id' => $business->id,
            'equipment_number' => 'EXC-9',
            'name' => 'Excavator',
            'product_type' => 'rental',
        ]);

        // Rented before the certificate lapsed, even though it has by "today".
        $past = RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-BEFORE',
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-03',
            'status' => 'active',
        ]);
        $past->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 100.00,
            'duration' => 1,
            'subtotal' => 100.00,
        ]);
        $past->syncTotals();

        $this->assertSame('0.00', $past->fresh()->tax_amount);
        $this->assertTrue($past->fresh()->tax_exempt);

        // Rented after it lapsed: taxed, even though the certificate is still
        // the one on file.
        $future = RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-AFTER',
            'customer_id' => $customer->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-03',
            'status' => 'active',
        ]);
        $future->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 100.00,
            'duration' => 1,
            'subtotal' => 100.00,
        ]);
        $future->syncTotals();

        $this->assertSame('6.00', $future->fresh()->tax_amount);
        $this->assertFalse($future->fresh()->tax_exempt);
    }

    public function test_a_clerk_cannot_grant_or_change_an_exemption(): void
    {
        $manager = $this->manager();

        $clerk = new User(['name' => 'Cara Clerk', 'email' => 'clerk@acme.test', 'password' => 'password']);
        $clerk->role = 'clerk';
        $clerk->business_id = $manager->business_id;
        $clerk->email_verified_at = now();
        $clerk->save();

        // A clerk cannot create an exempt customer at all.
        $this->actingAs($clerk)
            ->post(route('customers.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseMissing('customers', ['customer_code' => 'C-TAX1']);

        $customer = Customer::create([
            'business_id' => $manager->business_id,
            'customer_code' => 'C-TAX1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => true,
            'tax_exemption_expires_on' => '2026-12-31',
        ]);

        // Nor move an existing one, whether extending it or removing it.
        $this->actingAs($clerk)
            ->put(route('customers.update', $customer), $this->payload(['tax_exemption_expires_on' => '2029-12-31']))
            ->assertForbidden();

        $this->actingAs($clerk)
            ->put(route('customers.update', $customer), $this->payload([
                'tax_exempt_copy_on_file' => '0',
                'tax_exemption_number' => '',
            ]))
            ->assertForbidden();

        $customer->refresh();
        $this->assertSame('EX-4471', $customer->tax_exemption_number);
        $this->assertSame('2026-12-31', $customer->tax_exemption_expires_on->toDateString());
        $this->assertTrue($customer->isTaxExempt());

        // Ordinary details are still theirs to edit, and the exemption survives.
        $this->actingAs($clerk)
            ->put(route('customers.update', $customer), [
                'customer_code' => 'C-TAX1',
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'phone' => '555-0199',
                'account_type' => 'cash',
            ])
            ->assertRedirect(route('customers.index'));

        $customer->refresh();
        $this->assertSame('555-0199', $customer->phone);
        $this->assertSame('EX-4471', $customer->tax_exemption_number);
        $this->assertSame('2026-12-31', $customer->tax_exemption_expires_on->toDateString());
        $this->assertTrue($customer->tax_exempt_copy_on_file);

        // The form does not offer them the fields either.
        $this->actingAs($clerk)
            ->get(route('customers.edit', $customer))
            ->assertOk()
            ->assertDontSee('Tax exemption number')
            ->assertSee('set and changed by an admin or manager');
    }

    public function test_an_unchecked_box_clears_exemption_on_edit(): void
    {
        $manager = $this->manager();

        $customer = Customer::create([
            'business_id' => $manager->business_id,
            'customer_code' => 'C-TAX1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => true,
        ]);

        $this->actingAs($manager)
            ->put(route('customers.update', $customer), $this->payload([
                'tax_exemption_number' => 'EX-4471',
                'tax_exempt_copy_on_file' => '0',
            ]))
            ->assertRedirect(route('customers.index'));

        $customer->refresh();

        $this->assertFalse($customer->tax_exempt_copy_on_file);
        $this->assertFalse($customer->isTaxExempt());
    }

    public function test_a_number_without_the_copy_is_not_exempt(): void
    {
        $manager = $this->manager();

        $customer = Customer::create([
            'business_id' => $manager->business_id,
            'customer_code' => 'C-TAX1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => false,
        ]);

        $this->assertFalse($customer->isTaxExempt());

        $customer->update(['tax_exemption_number' => null, 'tax_exempt_copy_on_file' => true]);
        $this->assertFalse($customer->isTaxExempt());
    }
}
