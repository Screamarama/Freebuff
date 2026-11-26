<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\EquipmentModel;
use App\Models\FleetDisposal;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetDisposalTest extends TestCase
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
            'equipment_number' => 'FD-'.uniqid(),
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

    private function customer(Business $business): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'phone' => '555-0100',
            'account_type' => 'charge',
            'credit_limit' => '5000.00',
        ]);
    }

    public function test_selling_a_unit_records_the_gain_and_takes_it_out_of_the_fleet(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $unit = $this->unit($business, $this->model($business));

        // A year into a 60-month life: 30000 - (24000 / 60 * 12) = 25200.
        $response = $this->actingAs($user)->post(route('products.dispose.store', $unit), [
            'type' => 'sold',
            'disposed_on' => now()->toDateString(),
            'price' => '27000',
            'notes' => 'Sold to the dealer',
        ]);

        $response->assertRedirect(route('products.show', $unit));
        $response->assertSessionHas('success', fn (string $message) => str_contains($message, 'gain of $1,800.00'));

        $unit->refresh();
        $this->assertSame(Product::FLEET_DISPOSED, $unit->fleet_status);
        $this->assertFalse($unit->is_active);

        $disposal = FleetDisposal::where('product_id', $unit->id)->sole();
        $this->assertSame(FleetDisposal::SOLD, $disposal->type);
        $this->assertSame('25200.00', $disposal->book_value);
        $this->assertSame('27000.00', $disposal->price);
        $this->assertEquals(1800.0, $disposal->gain());

        $log = AuditLog::where('action', 'fleet.disposed')->sole();
        $this->assertSame($unit->id, $log->model_id);
        $this->assertEquals(1800.0, $log->details['gain']);
        $this->assertSame(now()->toDateString(), $disposal->disposed_on->toDateString());

        $this->actingAs($user)
            ->get(route('products.show', $unit))
            ->assertOk()
            ->assertSee('Disposed')
            ->assertSee('Sold')
            ->assertSee('Gain of')
            ->assertSee('$1,800.00');

        // Out of the live register, still in history with its figures.
        $this->actingAs($user)
            ->get(route('reports.fleet-value'))
            ->assertOk()
            ->assertDontSee($unit->equipment_number);

        $this->actingAs($user)
            ->get(route('reports.fleet-history'))
            ->assertOk()
            ->assertSee($unit->equipment_number)
            ->assertSee('Sold')
            ->assertSee('$27,000.00')
            ->assertSee('$25,200.00')
            ->assertSee('$1,800.00');
    }

    public function test_scrapping_a_unit_without_proceeds_records_the_loss(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $unit = $this->unit($business, $this->model($business));

        $this->actingAs($user)->post(route('products.dispose.store', $unit), [
            'type' => 'scrapped',
            'disposed_on' => now()->toDateString(),
            'notes' => 'Frame cracked',
        ])->assertSessionHas('success', fn (string $message) => str_contains($message, 'loss of $25,200.00'));

        $disposal = FleetDisposal::where('product_id', $unit->id)->sole();
        $this->assertSame('0.00', $disposal->price);
        $this->assertEquals(-25200.0, $disposal->gain());

        $this->actingAs($user)
            ->get(route('reports.fleet-history'))
            ->assertOk()
            ->assertSee('Scrapped')
            ->assertSee('-$25,200.00');
    }

    public function test_a_unit_without_a_book_value_is_still_recorded_without_a_gain(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $unit = $this->unit($business, $this->model($business), [
            'equipment_number' => 'FD-NOCOST',
            'cost_price' => null,
        ]);

        $this->actingAs($user)->post(route('products.dispose.store', $unit), [
            'type' => 'sold',
            'disposed_on' => now()->toDateString(),
            'price' => '5000',
        ])->assertSessionHas('success', fn (string $message) => str_contains($message, 'no gain or loss was recorded'));

        $disposal = FleetDisposal::where('product_id', $unit->id)->sole();
        $this->assertNull($disposal->book_value);
        $this->assertNull($disposal->gain());

        $this->actingAs($user)
            ->get(route('reports.fleet-history'))
            ->assertOk()
            ->assertSee('FD-NOCOST')
            ->assertSee('$5,000.00');
    }

    public function test_a_unit_in_hold_on_hire_or_already_disposed_cannot_be_disposed_again(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $model = $this->model($business);

        $held = $this->unit($business, $model, [
            'equipment_number' => 'FD-HOLD',
            'fleet_status' => Product::FLEET_SERVICE_HOLD,
            'is_active' => false,
        ]);

        $this->actingAs($user)->post(route('products.dispose.store', $held), [
            'type' => 'sold',
            'disposed_on' => now()->toDateString(),
            'price' => '1000',
        ])->assertSessionHasErrors('type');

        $this->assertSame(Product::FLEET_SERVICE_HOLD, $held->fresh()->fleet_status);

        $reserved = $this->unit($business, $model, ['equipment_number' => 'FD-RES']);

        Reservation::create([
            'business_id' => $business->id,
            'product_id' => $reserved->id,
            'customer_id' => $this->customer($business)->id,
            'created_by' => $user->id,
            'quantity' => 1,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(5),
            'status' => 'upcoming',
        ]);

        $this->actingAs($user)->post(route('products.dispose.store', $reserved), [
            'type' => 'scrapped',
            'disposed_on' => now()->toDateString(),
        ])->assertSessionHasErrors('type');

        $this->assertSame(Product::FLEET_ACTIVE, $reserved->fresh()->fleet_status);

        $sold = $this->unit($business, $model, ['equipment_number' => 'FD-SOLD']);
        $payload = ['type' => 'sold', 'disposed_on' => now()->toDateString(), 'price' => '1000'];

        $this->actingAs($user)->post(route('products.dispose.store', $sold), $payload)
            ->assertSessionHasNoErrors();

        $this->actingAs($user)->post(route('products.dispose.store', $sold), $payload)
            ->assertSessionHasErrors('type');

        $this->assertSame(1, FleetDisposal::where('product_id', $sold->id)->count());
    }

    public function test_disposal_is_limited_to_admins_and_the_owning_tenant(): void
    {
        $business = $this->business();
        $unit = $this->unit($business, $this->model($business));

        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');
        $this->actingAs($clerk)->get(route('products.dispose', $unit))->assertForbidden();

        $other = $this->business('Other Rentals');
        $intruder = $this->user($other, 'admin', 'intruder@other.test');
        $this->actingAs($intruder)->get(route('products.dispose', $unit))->assertNotFound();

        $this->assertSame(Product::FLEET_ACTIVE, $unit->fresh()->fleet_status);
        $this->assertSame(0, FleetDisposal::count());
    }

    public function test_a_sale_needs_a_price_but_a_scrap_does_not(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $unit = $this->unit($business, $this->model($business));

        $this->actingAs($user)->post(route('products.dispose.store', $unit), [
            'type' => 'sold',
            'disposed_on' => now()->toDateString(),
        ])->assertSessionHasErrors('price');

        $this->assertSame(Product::FLEET_ACTIVE, $unit->fresh()->fleet_status);
        $this->assertSame(0, FleetDisposal::count());

        $this->actingAs($user)->post(route('products.dispose.store', $unit), [
            'type' => 'scrapped',
            'disposed_on' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(Product::FLEET_DISPOSED, $unit->fresh()->fleet_status);
        $this->assertSame('0.00', FleetDisposal::where('product_id', $unit->id)->sole()->price);
    }

    public function test_the_disposal_date_cannot_be_in_the_future(): void
    {
        $business = $this->business();
        $user = $this->user($business);
        $unit = $this->unit($business, $this->model($business));

        $this->actingAs($user)->post(route('products.dispose.store', $unit), [
            'type' => 'sold',
            'disposed_on' => now()->addDay()->toDateString(),
            'price' => '1000',
        ])->assertSessionHasErrors('disposed_on');

        $this->assertSame(Product::FLEET_ACTIVE, $unit->fresh()->fleet_status);
        $this->assertSame(0, FleetDisposal::count());
    }
}
