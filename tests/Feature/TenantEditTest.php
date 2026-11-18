<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantEditTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(int $id, string $name = 'Test Rental Business'): Business
    {
        return Business::forceCreate([
            'id' => $id,
            'name' => $name,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function user(string $role, ?Business $tenant = null, string $email = 'user@example.test'): User
    {
        $user = new User(['name' => ucfirst($role), 'email' => $email, 'password' => 'password']);
        $user->role = $role;
        $user->business_id = $tenant?->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Renamed Rentals',
            'email' => 'hello@renamed.test',
            'phone' => '555-0199',
            'address' => '9 Market Street',
            'country' => 'US',
            'state' => 'Ohio',
            'currency' => 'eur',
        ], $overrides);
    }

    public function test_a_super_admin_can_edit_a_tenant_created_earlier(): void
    {
        $tenant = $this->tenant(9);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->get(route('tenants.edit', $tenant))
            ->assertOk()
            ->assertSee('Test Rental Business')
            ->assertSee('Business name')
            ->assertSee(route('tenants.update', $tenant), false);

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form())
            ->assertRedirect(route('tenants.index'))
            ->assertSessionHas('success');

        $tenant->refresh();

        $this->assertSame('Renamed Rentals', $tenant->name);
        $this->assertSame('hello@renamed.test', $tenant->email);
        $this->assertSame('9 Market Street', $tenant->address);
        $this->assertSame('OHIO', strtoupper((string) $tenant->state));
        $this->assertSame('EUR', $tenant->currency);
        $this->assertSame('US', $tenant->country);

        $log = AuditLog::where('action', 'tenant.updated')->latest('id')->firstOrFail();

        $this->assertSame(9, $log->business_id);
        $this->assertSame(['from' => 'Test Rental Business', 'to' => 'Renamed Rentals'], $log->details['changes']['name']);
        $this->assertSame(['from' => 'USD', 'to' => 'EUR'], $log->details['changes']['currency']);
        $this->assertSame(['from' => null, 'to' => 'Ohio'], $log->details['changes']['state']);
        $this->assertStringContainsString('Name: Test Rental Business → Renamed Rentals', $log->changeSummary());
        $this->assertStringContainsString('Currency: USD → EUR', $log->changeSummary());
    }

    public function test_the_audit_export_carries_the_changes_payload(): void
    {
        $tenant = $this->tenant(9);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)->put(route('tenants.update', $tenant), $this->form());

        $csv = $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs.export', ['tenant' => $tenant->id]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Business details updated', $csv);
        $this->assertStringContainsString('Renamed Rentals', $csv);

        $cells = collect(explode("\n", $csv))
            ->map(fn (string $line) => str_getcsv($line))
            ->first(fn (array $cell) => ($cell[5] ?? null) === 'Business details updated');

        $this->assertNotNull($cells, 'the export is missing the tenant.updated row');

        $details = json_decode($cells[10], true);

        $this->assertSame(['from' => 'USD', 'to' => 'EUR'], $details['changes']['currency']);
        $this->assertSame(['from' => 'Test Rental Business', 'to' => 'Renamed Rentals'], $details['changes']['name']);
    }

    public function test_saving_unchanged_details_writes_no_audit_row(): void
    {
        $tenant = $this->tenant(9);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), [
                'name' => $tenant->name,
                'currency' => $tenant->currency,
            ])
            ->assertRedirect(route('tenants.index'));

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_an_unsupported_currency_is_rejected_everywhere_a_tenant_sets_one(): void
    {
        $tenant = $this->tenant(9);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');
        $admin = $this->user('admin', $tenant, 'admin@tenant.test');

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['currency' => 'dollars']))
            ->assertSessionHasErrors('currency');

        $this->actingAs($admin)
            ->put(route('business.update'), $this->form(['currency' => 'dollars']))
            ->assertSessionHasErrors('currency');

        $this->actingAs($superAdmin)
            ->post(route('tenants.store'), [
                'name' => 'New Tenant',
                'currency' => 'dollars',
                'admin_name' => 'New Admin',
                'admin_email' => 'new-admin@example.test',
                'password' => 'secret-password',
            ])
            ->assertSessionHasErrors('currency');

        $this->assertSame('USD', $tenant->fresh()->currency);
    }

    public function test_the_country_decides_which_states_can_be_saved(): void
    {
        $tenant = $this->tenant(9);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['country' => 'CA', 'state' => 'Ontario']))
            ->assertRedirect(route('tenants.index'));

        $this->assertSame('CA', $tenant->fresh()->country);
        $this->assertSame('Ontario', $tenant->fresh()->state);

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['country' => 'CA', 'state' => 'Ohio']))
            ->assertSessionHasErrors('state');

        $this->assertSame('Ontario', $tenant->fresh()->state);
    }

    public function test_switching_country_forces_a_state_from_the_new_list(): void
    {
        $tenant = $this->tenant(9);
        $tenant->update(['state' => 'Bavaria']);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        // Same country: the stored state that predates the list is kept.
        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['country' => 'US', 'state' => 'Bavaria']))
            ->assertRedirect(route('tenants.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Bavaria', $tenant->fresh()->state);

        // New country with the old state still selected: rejected.
        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['country' => 'CA', 'state' => 'Bavaria']))
            ->assertSessionHasErrors('state');

        $this->assertSame('US', $tenant->fresh()->country);

        // New country with a state from its own list: accepted.
        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['country' => 'CA', 'state' => 'Ontario']))
            ->assertRedirect(route('tenants.index'));

        $this->assertSame('Ontario', $tenant->fresh()->state);
        $this->assertSame('CA', $tenant->fresh()->country);
    }

    public function test_city_and_sales_taxes_are_saved_on_create_and_edit(): void
    {
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->post(route('tenants.store'), [
                'name' => 'Columbus Rentals',
                'country' => 'US',
                'state' => 'Ohio',
                'city' => 'Columbus',
                'currency' => 'USD',
                'state_sales_tax' => 6,
                'county_sales_tax' => 2,
                'city_sales_tax' => 1,
                'admin_name' => 'Columbus Admin',
                'admin_email' => 'columbus@example.test',
                'password' => 'secret-password',
            ])
            ->assertRedirect(route('tenants.index'));

        $tenant = Business::where('name', 'Columbus Rentals')->firstOrFail();

        $this->assertSame('Columbus', $tenant->city);
        $this->assertSame(6, $tenant->state_sales_tax);
        $this->assertSame(2, $tenant->county_sales_tax);
        $this->assertSame(1, $tenant->city_sales_tax);
        $this->assertNull($tenant->vat);

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form([
                'city' => 'Dayton',
                'state_sales_tax' => 7,
                'county_sales_tax' => 3,
                'city_sales_tax' => 2,
            ]))
            ->assertRedirect(route('tenants.index'));

        $tenant->refresh();

        $this->assertSame('Dayton', $tenant->city);
        $this->assertSame(7, $tenant->state_sales_tax);
        $this->assertSame(3, $tenant->county_sales_tax);
        $this->assertSame(2, $tenant->city_sales_tax);
        $this->assertNull($tenant->vat);
    }

    public function test_a_tenant_cannot_save_sales_tax_and_vat_together(): void
    {
        $tenant = $this->tenant(9);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        // A US tenant may not carry VAT…
        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form([
                'country' => 'US',
                'state' => 'Ohio',
                'state_sales_tax' => 6,
                'vat' => 20,
            ]))
            ->assertSessionHasErrors('vat');

        // …and a non-US tenant may not carry state/county/city sales tax.
        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form([
                'country' => 'CA',
                'state' => 'Ontario',
                'county_sales_tax' => 2,
                'vat' => 20,
            ]))
            ->assertSessionHasErrors('county_sales_tax');

        // Each on its own is fine.
        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['country' => 'CA', 'state' => 'Ontario', 'vat' => 20]))
            ->assertRedirect(route('tenants.index'));

        $this->assertSame(20, $tenant->fresh()->vat);
        $this->assertNull($tenant->fresh()->state_sales_tax);

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['country' => 'US', 'state' => 'Ohio', 'state_sales_tax' => 6]))
            ->assertRedirect(route('tenants.index'));

        $this->assertSame(6, $tenant->fresh()->state_sales_tax);
        // Switching back to the US clears the VAT that no longer applies.
        $this->assertNull($tenant->fresh()->vat);
    }

    public function test_fractional_tax_rates_are_saved(): void
    {
        $tenant = $this->tenant(9);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['state_sales_tax' => 8.875, 'county_sales_tax' => 0.25]))
            ->assertRedirect(route('tenants.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(8.875, (float) $tenant->fresh()->state_sales_tax);
        $this->assertSame(0.25, (float) $tenant->fresh()->county_sales_tax);

        // More than three decimals is not a rate anyone can be billed at.
        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['state_sales_tax' => 8.8755]))
            ->assertSessionHasErrors('state_sales_tax');
    }

    public function test_sales_taxes_must_be_percentages_within_range(): void
    {
        $tenant = $this->tenant(9);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form([
                'state_sales_tax' => 101,
                'county_sales_tax' => 'six',
                'city_sales_tax' => -1,
            ]))
            ->assertSessionHasErrors(['state_sales_tax', 'county_sales_tax', 'city_sales_tax']);

        // VAT is validated the same way for a country that uses it.
        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['country' => 'CA', 'state' => 'Ontario', 'vat' => 101]))
            ->assertSessionHasErrors('vat');

        $this->assertNull($tenant->fresh()->state_sales_tax);
    }

    public function test_a_new_tenant_keeps_its_country_and_state(): void
    {
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->post(route('tenants.store'), [
                'name' => 'Maple Rentals',
                'country' => 'CA',
                'state' => 'Ontario',
                'currency' => 'CAD',
                'admin_name' => 'Maple Admin',
                'admin_email' => 'maple@example.test',
                'password' => 'secret-password',
            ])
            ->assertRedirect(route('tenants.index'));

        $this->assertDatabaseHas('businesses', [
            'name' => 'Maple Rentals',
            'country' => 'CA',
            'state' => 'Ontario',
            'currency' => 'CAD',
        ]);
    }

    public function test_editing_a_tenant_needs_a_name_and_currency(): void
    {
        $tenant = $this->tenant(9);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->put(route('tenants.update', $tenant), $this->form(['name' => '', 'currency' => '']))
            ->assertSessionHasErrors(['name', 'currency']);

        $this->assertSame('Test Rental Business', $tenant->fresh()->name);
    }

    public function test_only_a_super_admin_can_edit_another_tenant(): void
    {
        $tenant = $this->tenant(9);
        $admin = $this->user('admin', $tenant, 'admin@tenant.test');

        $this->actingAs($admin)->get(route('tenants.edit', $tenant))->assertForbidden();
        $this->actingAs($admin)
            ->put(route('tenants.update', $tenant), $this->form())
            ->assertForbidden();
    }

    public function test_a_tenant_admin_can_edit_their_own_business_details(): void
    {
        $tenant = $this->tenant(9);
        $admin = $this->user('admin', $tenant, 'admin@tenant.test');

        $this->actingAs($admin)
            ->put(route('business.update'), $this->form(['name' => 'Own Business', 'currency' => 'gbp']))
            ->assertRedirect(route('business.edit'));

        $tenant->refresh();

        $this->assertSame('Own Business', $tenant->name);
        $this->assertSame('GBP', $tenant->currency);

        // The admin's own edit is in the tenant's audit log too.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.updated',
            'business_id' => $tenant->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_a_clerk_cannot_edit_business_details(): void
    {
        $tenant = $this->tenant(9);
        $clerk = $this->user('clerk', $tenant, 'clerk@tenant.test');

        $this->actingAs($clerk)->get(route('business.edit'))->assertForbidden();
        $this->actingAs($clerk)
            ->put(route('business.update'), $this->form())
            ->assertForbidden();
    }
}
