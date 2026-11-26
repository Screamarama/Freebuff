<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\EquipmentModel;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetValueTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $name = 'Acme Rentals'): Business
    {
        return Business::create([
            'name' => $name,
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    private function user(Business $business, string $role = 'manager', string $email = 'manager@acme.test'): User
    {
        $user = new User(['name' => 'Mia Manager', 'email' => $email, 'password' => 'password']);
        $user->role = $role;
        $user->business_id = $business->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function model(Business $business, array $overrides = []): EquipmentModel
    {
        return EquipmentModel::create([
            'business_id' => $business->id,
            'name' => 'Excavator',
            'manufacturer' => 'Caterpillar',
            'model_number' => '320 GC',
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function unit(Business $business, ?EquipmentModel $model, array $overrides = []): Product
    {
        return Product::create([
            'business_id' => $business->id,
            'equipment_model_id' => $model?->id,
            'product_type' => 'rental',
            'fleet_status' => Product::FLEET_ACTIVE,
            'equipment_number' => 'FL-'.uniqid(),
            'name' => $model?->name ?? 'Loose unit',
            'cost_price' => 30000,
            'useful_life_months' => 60,
            'salvage_value' => 6000,
            'purchase_date' => now()->toDateString(),
            'depreciation_started_on' => now()->toDateString(),
            'stock_quantity' => 1,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_the_register_totals_the_fleet_and_breaks_it_down_by_model(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $excavator = $this->model($business, ['name' => 'Excavator']);
        $loader = $this->model($business, ['name' => 'Wheel loader', 'model_number' => '930M']);

        // 30000 - (24000 / 60 * 12) = 25200 after a year.
        $this->unit($business, $excavator, [
            'equipment_number' => 'FL-1',
            'depreciation_started_on' => now()->subMonths(12)->toDateString(),
        ]);

        // Past its 12-month life: it sits at salvage.
        $this->unit($business, $excavator, [
            'equipment_number' => 'FL-2',
            'cost_price' => 10000,
            'useful_life_months' => 12,
            'salvage_value' => 1000,
            'depreciation_started_on' => now()->subMonths(24)->toDateString(),
        ]);

        // Bought today: still at cost.
        $this->unit($business, $loader, [
            'equipment_number' => 'FL-3',
            'cost_price' => 24000,
            'useful_life_months' => 24,
            'salvage_value' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('reports.fleet-value'))
            ->assertOk()
            ->assertSee('Fleet value')
            ->assertSee('$64,000.00')
            ->assertSee('$50,200.00')
            ->assertSee('$13,800.00')
            ->assertSee('$2,150.00')
            ->assertSee('Caterpillar 320 GC')
            ->assertSee('Caterpillar 930M')
            ->assertSee('FL-2')
            ->assertSee('Fully depreciated')
            ->assertSee('3 units');
    }

    public function test_units_missing_cost_life_or_start_are_named_and_left_out_of_the_value(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $model = $this->model($business);

        $this->unit($business, $model, ['equipment_number' => 'FL-NOCOST', 'cost_price' => null]);
        $this->unit($business, $model, ['equipment_number' => 'FL-NOLIFE', 'useful_life_months' => 0]);
        $this->unit($business, $model, [
            'equipment_number' => 'FL-NOSTART',
            'purchase_date' => null,
            'depreciation_started_on' => null,
        ]);

        $this->actingAs($user)
            ->get(route('reports.fleet-value'))
            ->assertOk()
            ->assertSee('No purchase cost')
            ->assertSee('No useful life')
            ->assertSee('No depreciation start')
            ->assertSee('Excludes 3 units that cannot be valued yet.')
            ->assertSee('3 needing attention');
    }

    public function test_the_export_lists_units_and_model_totals_and_leaves_other_tenants_and_hold_out(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $model = $this->model($business);

        $this->unit($business, $model, ['equipment_number' => 'FL-1']);

        // Waiting in service hold is not fleet yet.
        $this->unit($business, $model, [
            'equipment_number' => 'FL-HOLD',
            'fleet_status' => Product::FLEET_SERVICE_HOLD,
            'is_active' => false,
        ]);

        $other = $this->business('Other Rentals');
        $this->unit($other, $this->model($other), ['equipment_number' => 'OTHER-1']);

        $response = $this->actingAs($user)->get(route('reports.fleet-value.export'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Equipment number', $csv);
        $this->assertStringContainsString('FL-1', $csv);
        $this->assertStringContainsString('"Catalogue model",Units,Cost,"Book value"', $csv);
        $this->assertStringContainsString('"Fleet total",1,30000.00,30000.00', $csv);
        $this->assertStringNotContainsString('FL-HOLD', $csv);
        $this->assertStringNotContainsString('OTHER-1', $csv);
    }

    public function test_a_clerk_can_read_the_register(): void
    {
        $business = $this->business();
        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');

        $this->actingAs($clerk)
            ->get(route('reports.fleet-value'))
            ->assertOk()
            ->assertSee('No rental units in the fleet yet.');
    }
}
