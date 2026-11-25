<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\EquipmentModel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceHoldTest extends TestCase
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

    private function heldUnit(User $user, array $overrides = []): Product
    {
        return Product::create([
            'business_id' => $user->business_id,
            'product_type' => 'rental',
            'fleet_status' => Product::FLEET_SERVICE_HOLD,
            'is_active' => false,
            'equipment_number' => 'PO-R-0001-1',
            'name' => 'CAT 320 GC excavator',
            'cost_price' => 24000,
            'purchase_order' => 'PO-R-0001',
            'purchase_date' => '2026-11-25',
            'stock_quantity' => 1,
            ...$overrides,
        ]);
    }

    private function commitPayload(array $overrides = []): array
    {
        return [
            'equipment_number' => 'EQ-100',
            'serial_number' => 'CAT0320GC12345',
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'category' => 'Earthmoving',
            'rental_price_hour' => 75,
            'rental_price_day' => 450,
            'rental_price_week' => 2400,
            'rental_price_month' => 8000,
            'deposit_amount' => 500,
            'used_sale_price' => 18000,
            'useful_life_months' => 48,
            'salvage_value' => 4800,
            'depreciation_started_on' => '2026-11-25',
            ...$overrides,
        ];
    }

    public function test_a_held_unit_is_listed_but_not_in_the_fleet(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->get(route('service-hold.index'))
            ->assertOk()
            ->assertSee('CAT 320 GC excavator')
            ->assertSee('PO-R-0001');

        $this->actingAs($user)
            ->get('/products')
            ->assertOk()
            ->assertDontSee('CAT 320 GC excavator');

        $this->actingAs($user)
            ->get(route('service-hold.setup', $unit))
            ->assertOk()
            ->assertSee('Commit to rental fleet');
    }

    public function test_committing_a_unit_creates_its_catalogue_entry_and_activates_it(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload())
            ->assertRedirect(route('service-hold.index'))
            ->assertSessionHasNoErrors();

        $unit->refresh();
        $model = EquipmentModel::where('business_id', $user->business_id)->firstOrFail();

        $this->assertSame(Product::FLEET_ACTIVE, $unit->fleet_status);
        $this->assertTrue($unit->is_active);
        $this->assertSame($model->id, $unit->equipment_model_id);
        $this->assertSame('EQ-100', $unit->equipment_number);
        $this->assertSame('Caterpillar', $unit->manufacturer);
        $this->assertSame('450.00', $unit->rental_price_day);
        $this->assertSame('18000.00', $unit->used_sale_price);
        $this->assertSame(48, $unit->useful_life_months);
        $this->assertSame('4800.00', $unit->salvage_value);

        // The unit now depreciates: (24000 - 4800) / 48 per month.
        $this->assertSame(400.0, $unit->monthlyDepreciation());
        $this->assertSame(21600.0, $unit->bookValue(Carbon::parse('2027-05-25')));

        // The catalogue entry carries the terms entered at commit time.
        $this->assertSame('Excavator', $model->name);
        $this->assertSame('Caterpillar', $model->manufacturer);
        $this->assertSame('450.00', $model->rental_price_day);
        $this->assertSame(48, $model->useful_life_months);
        $this->assertSame('4800.00', $model->salvage_value);
        $this->assertSame('24000.00', $model->default_cost_price);
    }

    public function test_units_with_the_same_spec_share_one_catalogue_model(): void
    {
        $user = $this->tenantUser();
        $first = $this->heldUnit($user);
        $second = $this->heldUnit($user, ['equipment_number' => 'PO-R-0001-2']);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $first), $this->commitPayload())
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('service-hold.commit', $second), $this->commitPayload([
                'equipment_number' => 'EQ-101',
                'serial_number' => 'CAT0320GC99999',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, EquipmentModel::query()->count());
        $this->assertSame(
            $first->fresh()->equipment_model_id,
            $second->fresh()->equipment_model_id
        );
        $this->assertSame(2, EquipmentModel::firstOrFail()->products()->count());
    }

    public function test_setup_names_the_existing_model_the_unit_would_join_and_hides_ignored_rates(): void
    {
        $user = $this->tenantUser();

        EquipmentModel::create([
            'business_id' => $user->business_id,
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'rental_price_day' => 450,
            'useful_life_months' => 60,
            'salvage_value' => 5000,
            'is_active' => true,
        ]);

        $unit = $this->heldUnit($user, [
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
        ]);

        $this->actingAs($user)
            ->get(route('service-hold.setup', $unit))
            ->assertOk()
            ->assertSee('Joins existing model')
            ->assertSee('Caterpillar 320 GC')
            ->assertSee('$450.00/day')
            ->assertSee('its catalogue rates and depreciation terms apply')
            // The rate inputs are hidden and disabled: they would be ignored.
            ->assertSee('id="model-default-fields" class="sm:col-span-2 hidden"', false);
    }

    public function test_setup_asks_for_rates_when_no_catalogue_model_matches(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->get(route('service-hold.setup', $unit))
            ->assertOk()
            ->assertSee('No catalogue model matches yet')
            ->assertDontSeeText('Joins existing model')
            ->assertDontSee('sm:col-span-2 hidden')
            ->assertSee('Catalogue rates and depreciation');
    }

    public function test_committing_does_not_overwrite_an_existing_models_defaults(): void
    {
        $user = $this->tenantUser();

        $existing = EquipmentModel::create([
            'business_id' => $user->business_id,
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'rental_price_day' => 450,
            'useful_life_months' => 60,
            'salvage_value' => 5000,
            'default_cost_price' => 20000,
            'is_active' => true,
        ]);

        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload([
                'rental_price_day' => 999,
                'useful_life_months' => 24,
                'salvage_value' => 1000,
            ]))
            ->assertSessionHasNoErrors();

        // The catalogue is left exactly as it was, cost included.
        $existing->refresh();
        $this->assertSame('450.00', $existing->rental_price_day);
        $this->assertSame(60, $existing->useful_life_months);
        $this->assertSame('5000.00', $existing->salvage_value);
        $this->assertSame('20000.00', $existing->default_cost_price);

        // The unit adopts those terms rather than the typed ones.
        $unit->refresh();
        $this->assertSame($existing->id, $unit->equipment_model_id);
        $this->assertSame('450.00', $unit->rental_price_day);
        $this->assertSame(60, $unit->useful_life_months);
        $this->assertSame('5000.00', $unit->salvage_value);

        // Ticking the box is the only way the model changes.
        $second = $this->heldUnit($user, ['equipment_number' => 'PO-R-0001-2']);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $second), $this->commitPayload([
                'equipment_number' => 'EQ-101',
                'rental_price_day' => 999,
                'useful_life_months' => 24,
                'salvage_value' => 1000,
                'update_model_defaults' => 1,
            ]))
            ->assertSessionHasNoErrors();

        $existing->refresh();
        $this->assertSame('999.00', $existing->rental_price_day);
        $this->assertSame(24, $existing->useful_life_months);
        $this->assertSame('1000.00', $existing->salvage_value);
        $this->assertSame('999.00', $second->fresh()->rental_price_day);
    }

    public function test_committing_requires_an_equipment_number_that_is_free(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        Product::create([
            'business_id' => $user->business_id,
            'product_type' => 'rental',
            'equipment_number' => 'EQ-100',
            'name' => 'Existing unit',
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload())
            ->assertSessionHasErrors('equipment_number');

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame(Product::FLEET_SERVICE_HOLD, $unit->fresh()->fleet_status);
    }

    public function test_salvage_cannot_exceed_the_purchase_cost_and_life_must_be_positive(): void
    {
        $user = $this->tenantUser();
        $unit = $this->heldUnit($user);

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload(['salvage_value' => 30000]))
            ->assertSessionHasErrors('salvage_value');

        $this->actingAs($user)
            ->post(route('service-hold.commit', $unit), $this->commitPayload(['useful_life_months' => 0]))
            ->assertSessionHasErrors('useful_life_months');

        $this->assertSame(Product::FLEET_SERVICE_HOLD, $unit->fresh()->fleet_status);
        $this->assertSame(0, EquipmentModel::query()->count());
    }

    public function test_a_tenant_cannot_commit_another_tenants_held_unit(): void
    {
        $owner = $this->tenantUser();
        $unit = $this->heldUnit($owner);

        $intruder = $this->tenantUser('intruder@tenant.test');

        $this->actingAs($intruder)->get(route('service-hold.setup', $unit))->assertNotFound();
        $this->actingAs($intruder)
            ->post(route('service-hold.commit', $unit), $this->commitPayload())
            ->assertNotFound();

        $this->actingAs($intruder)
            ->get(route('service-hold.index'))
            ->assertOk()
            ->assertDontSee('CAT 320 GC excavator');
    }

    public function test_an_active_unit_cannot_be_recommitted_from_hold(): void
    {
        $user = $this->tenantUser();

        $unit = Product::create([
            'business_id' => $user->business_id,
            'product_type' => 'rental',
            'equipment_number' => 'EQ-200',
            'name' => 'In service',
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('service-hold.setup', $unit))->assertNotFound();
    }
}
