<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantUserTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name = 'Test Rental Business'): Business
    {
        return Business::create([
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

    private function createSessionFor(User $user): void
    {
        DB::table('sessions')->insert([
            'id' => 'live-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }

    public function test_super_admin_can_create_a_staff_login_for_a_tenant(): void
    {
        $tenant = $this->tenant();
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->post(route('tenants.users.store', $tenant), [
                'name' => 'Dana Clerk',
                'email' => 'dana@tenant.test',
                'role' => 'clerk',
                'password' => 'secret-password',
            ])
            ->assertRedirect(route('tenants.index'))
            ->assertSessionHas('success');

        $staff = User::where('email', 'dana@tenant.test')->firstOrFail();

        $this->assertSame('Dana Clerk', $staff->name);
        $this->assertSame('clerk', $staff->role);
        $this->assertSame($tenant->id, $staff->business_id);
        $this->assertNotNull($staff->email_verified_at);
        $this->assertTrue(Hash::check('secret-password', $staff->password));

        // The credentials handed over actually work for signing in.
        $this->assertTrue(
            Auth::attempt(['email' => 'dana@tenant.test', 'password' => 'secret-password'])
        );
    }

    public function test_the_new_login_belongs_to_the_tenant_it_was_created_for(): void
    {
        $tenant = $this->tenant('First Tenant');
        $other = $this->tenant('Other Tenant');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)->post(route('tenants.users.store', $tenant), [
            'name' => 'Morgan Manager',
            'email' => 'morgan@tenant.test',
            'role' => 'manager',
            'password' => 'secret-password',
        ]);

        $staff = User::where('email', 'morgan@tenant.test')->firstOrFail();

        $this->assertSame($tenant->id, $staff->business_id);
        $this->assertNotSame($other->id, $staff->business_id);
    }

    public function test_the_add_login_page_shows_the_tenant_name(): void
    {
        $tenant = $this->tenant('Acme Rentals');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->get(route('tenants.users.create', $tenant))
            ->assertOk()
            ->assertSee('Add a login')
            ->assertSee('Acme Rentals')
            ->assertSee('Create login');
    }

    public function test_tenant_staff_cannot_create_logins(): void
    {
        $tenant = $this->tenant();
        $tenantAdmin = $this->user('admin', $tenant);

        $this->actingAs($tenantAdmin)
            ->get(route('tenants.users.create', $tenant))
            ->assertForbidden();

        $this->actingAs($tenantAdmin)
            ->post(route('tenants.users.store', $tenant), [
                'name' => 'Sneaky Clerk',
                'email' => 'sneaky@tenant.test',
                'role' => 'clerk',
                'password' => 'secret-password',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@tenant.test']);
    }

    public function test_the_tenants_page_lists_each_tenants_logins(): void
    {
        $tenant = $this->tenant('Acme Rentals');
        $this->user('admin', $tenant, 'owner@acme.test');
        $this->user('clerk', $tenant, 'desk@acme.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->get(route('tenants.index'))
            ->assertOk()
            ->assertSee('Logins for Acme Rentals')
            ->assertSee('owner@acme.test')
            ->assertSee('desk@acme.test')
            ->assertSee('Clerk');
    }

    public function test_super_admin_can_reset_a_staff_password(): void
    {
        $tenant = $this->tenant();
        $staff = $this->user('clerk', $tenant, 'dana@tenant.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.password', [$tenant, $staff]), ['password' => 'brand-new-password'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $staff->refresh();

        $this->assertTrue(Hash::check('brand-new-password', $staff->password));
        $this->assertFalse(Hash::check('password', $staff->password));
        $this->assertTrue(Auth::attempt(['email' => 'dana@tenant.test', 'password' => 'brand-new-password']));
    }

    public function test_a_password_reset_ends_the_users_existing_sessions(): void
    {
        config(['session.driver' => 'database']);

        $tenant = $this->tenant();
        $staff = $this->user('clerk', $tenant, 'dana@tenant.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->createSessionFor($staff);

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.password', [$tenant, $staff]), ['password' => 'brand-new-password']);

        $this->assertDatabaseMissing('sessions', ['id' => 'live-session']);
    }

    public function test_deactivating_a_login_ends_its_existing_sessions(): void
    {
        config(['session.driver' => 'database']);

        $tenant = $this->tenant();
        $staff = $this->user('clerk', $tenant, 'dana@tenant.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->createSessionFor($staff);

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.toggle', [$tenant, $staff]));

        $this->assertDatabaseMissing('sessions', ['id' => 'live-session']);
    }

    public function test_short_reset_passwords_are_rejected(): void
    {
        $tenant = $this->tenant();
        $staff = $this->user('clerk', $tenant, 'dana@tenant.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.password', [$tenant, $staff]), ['password' => 'short'])
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $staff->refresh()->password));
    }

    public function test_a_login_cannot_be_managed_through_another_tenant(): void
    {
        $tenant = $this->tenant('First Tenant');
        $other = $this->tenant('Other Tenant');
        $staff = $this->user('clerk', $other, 'other@tenant.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.password', [$tenant, $staff]), ['password' => 'brand-new-password'])
            ->assertNotFound();

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.toggle', [$tenant, $staff]))
            ->assertNotFound();

        $this->assertTrue($staff->refresh()->is_active);
    }

    public function test_a_deactivated_login_cannot_sign_in_and_can_be_reactivated(): void
    {
        $tenant = $this->tenant();
        $staff = $this->user('clerk', $tenant, 'dana@tenant.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.toggle', [$tenant, $staff]))
            ->assertSessionHas('success');

        $this->assertFalse($staff->refresh()->is_active);

        // Sign the super admin out first: /login only runs for guests.
        $this->post('/logout');

        $this->post('/login', ['email' => 'dana@tenant.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.toggle', [$tenant, $staff]))
            ->assertSessionHas('success');

        $this->assertTrue($staff->refresh()->is_active);

        $this->post('/logout');

        $this->post('/login', ['email' => 'dana@tenant.test', 'password' => 'password']);
        $this->assertAuthenticatedAs($staff);
    }

    public function test_tenant_staff_cannot_reset_or_deactivate_logins(): void
    {
        $tenant = $this->tenant();
        $tenantAdmin = $this->user('admin', $tenant, 'boss@tenant.test');
        $colleague = $this->user('clerk', $tenant, 'desk@tenant.test');

        $this->actingAs($tenantAdmin)
            ->patch(route('tenants.users.password', [$tenant, $colleague]), ['password' => 'brand-new-password'])
            ->assertForbidden();

        $this->actingAs($tenantAdmin)
            ->patch(route('tenants.users.toggle', [$tenant, $colleague]))
            ->assertForbidden();

        $this->assertTrue(Hash::check('password', $colleague->refresh()->password));
        $this->assertTrue($colleague->is_active);
    }

    public function test_duplicate_and_invalid_details_are_rejected(): void
    {
        $tenant = $this->tenant();
        $superAdmin = $this->user('super_admin', null, 'super@example.test');
        $this->user('clerk', $tenant, 'taken@tenant.test');

        $this->actingAs($superAdmin)
            ->post(route('tenants.users.store', $tenant), [
                'name' => 'Duplicate',
                'email' => 'taken@tenant.test',
                'role' => 'clerk',
                'password' => 'secret-password',
            ])
            ->assertSessionHasErrors('email');

        $this->actingAs($superAdmin)
            ->post(route('tenants.users.store', $tenant), [
                'name' => 'Too Powerful',
                'email' => 'boss@tenant.test',
                'role' => 'super_admin',
                'password' => 'secret-password',
            ])
            ->assertSessionHasErrors('role');

        $this->actingAs($superAdmin)
            ->post(route('tenants.users.store', $tenant), [
                'name' => 'Short Password',
                'email' => 'short@tenant.test',
                'role' => 'clerk',
                'password' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 2);
    }
}
