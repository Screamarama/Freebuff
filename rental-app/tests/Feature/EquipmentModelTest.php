<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\EquipmentModel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentModelTest extends TestCase
{
    use RefreshDatabase;

    private function tenantUser(string $email = 'admin@tenant.test'): User
    {
        $tenant = Business::create([
            'name' => 'Test Rental Business',
            'phone' => '555-0100',
            'email' => 'info@testrental.test',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $admin = new User(['name' => 'Tenant Admin', 'email' => $email, 'password' => 'password']);
        $admin->role = 'admin';
        $admin->business_id = $tenant->id;
        $admin->email_verified_at = now();
        $admin->save();

        return $admin;
    }

    private function model(User $user, array $overrides = []): EquipmentModel
    {
        return EquipmentModel::create([
            'business_id' => $user->business_id,
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'rental_price_day' => 450,
            'useful_life_months' => 60,
            'salvage_value' => 5000,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function unit(User $user, EquipmentModel $model, array $overrides = []): Product
    {
        return Product::create([
            'business_id' => $user->business_id,
            'equipment_model_id' => $model->id,
            'product_type' => 'rental',
            'fleet_status' => Product::FLEET_ACTIVE,
            'equipment_number' => 'EQ-'.uniqid(),
            'name' => $model->name,
            'cost_price' => 24000,
            'stock_quantity' => 1,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function clerk(User $admin): User
    {
        $clerk = new User(['name' => 'Clerk', 'email' => 'clerk@tenant.test', 'password' => 'password']);
        $clerk->role = 'clerk';
        $clerk->business_id = $admin->business_id;
        $clerk->email_verified_at = now();
        $clerk->save();

        return $clerk;
    }

    public function test_models_page_lists_the_catalogue_with_unit_counts(): void
    {
        $user = $this->tenantUser();
        $model = $this->model($user);

        Product::create([
            'business_id' => $user->business_id,
            'equipment_model_id' => $model->id,
            'product_type' => 'rental',
            'equipment_number' => 'EQ-100',
            'name' => $model->name,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('equipment-models.index'))
            ->assertOk()
            ->assertSee('Excavator')
            ->assertSee('Caterpillar')
            ->assertSee('320 GC')
            ->assertSee('1');
    }

    public function test_a_model_can_be_created_and_validated(): void
    {
        $user = $this->tenantUser();
        $this->actingAs($user);

        $this->post(route('equipment-models.store'), [
            'name' => 'Boom Lift',
            'manufacturer' => 'JLG',
            'model_number' => '450AJ',
            'rental_price_day' => 320,
            'useful_life_months' => 48,
            'salvage_value' => 3000,
        ])->assertRedirect(route('equipment-models.index'));

        $model = EquipmentModel::where('name', 'Boom Lift')->firstOrFail();
        $this->assertSame($user->business_id, $model->business_id);
        $this->assertSame(48, $model->useful_life_months);
        $this->assertTrue($model->is_active);
        $this->assertSame('JLG 450AJ', $model->label());

        $this->post(route('equipment-models.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->post(route('equipment-models.store'), [
            'name' => 'Overpriced salvage',
            'default_cost_price' => 1000,
            'salvage_value' => 1500,
        ])->assertSessionHasErrors('salvage_value');

        $this->post(route('equipment-models.store'), [
            'name' => 'Bad life',
            'useful_life_months' => 0,
        ])->assertSessionHasErrors('useful_life_months');
    }

    public function test_a_model_can_be_edited_and_changes_do_not_retrofit_units(): void
    {
        $user = $this->tenantUser();
        $model = $this->model($user, ['rental_price_day' => 450]);

        $unit = Product::create([
            'business_id' => $user->business_id,
            'equipment_model_id' => $model->id,
            'product_type' => 'rental',
            'equipment_number' => 'EQ-100',
            'name' => 'Excavator',
            'rental_price_day' => 450,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('equipment-models.update', $model), [
                'name' => 'Excavator',
                'rental_price_day' => 520,
                'useful_life_months' => 60,
                'is_active' => '1',
            ])
            ->assertRedirect(route('equipment-models.index'));

        $this->assertSame('520.00', $model->fresh()->rental_price_day);
        // The unit keeps the price it was hired at until it is saved again.
        $this->assertSame('450.00', $unit->fresh()->rental_price_day);
    }

    public function test_a_tenant_cannot_touch_another_tenants_model(): void
    {
        $owner = $this->tenantUser();
        $model = $this->model($owner);

        $intruder = $this->tenantUser('intruder@tenant.test');

        $this->actingAs($intruder)->get(route('equipment-models.edit', $model))->assertNotFound();
        $this->actingAs($intruder)->put(route('equipment-models.update', $model), ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAs($intruder)->delete(route('equipment-models.destroy', $model))->assertNotFound();

        $this->assertSame('Excavator', $model->fresh()->name);

        $this->actingAs($intruder)
            ->get(route('equipment-models.index'))
            ->assertOk()
            ->assertDontSee('Excavator');
    }

    public function test_retiring_a_model_keeps_its_units_and_hides_it_from_new_orders(): void
    {
        $user = $this->tenantUser();
        $model = $this->model($user);

        $unit = Product::create([
            'business_id' => $user->business_id,
            'equipment_model_id' => $model->id,
            'product_type' => 'rental',
            'equipment_number' => 'EQ-100',
            'name' => 'Excavator',
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->delete(route('equipment-models.destroy', $model))
            ->assertRedirect(route('equipment-models.index'));

        $this->assertFalse($model->fresh()->is_active);
        $this->assertSame($model->id, $unit->fresh()->equipment_model_id);

        // Retired models are not offered when ordering or adding a unit.
        $this->actingAs($user)
            ->get(route('purchase-orders.create', ['type' => 'rental']))
            ->assertOk()
            ->assertDontSee('Excavator');

        $this->actingAs($user)
            ->get(route('products.create', ['type' => 'rental']))
            ->assertOk()
            ->assertSee('No equipment models yet');

        // But the unit that already uses it can still be edited.
        $this->actingAs($user)
            ->get(route('products.edit', $unit))
            ->assertOk()
            ->assertSee('(retired)');
    }

    public function test_the_model_page_counts_units_whose_terms_differ(): void
    {
        $user = $this->tenantUser();
        $model = $this->model($user);

        $this->unit($user, $model, [
            'equipment_number' => 'EQ-1',
            'rental_price_day' => 450,
            'useful_life_months' => 60,
            'salvage_value' => 5000,
        ]);
        $this->unit($user, $model, ['equipment_number' => 'EQ-2', 'rental_price_day' => 400]);
        $this->unit($user, $model, ['equipment_number' => 'EQ-3', 'salvage_value' => 4000]);

        $this->actingAs($user)
            ->get(route('equipment-models.edit', $model))
            ->assertOk()
            ->assertSee('2 of 3 use different terms')
            ->assertSee('Terms differ')
            ->assertSee('Apply these terms to 2 units');
    }

    public function test_the_models_terms_can_be_applied_to_the_units_that_differ(): void
    {
        $user = $this->tenantUser();
        $model = $this->model($user, [
            'rental_price_day' => 520,
            'useful_life_months' => 72,
            'salvage_value' => 9000,
        ]);

        $current = $this->unit($user, $model, [
            'equipment_number' => 'EQ-1',
            'rental_price_day' => 520,
            'useful_life_months' => 72,
            'salvage_value' => 9000,
            'used_sale_price' => 14000,
        ]);

        $stale = $this->unit($user, $model, [
            'equipment_number' => 'EQ-2',
            'rental_price_day' => 450,
            'useful_life_months' => 60,
            'salvage_value' => 5000,
            'used_sale_price' => 12000,
            'serial_number' => 'SN-2',
        ]);

        $held = $this->unit($user, $model, [
            'equipment_number' => 'EQ-3',
            'fleet_status' => Product::FLEET_SERVICE_HOLD,
            'is_active' => false,
            'rental_price_day' => 450,
        ]);

        $this->actingAs($user)
            ->post(route('equipment-models.apply-defaults', $model))
            ->assertRedirect()
            ->assertSessionHas('success', "1 unit now uses this model's terms.");

        $stale->refresh();
        $this->assertSame('520.00', $stale->rental_price_day);
        $this->assertSame(72, $stale->useful_life_months);
        $this->assertSame('9000.00', $stale->salvage_value);

        // Unit-specific values are not catalogue terms and stay as they were.
        $this->assertSame('12000.00', $stale->used_sale_price);
        $this->assertSame('SN-2', $stale->serial_number);
        $this->assertSame('14000.00', $current->fresh()->used_sale_price);

        // Held units get their terms when they are committed, not here.
        $this->assertSame('450.00', $held->fresh()->rental_price_day);

        $log = AuditLog::where('action', 'equipment_model.defaults_applied')->sole();
        $this->assertSame($model->id, $log->model_id);
        $this->assertSame(['EQ-2'], $log->details['units']);
        $this->assertSame('520.00', $log->details['rental_price_day']);
        $this->assertSame(72, $log->details['useful_life_months']);
    }

    public function test_only_admins_and_the_owning_tenant_can_apply_terms(): void
    {
        $owner = $this->tenantUser();
        $model = $this->model($owner);
        $unit = $this->unit($owner, $model, ['equipment_number' => 'EQ-1', 'rental_price_day' => 400]);

        $this->actingAs($this->clerk($owner))
            ->post(route('equipment-models.apply-defaults', $model))
            ->assertForbidden();

        $intruder = $this->tenantUser('intruder@tenant.test');
        $this->actingAs($intruder)
            ->post(route('equipment-models.apply-defaults', $model))
            ->assertNotFound();

        $this->assertSame('400.00', $unit->fresh()->rental_price_day);
        $this->assertSame(0, AuditLog::where('action', 'equipment_model.defaults_applied')->count());
    }

    public function test_the_navigation_links_to_the_catalogue(): void
    {
        $this->actingAs($this->tenantUser());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('equipment-models.index'), false);
    }
}
