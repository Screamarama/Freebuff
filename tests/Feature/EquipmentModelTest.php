<?php

namespace Tests\Feature;

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

    public function test_the_navigation_links_to_the_catalogue(): void
    {
        $this->actingAs($this->tenantUser());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('equipment-models.index'), false);
    }
}
