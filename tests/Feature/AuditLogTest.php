<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name = 'Acme Rentals'): Business
    {
        return Business::create([
            'name' => $name,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function user(string $role, ?Business $tenant = null, string $email = 'user@example.test'): User
    {
        $user = new User(['name' => 'Dana Clerk', 'email' => $email, 'password' => 'password']);
        $user->role = $role;
        $user->business_id = $tenant?->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    public function test_signing_in_is_recorded(): void
    {
        $tenant = $this->tenant();
        $clerk = $this->user('clerk', $tenant, 'dana@acme.test');

        $this->post('/login', [
            'email' => 'dana@acme.test',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $log = AuditLog::where('action', 'auth.login')->firstOrFail();

        $this->assertSame($clerk->id, $log->user_id);
        $this->assertSame($tenant->id, $log->business_id);
        $this->assertSame('dana@acme.test', $log->details['actor_email']);
        $this->assertSame('Dana Clerk', $log->details['actor_name']);
    }

    public function test_a_failed_sign_in_is_not_recorded(): void
    {
        $this->user('clerk', $this->tenant(), 'dana@acme.test');

        $this->post('/login', [
            'email' => 'dana@acme.test',
            'password' => 'wrong-password',
        ]);

        $this->assertSame(0, AuditLog::where('action', 'auth.login')->count());
    }

    public function test_changing_your_own_password_is_recorded(): void
    {
        $user = $this->user('clerk', $this->tenant(), 'dana@acme.test');

        $this->actingAs($user)->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'password.changed')->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('dana@acme.test', $log->actorEmail());
        $this->assertSame($user->business_id, $log->business_id);
    }

    public function test_a_password_reset_by_email_names_the_account_that_reset_it(): void
    {
        $user = $this->user('clerk', $this->tenant(), 'dana@acme.test');

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'dana@acme.test',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect(route('login'));

        $log = AuditLog::where('action', 'password.reset')->firstOrFail();

        // Nobody is signed in during a reset, so the account is the actor.
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('Dana Clerk', $log->actorName());
        $this->assertSame('dana@acme.test', $log->actorEmail());
    }

    public function test_an_admin_password_reset_is_recorded_against_the_tenant(): void
    {
        $tenant = $this->tenant();
        $superAdmin = $this->user('super_admin', null, 'super@example.test');
        $clerk = $this->user('clerk', $tenant, 'dana@acme.test');

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.password', [$tenant, $clerk]), [
                'password' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'tenant.user.password_reset')->firstOrFail();

        $this->assertSame($superAdmin->id, $log->user_id);
        $this->assertSame($tenant->id, $log->business_id);
        $this->assertSame('super@example.test', $log->actorEmail());
        // The entry names whose password it was, not just who changed it.
        $this->assertSame('Dana Clerk · dana@acme.test', $log->subjectLabel());
    }

    public function test_activating_and_deactivating_a_login_is_recorded(): void
    {
        $tenant = $this->tenant();
        $superAdmin = $this->user('super_admin', null, 'super@example.test');
        $clerk = $this->user('clerk', $tenant, 'dana@acme.test');

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.toggle', [$tenant, $clerk]));

        $this->assertFalse($clerk->fresh()->is_active);

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.toggle', [$tenant, $clerk]));

        $this->assertTrue($clerk->fresh()->is_active);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $superAdmin->id,
            'business_id' => $tenant->id,
            'action' => 'tenant.user.deactivated',
            'model_type' => User::class,
            'model_id' => $clerk->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.user.activated',
            'model_id' => $clerk->id,
        ]);
    }

    public function test_the_audit_page_shows_sign_ins_and_account_changes(): void
    {
        $tenant = $this->tenant();
        $superAdmin = $this->user('super_admin', null, 'super@example.test');
        $clerk = $this->user('clerk', $tenant, 'dana@acme.test');

        $this->post('/login', ['email' => 'dana@acme.test', 'password' => 'password']);

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.toggle', [$tenant, $clerk]));

        $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs'))
            ->assertOk()
            ->assertSee('Access &amp; account activity', false)
            ->assertSee('Signed in')
            ->assertSee('Login deactivated')
            ->assertSee('dana@acme.test');
    }

    public function test_a_cancellation_reason_is_shown_in_the_audit_log(): void
    {
        $tenant = $this->tenant();
        $superAdmin = $this->user('super_admin', null, 'super@example.test');
        $clerk = $this->user('clerk', $tenant, 'dana@acme.test');

        AuditLog::record('agreement.cancelled', [
            'name' => 'RA-20261117-0001',
            'reason' => 'Customer changed plans',
        ], $clerk, $tenant->id);

        $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs', ['tenant' => $tenant->id]))
            ->assertOk()
            ->assertSee('Agreement cancelled')
            ->assertSee('Reason: Customer changed plans');
    }

    public function test_the_log_can_be_filtered_by_tenant(): void
    {
        $acme = $this->tenant('Acme Rentals');
        $beta = $this->tenant('Beta Rentals');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        AuditLog::record('tenant.suspended', ['name' => 'Acme Rentals'], $superAdmin, $acme->id, $acme);
        AuditLog::record('tenant.suspended', ['name' => 'Beta Rentals'], $superAdmin, $beta->id, $beta);

        ImpersonationLog::create([
            'impersonator_id' => $superAdmin->id,
            'impersonated_id' => $superAdmin->id,
            'business_id' => $acme->id,
            'started_at' => now(),
        ]);

        $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs', ['tenant' => $acme->id]))
            ->assertOk()
            // Both tables narrow to the chosen tenant.
            ->assertViewHas('accessLogs', fn ($logs) => $logs->total() === 1 && $logs->first()->business_id === $acme->id)
            ->assertViewHas('logs', fn ($logs) => $logs->total() === 1)
            ->assertSee('Acme Rentals');
    }

    public function test_the_activity_table_can_be_filtered_by_action_type(): void
    {
        $tenant = $this->tenant();
        $superAdmin = $this->user('super_admin', null, 'super@example.test');
        $clerk = $this->user('clerk', $tenant, 'dana@acme.test');

        // Sign in first: the guest-only login route ignores a signed-in session.
        $this->post('/login', ['email' => 'dana@acme.test', 'password' => 'password']);

        $this->actingAs($superAdmin)
            ->patch(route('tenants.users.toggle', [$tenant, $clerk]));

        $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs', ['action' => 'auth.login']))
            ->assertOk()
            // The action dropdown lists every label, so assert on the rows.
            ->assertViewHas('accessLogs', fn ($logs) => $logs->total() === 1
                && $logs->first()->action === 'auth.login');
    }

    public function test_the_log_can_be_filtered_by_date_range(): void
    {
        $tenant = $this->tenant();
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        // created_at defaults to the database clock, so backdate the rows directly.
        $old = AuditLog::record('tenant.suspended', ['name' => $tenant->name], $superAdmin, $tenant->id, $tenant);
        DB::table('audit_logs')->where('id', $old->id)->update(['created_at' => now()->subDays(30)]);

        $recent = AuditLog::record('tenant.activated', ['name' => $tenant->name], $superAdmin, $tenant->id, $tenant);
        DB::table('audit_logs')->where('id', $recent->id)->update(['created_at' => now()->subDay()]);

        $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs', [
                'from' => now()->subDays(7)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertViewHas('accessLogs', fn ($logs) => $logs->total() === 1
                && $logs->first()->action === 'tenant.activated');
    }

    public function test_the_filters_survive_paging(): void
    {
        $tenant = $this->tenant('Acme Rentals');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        for ($i = 0; $i < 25; $i++) {
            AuditLog::record('auth.login', [], $superAdmin, $tenant->id);
        }

        $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs', ['tenant' => $tenant->id]))
            ->assertOk()
            ->assertViewHas('accessLogs', fn ($logs) => $logs->total() === 25
                && str_contains($logs->url(2), 'tenant='.$tenant->id));
    }

    public function test_the_csv_export_holds_every_matching_row_and_nothing_else(): void
    {
        $acme = $this->tenant('Acme Rentals');
        $beta = $this->tenant('Beta Rentals');
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        // More rows than one page holds: the export must not stop at 20.
        for ($i = 0; $i < 25; $i++) {
            AuditLog::record('auth.login', ['name' => 'Acme Rentals'], $superAdmin, $acme->id);
        }

        AuditLog::record('tenant.suspended', ['name' => 'Beta Rentals'], $superAdmin, $beta->id, $beta);

        ImpersonationLog::create([
            'impersonator_id' => $superAdmin->id,
            'impersonated_id' => $superAdmin->id,
            'business_id' => $acme->id,
            'started_at' => now(),
        ]);

        $csv = $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs.export', ['tenant' => $acme->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", $csv)));

        // Header, 25 sign-ins and the impersonation.
        $this->assertCount(27, $lines);
        $this->assertStringContainsString('Section,When,Actor', $lines[0]);
        $this->assertStringContainsString('Impersonation', $lines[1]);
        $this->assertStringNotContainsString('Beta Rentals', $csv);
    }

    public function test_the_csv_export_follows_the_action_and_date_filters(): void
    {
        $tenant = $this->tenant();
        $superAdmin = $this->user('super_admin', null, 'super@example.test');

        $old = AuditLog::record('tenant.suspended', ['name' => $tenant->name], $superAdmin, $tenant->id, $tenant);
        DB::table('audit_logs')->where('id', $old->id)->update(['created_at' => now()->subDays(30)]);

        AuditLog::record('tenant.activated', ['name' => $tenant->name], $superAdmin, $tenant->id, $tenant);

        $csv = $this->actingAs($superAdmin)
            ->get(route('tenants.audit-logs.export', [
                'action' => 'tenant.activated',
                'from' => now()->subDays(7)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Tenant activated', $csv);
        $this->assertStringNotContainsString('Tenant suspended', $csv);
    }

    public function test_only_super_admins_can_download_the_audit_log(): void
    {
        $clerk = $this->user('clerk', $this->tenant(), 'dana@acme.test');

        $this->actingAs($clerk)
            ->get(route('tenants.audit-logs.export'))
            ->assertForbidden();
    }

    public function test_a_sign_in_keeps_the_actor_after_the_account_is_deleted(): void
    {
        $tenant = $this->tenant();
        $clerk = $this->user('clerk', $tenant, 'dana@acme.test');

        $this->post('/login', ['email' => 'dana@acme.test', 'password' => 'password']);

        $clerk->delete();

        $log = AuditLog::where('action', 'auth.login')->firstOrFail();

        $this->assertNull($log->user_id);
        $this->assertSame('Acme Rentals', $log->business?->name);
        $this->assertSame('Dana Clerk', $log->actorName());
        $this->assertSame('dana@acme.test', $log->actorEmail());
    }
}
