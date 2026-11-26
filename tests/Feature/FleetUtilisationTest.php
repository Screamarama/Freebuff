<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\FleetMaintenance;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\RentalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetUtilisationTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = Business::create([
            'name' => 'Acme Rentals',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->user = new User(['name' => 'Ada Manager', 'email' => 'manager@acme.test', 'password' => 'password']);
        $this->user->role = 'manager';
        $this->user->business_id = $this->business->id;
        $this->user->save();
    }

    private function unit(string $number, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'Unit '.$number,
            'product_type' => 'rental',
            'fleet_status' => Product::FLEET_ACTIVE,
            'equipment_number' => $number,
            'cost_price' => 1000,
            'useful_life_months' => 60,
            'salvage_value' => 0,
            'depreciation_started_on' => now()->subDays(10)->toDateString(),
            'purchase_date' => now()->subDays(10)->toDateString(),
            'stock_quantity' => 1,
            'is_active' => true,
        ], $overrides));
    }

    public function test_each_unit_shows_its_hire_days_revenue_repairs_and_signal(): void
    {
        $unit = $this->unit('U-1');

        $customer = Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => 'C-1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
        ]);

        $contract = RentalContract::create([
            'business_id' => $this->business->id,
            'contract_number' => 'A-1',
            'customer_id' => $customer->id,
            'clerk_id' => $this->user->id,
            'start_date' => now()->subDays(6)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'actual_return_date' => now()->subDays(2)->toDateString(),
            'status' => 'returned',
            'subtotal' => 300,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total_amount' => 300,
        ]);

        RentalItem::create([
            'contract_id' => $contract->id,
            'product_id' => $unit->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 75,
            'duration' => 4,
            'subtotal' => 300,
        ]);

        FleetMaintenance::create([
            'business_id' => $this->business->id,
            'product_id' => $unit->id,
            'reason' => 'Hydraulic leak',
            'started_on' => now()->subDays(9)->toDateString(),
            'completed_on' => now()->subDays(8)->toDateString(),
            'cost' => 100,
        ]);

        // An open job adds downtime but its estimate is not spend.
        FleetMaintenance::create([
            'business_id' => $this->business->id,
            'product_id' => $unit->id,
            'reason' => 'Track tension',
            'started_on' => now()->subDay()->toDateString(),
            'cost' => 50,
        ]);

        $idle = $this->unit('U-IDLE', [
            'cost_price' => 500,
            'depreciation_started_on' => now()->subDays(60)->toDateString(),
            'purchase_date' => now()->subDays(60)->toDateString(),
        ]);

        $new = $this->unit('U-NEW', [
            'depreciation_started_on' => now()->subDays(5)->toDateString(),
            'purchase_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->get(route('reports.fleet-utilisation'))
            ->assertOk()
            ->assertSee('Fleet utilisation')
            ->assertSee('U-1')
            ->assertSee('U-IDLE')
            ->assertSee('U-NEW')
            ->assertSee('40%')
            ->assertSee('300.00')
            ->assertSee('100.00')
            ->assertSee('200.00')
            ->assertSee('Watch')
            ->assertSee('Low use')
            ->assertSee('Too new');
    }

    public function test_the_keep_sell_list_downloads_as_csv_with_the_same_figures(): void
    {
        $unit = $this->unit('U-1');

        $customer = Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => 'C-1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
        ]);

        $contract = RentalContract::create([
            'business_id' => $this->business->id,
            'contract_number' => 'A-1',
            'customer_id' => $customer->id,
            'clerk_id' => $this->user->id,
            'start_date' => now()->subDays(6)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'actual_return_date' => now()->subDays(2)->toDateString(),
            'status' => 'returned',
            'subtotal' => 300,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total_amount' => 300,
        ]);

        RentalItem::create([
            'contract_id' => $contract->id,
            'product_id' => $unit->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 75,
            'duration' => 4,
            'subtotal' => 300,
        ]);

        FleetMaintenance::create([
            'business_id' => $this->business->id,
            'product_id' => $unit->id,
            'reason' => 'Hydraulic leak',
            'started_on' => now()->subDays(9)->toDateString(),
            'completed_on' => now()->subDays(8)->toDateString(),
            'cost' => 100,
        ]);

        $response = $this->actingAs($this->user)->get(route('reports.fleet-utilisation.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('fleet-utilisation-'.now()->format('Y-m-d').'.csv', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('"Equipment number",Model,"In service","Days in service","Days on hire"', $csv);
        $this->assertStringContainsString('U-1,"Unit U-1",', $csv);
        $this->assertStringContainsString(',10,4,1,5,40,300.00,100.00,200.00,', $csv);
        $this->assertStringContainsString(',Watch,USD', $csv);
        $this->assertStringContainsString('"Fleet total","1 unit",,10,4,1,5,40,300.00,100.00,200.00', $csv);
    }

    public function test_the_unit_page_shows_the_same_figures_as_the_report(): void
    {
        $unit = $this->unit('U-1');

        $customer = Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => 'C-1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
        ]);

        $contract = RentalContract::create([
            'business_id' => $this->business->id,
            'contract_number' => 'A-1',
            'customer_id' => $customer->id,
            'clerk_id' => $this->user->id,
            'start_date' => now()->subDays(6)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'actual_return_date' => now()->subDays(2)->toDateString(),
            'status' => 'returned',
            'subtotal' => 300,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total_amount' => 300,
        ]);

        RentalItem::create([
            'contract_id' => $contract->id,
            'product_id' => $unit->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 75,
            'duration' => 4,
            'subtotal' => 300,
        ]);

        FleetMaintenance::create([
            'business_id' => $this->business->id,
            'product_id' => $unit->id,
            'reason' => 'Hydraulic leak',
            'started_on' => now()->subDays(9)->toDateString(),
            'completed_on' => now()->subDays(8)->toDateString(),
            'cost' => 150,
        ]);

        $this->actingAs($this->user)
            ->get(route('products.show', $unit))
            ->assertOk()
            ->assertSee('Utilisation &amp; return on asset', false)
            ->assertSee('40%')
            ->assertSee('300.00')
            ->assertSee('150.00')
            ->assertSee('150.00')
            ->assertSee('Watch')
            ->assertSee('Fleet report');

        $sale = Product::create([
            'business_id' => $this->business->id,
            'name' => 'Sale item',
            'product_type' => 'sale',
            'sku' => 'S-1',
            'sale_price' => 50,
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->get(route('products.show', $sale))
            ->assertOk()
            ->assertDontSee('Utilisation &amp; return on asset', false);
    }

    public function test_another_tenants_units_are_not_shown(): void
    {
        $other = Business::create(['name' => 'Other Rentals', 'currency' => 'USD', 'is_active' => true]);

        Product::create([
            'business_id' => $other->id,
            'name' => 'Other unit',
            'product_type' => 'rental',
            'fleet_status' => Product::FLEET_ACTIVE,
            'equipment_number' => 'OTHER-1',
            'cost_price' => 1000,
            'useful_life_months' => 60,
            'depreciation_started_on' => now()->subDays(40)->toDateString(),
            'stock_quantity' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->get(route('reports.fleet-utilisation'))
            ->assertOk()
            ->assertDontSee('OTHER-1');
    }
}
