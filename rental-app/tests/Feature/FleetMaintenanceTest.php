<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\EquipmentModel;
use App\Models\FleetMaintenance;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetMaintenanceTest extends TestCase
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
            'equipment_number' => 'FM-'.uniqid(),
            'name' => 'Excavator',
            'cost_price' => 30000,
            'useful_life_months' => 60,
            'salvage_value' => 6000,
            'purchase_date' => now()->subMonths(12)->toDateString(),
            'depreciation_started_on' => now()->subMonths(12)->toDateString(),
            'stock_quantity' => 1,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    public function test_sending_a_unit_away_takes_it_out_of_availability_but_not_the_books(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $unit = $this->unit($business, $this->model($business));

        $this->actingAs($user)
            ->get(route('maintenance.create', $unit))
            ->assertOk()
            ->assertSee('Send '.$unit->equipment_number.' for maintenance');

        $response = $this->actingAs($user)->post(route('maintenance.store', $unit), [
            'reason' => 'Hydraulic leak',
            'vendor' => 'Acme Hydraulics',
            'started_on' => now()->toDateString(),
            'expected_return_on' => now()->addDays(5)->toDateString(),
            'cost' => '850',
            'notes' => 'Hose ordered',
        ]);

        $response->assertRedirect(route('maintenance.index'));
        $response->assertSessionHas('success', fn (string $message) => str_contains($message, 'Acme Hydraulics')
            && str_contains($message, 'not bookable'));

        $unit->refresh();
        $this->assertSame(Product::FLEET_MAINTENANCE, $unit->fleet_status);
        $this->assertFalse($unit->is_active);

        $job = FleetMaintenance::where('product_id', $unit->id)->sole();
        $this->assertSame('Hydraulic leak', $job->reason);
        $this->assertSame('Acme Hydraulics', $job->vendor);
        $this->assertSame('850.00', $job->cost);
        $this->assertSame('Hose ordered', $job->notes);
        $this->assertTrue($job->isOpen());
        $this->assertSame(1, $job->daysOut());

        $this->assertSame(1, AuditLog::where('action', 'maintenance.started')->count());

        $this->actingAs($user)
            ->get(route('products.show', $unit))
            ->assertOk()
            ->assertSee('In maintenance')
            ->assertSee('Hydraulic leak')
            ->assertSee('Acme Hydraulics')
            ->assertSee('Away since');

        // Out of the product list while away...
        $this->actingAs($user)
            ->get(route('products.index'))
            ->assertOk()
            ->assertDontSee($unit->equipment_number);

        // ...but still an owned asset in the fleet value register. The page
        // groups by model, so the unit shows up under its catalogue entry and
        // in the CSV, not as a row of its own.
        $this->actingAs($user)
            ->get(route('reports.fleet-value'))
            ->assertOk()
            ->assertSee('Caterpillar 320 GC')
            ->assertSee('$30,000.00');

        $this->assertStringContainsString(
            $unit->equipment_number,
            $this->actingAs($user)->get(route('reports.fleet-value.export'))->streamedContent()
        );

        $this->actingAs($user)
            ->get(route('maintenance.index'))
            ->assertOk()
            ->assertSee('Hydraulic leak')
            ->assertSee('Acme Hydraulics')
            ->assertSee('Away now');
    }

    public function test_notes_can_be_updated_while_away_and_the_unit_comes_back_on_completion(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $unit = $this->unit($business, $this->model($business));

        $this->actingAs($user)->post(route('maintenance.store', $unit), [
            'reason' => 'Hydraulic leak',
            'vendor' => 'Acme Hydraulics',
            'started_on' => now()->subDays(4)->toDateString(),
            'expected_return_on' => now()->addDay()->toDateString(),
            'cost' => '850',
        ])->assertSessionHasNoErrors();

        $job = FleetMaintenance::where('product_id', $unit->id)->sole();

        $this->actingAs($user)
            ->patch(route('maintenance.notes', $job), ['notes' => 'Hose replaced, waiting on the pressure test'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Maintenance notes updated.');

        $this->assertSame('Hose replaced, waiting on the pressure test', $job->fresh()->notes);

        $this->actingAs($user)->post(route('maintenance.complete', $job), [
            'completed_on' => now()->toDateString(),
            'cost' => '975',
            'notes' => 'Pressure test passed',
        ])
            ->assertRedirect(route('maintenance.index'))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'back in service after 5 days')
                && str_contains($message, 'maintenance cost $975.00'));

        $unit->refresh();
        $this->assertSame(Product::FLEET_ACTIVE, $unit->fleet_status);
        $this->assertTrue($unit->is_active);

        $job->refresh();
        $this->assertFalse($job->isOpen());
        $this->assertSame(now()->toDateString(), $job->completed_on->toDateString());
        $this->assertSame('975.00', $job->cost);
        $this->assertSame('Pressure test passed', $job->notes);
        $this->assertSame(5, $job->daysOut());

        $log = AuditLog::where('action', 'maintenance.completed')->sole();
        $this->assertSame(5, $log->details['days_out']);

        $this->actingAs($user)
            ->get(route('maintenance.index'))
            ->assertOk()
            ->assertSee('Completed jobs')
            ->assertSee('Hydraulic leak')
            ->assertSee('975.00')
            ->assertSee('5 days');
    }

    public function test_maintenance_is_limited_to_live_units_and_admin_or_manager(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $model = $this->model($business);

        $held = $this->unit($business, $model, [
            'equipment_number' => 'FM-HOLD',
            'fleet_status' => Product::FLEET_SERVICE_HOLD,
            'is_active' => false,
        ]);

        $this->actingAs($user)->post(route('maintenance.store', $held), [
            'reason' => 'Repair',
            'started_on' => now()->toDateString(),
        ])->assertSessionHasErrors('reason');

        $inactive = $this->unit($business, $model, [
            'equipment_number' => 'FM-INACTIVE',
            'is_active' => false,
        ]);

        $this->actingAs($user)->post(route('maintenance.store', $inactive), [
            'reason' => 'Repair',
            'started_on' => now()->toDateString(),
        ])->assertSessionHasErrors('reason');

        $live = $this->unit($business, $model, ['equipment_number' => 'FM-LIVE']);

        $this->actingAs($user)->post(route('maintenance.store', $live), [
            'reason' => 'Repair',
            'started_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $job = FleetMaintenance::where('product_id', $live->id)->sole();

        $this->actingAs($user)->post(route('maintenance.store', $live), [
            'reason' => 'Again',
            'started_on' => now()->toDateString(),
        ])->assertSessionHasErrors('reason');

        $this->assertSame(1, FleetMaintenance::where('product_id', $live->id)->count());

        // A return date before the start date is rejected.
        $other = $this->unit($business, $model, ['equipment_number' => 'FM-DATE']);
        $this->actingAs($user)->post(route('maintenance.store', $other), [
            'reason' => 'Repair',
            'started_on' => now()->toDateString(),
            'expected_return_on' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('expected_return_on');

        // And the job cannot be completed before it started.
        $this->actingAs($user)->post(route('maintenance.complete', $job), [
            'completed_on' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('completed_on');

        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');
        $this->actingAs($clerk)->get(route('maintenance.create', $other))->assertForbidden();

        $elsewhere = $this->business('Other Rentals');
        $intruder = $this->user($elsewhere, 'admin', 'intruder@other.test');
        $this->actingAs($intruder)->get(route('maintenance.create', $other))->assertNotFound();
        $this->actingAs($intruder)->patch(route('maintenance.notes', $job), ['notes' => 'Hijack'])->assertNotFound();
    }
}
