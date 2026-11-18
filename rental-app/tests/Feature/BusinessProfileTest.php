<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ContractTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    private function tenantUser(string $role = 'admin', array $businessAttributes = []): User
    {
        $tenant = Business::create([
            'name' => 'Test Rental Business',
            'address' => '1447 Monroe Street',
            'phone' => '555-0100',
            'email' => 'info@testrental.test',
            'state' => 'Pennsylvania',
            'currency' => 'USD',
            'is_active' => true,
            ...$businessAttributes,
        ]);

        $user = new User(['name' => 'Tenant Admin', 'email' => $role . '@tenant.test', 'password' => 'password']);
        $user->role = $role;
        $user->business_id = $tenant->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    public function test_profile_page_shows_the_tenant_details(): void
    {
        $this->actingAs($this->tenantUser());

        $this->get(route('business.edit'))
            ->assertOk()
            ->assertSee('Test Rental Business')
            ->assertSee('1447 Monroe Street')
            ->assertSee('Pennsylvania');
    }

    public function test_tenant_can_update_its_business_details(): void
    {
        $admin = $this->tenantUser();
        $this->actingAs($admin);

        $this->put(route('business.update'), [
            'name' => 'Hudson Valley Rentals',
            'email' => 'hello@hudsonvalley.test',
            'phone' => '555-0199',
            'address' => '88 River Road, Kingston',
            'state' => 'New York',
        ])->assertRedirect(route('business.edit'));

        $this->assertDatabaseHas('businesses', [
            'id' => $admin->business_id,
            'name' => 'Hudson Valley Rentals',
            'state' => 'New York',
        ]);
    }

    public function test_name_is_required_and_email_must_be_valid(): void
    {
        $this->actingAs($this->tenantUser());

        $this->put(route('business.update'), [
            'name' => '',
            'email' => 'not-an-email',
        ])->assertSessionHasErrors(['name', 'email']);
    }

    public function test_other_tenants_are_untouched(): void
    {
        $admin = $this->tenantUser();
        $other = Business::create([
            'name' => 'Other Rental Co',
            'phone' => '555-0111',
            'email' => 'info@other.test',
            'state' => 'Ohio',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actingAs($admin);
        $this->put(route('business.update'), [
            'name' => 'Renamed Co',
            'state' => 'Vermont',
        ]);

        $this->assertSame('Other Rental Co', $other->fresh()->name);
        $this->assertSame('Ohio', $other->fresh()->state);
    }

    public function test_a_state_that_predates_the_list_is_not_silently_blanked(): void
    {
        $admin = $this->tenantUser(businessAttributes: ['state' => 'Bavaria']);
        $this->actingAs($admin);

        $this->get(route('business.edit'))
            ->assertOk()
            ->assertSee('Bavaria');

        $this->put(route('business.update'), [
            'name' => 'Renamed Co',
            'state' => 'Bavaria',
        ])->assertRedirect(route('business.edit'))->assertSessionHasNoErrors();

        $this->assertSame('Bavaria', Business::find($admin->business_id)->state);

        // A new choice still has to come from the list.
        $this->put(route('business.update'), [
            'name' => 'Renamed Co',
            'state' => 'Atlantis',
        ])->assertSessionHasErrors('state');

        $this->assertSame('Bavaria', Business::find($admin->business_id)->state);
    }

    public function test_saved_state_flows_into_the_contract_governing_law(): void
    {
        $admin = $this->tenantUser();
        $this->actingAs($admin);

        $this->put(route('business.update'), [
            'name' => 'Test Rental Business',
            'address' => '1447 Monroe Street',
            'state' => 'Pennsylvania',
        ]);

        $template = ContractTemplate::effectiveFor($admin->business_id);
        $rendered = $template->render(['business_id' => $admin->business_id]);

        $this->assertStringContainsString('in the State of Pennsylvania.', $rendered['body']);
    }

    public function test_clerk_and_super_admin_cannot_edit_business_profile(): void
    {
        $this->actingAs($this->tenantUser('clerk'));
        $this->get(route('business.edit'))->assertForbidden();

        $root = new User(['name' => 'Root', 'email' => 'root@example.com', 'password' => 'password']);
        $root->role = 'super_admin';
        $root->email_verified_at = now();
        $root->save();

        $this->actingAs($root);
        $this->get(route('business.edit'))->assertForbidden();
    }
}
