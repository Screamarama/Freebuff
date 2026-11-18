<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name, bool $active = true): Business
    {
        return Business::create([
            'name' => $name,
            'currency' => 'USD',
            'is_active' => $active,
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

    public function test_users_of_a_deactivated_tenant_cannot_sign_in(): void
    {
        $tenant = $this->tenant('Acme Rentals');
        $this->user('clerk', $tenant, 'desk@acme.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->patch(route('tenants.toggle', $tenant))
            ->assertSessionHas('success');

        $this->assertFalse($tenant->refresh()->is_active);

        // Sign the super admin out: /login only handles guests.
        $this->post('/logout');

        $this->post('/login', ['email' => 'desk@acme.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_users_can_sign_in_again_once_the_tenant_is_reactivated(): void
    {
        $tenant = $this->tenant('Acme Rentals', active: false);
        $clerk = $this->user('clerk', $tenant, 'desk@acme.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->post('/login', ['email' => 'desk@acme.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->actingAs($superAdmin)
            ->patch(route('tenants.toggle', $tenant))
            ->assertSessionHas('success');

        $this->assertTrue($tenant->refresh()->is_active);

        $this->post('/logout');

        $this->post('/login', ['email' => 'desk@acme.test', 'password' => 'password']);
        $this->assertAuthenticatedAs($clerk);
    }

    public function test_the_super_admin_is_not_locked_out_by_their_own_tenant_being_inactive(): void
    {
        $tenant = $this->tenant('Platform', active: false);
        $superAdmin = $this->user('super_admin', $tenant, 'super@example.test');

        $this->post('/login', ['email' => 'super@example.test', 'password' => 'password']);

        $this->assertAuthenticatedAs($superAdmin);
    }

    public function test_users_without_a_tenant_are_unaffected_by_tenant_status(): void
    {
        $tenantless = $this->user('admin', null, 'legacy@example.test');

        $this->post('/login', ['email' => 'legacy@example.test', 'password' => 'password']);

        $this->assertAuthenticatedAs($tenantless);
    }

    public function test_logins_cannot_be_added_to_a_suspended_tenant(): void
    {
        $tenant = $this->tenant('Acme Rentals', active: false);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->get(route('tenants.users.create', $tenant))
            ->assertRedirect(route('tenants.index'))
            ->assertSessionHas('error');

        $this->actingAs($superAdmin)
            ->post(route('tenants.users.store', $tenant), [
                'name' => 'Dana Clerk',
                'email' => 'dana@tenant.test',
                'role' => 'clerk',
                'password' => 'secret-password',
            ])
            ->assertRedirect(route('tenants.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('users', ['email' => 'dana@tenant.test']);
    }

    public function test_the_tenants_page_disables_add_user_for_a_suspended_tenant(): void
    {
        $tenant = $this->tenant('Acme Rentals', active: false);
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)
            ->get(route('tenants.index'))
            ->assertOk()
            ->assertDontSee(route('tenants.users.create', $tenant), false)
            ->assertSee('Activate this tenant to add logins', false)
            ->assertSee('Suspended tenants cannot sign in');

        // An active tenant keeps the working link.
        $active = $this->tenant('Live Rentals');

        $this->actingAs($superAdmin)
            ->get(route('tenants.index'))
            ->assertSee(route('tenants.users.create', $active), false);
    }

    public function test_suspending_and_reactivating_a_tenant_are_written_to_the_audit_log(): void
    {
        $tenant = $this->tenant('Acme Rentals');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)->patch(route('tenants.toggle', $tenant));
        $this->actingAs($superAdmin)->patch(route('tenants.toggle', $tenant));

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $superAdmin->id,
            'business_id' => $tenant->id,
            'action' => 'tenant.suspended',
            'model_type' => Business::class,
            'model_id' => $tenant->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $superAdmin->id,
            'business_id' => $tenant->id,
            'action' => 'tenant.activated',
        ]);

        $this->assertSame(2, DB::table('audit_logs')->where('business_id', $tenant->id)->count());
    }

    public function test_the_audit_log_page_shows_who_suspended_a_tenant(): void
    {
        $tenant = $this->tenant('Acme Rentals');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)->patch(route('tenants.toggle', $tenant));

        $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs'))
            ->assertOk()
            ->assertSee('Access & account activity')
            ->assertSee('Tenant suspended')
            ->assertSee('Acme Rentals')
            ->assertSee('super@example.test');
    }

    public function test_audit_history_survives_deleting_the_tenant(): void
    {
        $tenant = $this->tenant('Acme Rentals');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $this->actingAs($superAdmin)->patch(route('tenants.toggle', $tenant));

        $tenant->delete();

        $log = AuditLog::firstOrFail();

        $this->assertNull($log->business_id);
        $this->assertSame('tenant.suspended', $log->action);
        $this->assertSame('Acme Rentals', $log->details['name']);

        // The history still reads correctly without the tenant row.
        $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs'))
            ->assertOk()
            ->assertSee('Acme Rentals')
            ->assertSee('Tenant suspended');
    }

    public function test_audit_entries_keep_the_actor_after_that_user_is_deleted(): void
    {
        $tenant = $this->tenant('Acme Rentals');
        $actor = $this->user('super_admin', null, 'super@example.test');
        $actor->name = 'Platform Owner';
        $actor->save();

        $this->actingAs($actor)->patch(route('tenants.toggle', $tenant));

        $observer = $this->user('super_admin', null, 'observer@example.test');

        $actor->delete();

        $log = AuditLog::firstOrFail();

        $this->assertNull($log->user_id);
        $this->assertSame('Platform Owner', $log->actorName());
        $this->assertSame('super@example.test', $log->actorEmail());

        $this->actingAs($observer)
            ->get(route('tenants.audit-logs'))
            ->assertOk()
            ->assertSee('Platform Owner')
            ->assertSee('super@example.test')
            ->assertSee('account deleted');
    }

    public function test_deactivating_a_tenant_ends_its_users_sessions_only(): void
    {
        config(['session.driver' => 'database']);

        $tenant = $this->tenant('Acme Rentals');
        $clerk = $this->user('clerk', $tenant, 'desk@acme.test');
        $other = $this->tenant('Other Rentals');
        $otherClerk = $this->user('clerk', $other, 'desk@other.test');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        foreach ([[$clerk, 'acme-session'], [$otherClerk, 'other-session']] as [$user, $id]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        $this->actingAs($superAdmin)
            ->patch(route('tenants.toggle', $tenant))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('sessions', ['id' => 'acme-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
    }
}
