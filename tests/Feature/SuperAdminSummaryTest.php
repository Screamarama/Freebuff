<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $admin = new User(['name' => 'Root', 'email' => 'root@example.com', 'password' => 'password']);
        $admin->role = 'super_admin';
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    private function tenantAdmin(): User
    {
        $tenant = Business::create(['name' => 'Acme Rentals', 'currency' => 'USD', 'is_active' => true]);
        $admin = new User(['name' => 'Acme Admin', 'email' => 'admin@acme.test', 'password' => 'password']);
        $admin->role = 'admin';
        $admin->business_id = $tenant->id;
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    public function test_super_admin_is_redirected_from_record_lists_to_tenants_summary(): void
    {
        $this->actingAs($this->superAdmin());

        foreach (['/products', '/customers', '/reservations', '/rental-agreements'] as $url) {
            $this->get($url)->assertRedirect(route('tenants.index'));
        }
    }

    public function test_super_admin_gets_summary_dashboard(): void
    {
        $this->tenantAdmin();
        $this->actingAs($this->superAdmin());

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Reservations by status')
            ->assertSee('Agreements by status')
            ->assertSee('Newest tenants')
            ->assertSee('Acme Rentals');
    }

    public function test_tenants_summary_shows_per_tenant_counts(): void
    {
        $this->tenantAdmin();

        $this->actingAs($this->superAdmin());

        $this->get(route('tenants.index'))
            ->assertOk()
            ->assertSee('Acme Rentals')
            ->assertSee('Summary across tenants');
    }

    public function test_tenant_admin_keeps_record_access_and_dashboard(): void
    {
        $this->actingAs($this->tenantAdmin());

        $this->get('/dashboard')->assertOk();
        $this->get('/products')->assertOk();
    }
}
