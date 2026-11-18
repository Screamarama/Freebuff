<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function tenantAdmin(string $role = 'admin'): User
    {
        $tenant = Business::create(['name' => 'Acme Rentals', 'currency' => 'USD', 'is_active' => true]);
        $admin = new User(['name' => 'Acme Admin', 'email' => $role . '@acme.test', 'password' => 'password']);
        $admin->role = $role;
        $admin->business_id = $tenant->id;
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    public function test_tenant_admin_can_open_branding_settings_with_defaults(): void
    {
        $this->actingAs($this->tenantAdmin());

        $this->get(route('theme.edit'))
            ->assertOk()
            ->assertSee('#1f2937')
            ->assertSee('Live preview');
    }

    public function test_tenant_admin_can_save_brand_colors(): void
    {
        $admin = $this->tenantAdmin();
        $this->actingAs($admin);

        $this->put(route('theme.update'), [
            'primary_color' => '#0f766e',
            'secondary_color' => '#f0fdfa',
            'accent_color' => '#f59e0b',
            'text_color' => '#134e4a',
            'background_color' => '#ecfdf5',
        ])->assertRedirect(route('theme.edit'));

        $this->assertDatabaseHas('themes', [
            'business_id' => $admin->business_id,
            'primary_color' => '#0f766e',
            'accent_color' => '#f59e0b',
        ]);
    }

    public function test_brand_colors_must_be_hex(): void
    {
        $this->actingAs($this->tenantAdmin());

        $this->put(route('theme.update'), [
            'primary_color' => 'not-a-color',
            'secondary_color' => '#ffffff',
            'accent_color' => '#4f46e5',
            'text_color' => '#111827',
            'background_color' => '#f3f4f6',
        ])->assertSessionHasErrors('primary_color');
    }

    public function test_clerk_and_super_admin_cannot_access_branding(): void
    {
        $clerk = $this->tenantAdmin('clerk');
        $this->actingAs($clerk);
        $this->get(route('theme.edit'))->assertForbidden();

        $root = new User(['name' => 'Root', 'email' => 'root@example.com', 'password' => 'password']);
        $root->role = 'super_admin';
        $root->email_verified_at = now();
        $root->save();

        $this->actingAs($root);
        $this->get(route('theme.edit'))->assertForbidden();
    }

    public function test_saved_theme_colors_render_in_layout(): void
    {
        $admin = $this->tenantAdmin();
        Theme::updateOrCreate(['business_id' => $admin->business_id], [
            ...Theme::DEFAULTS,
            'primary_color' => '#0f766e',
        ]);

        $this->actingAs($admin);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('--theme-primary: #0f766e', false);
    }
}
