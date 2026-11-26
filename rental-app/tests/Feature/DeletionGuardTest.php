<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\RentalItem;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionGuardTest extends TestCase
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

    private function product(): Product
    {
        return Product::create([
            'business_id' => $this->business->id,
            'name' => 'Mini Excavator',
            'product_type' => 'rental',
            'is_active' => true,
            'stock_quantity' => 1,
            'rental_price_day' => 60.00,
        ]);
    }

    private function customer(string $code = 'C-1'): Customer
    {
        return Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => $code,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
            'account_type' => 'charge',
            'credit_limit' => 1000,
        ]);
    }

    private function reservation(Product $product, Customer $customer): Reservation
    {
        return Reservation::create([
            'business_id' => $this->business->id,
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
            'quantity' => 1,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(4),
            'status' => 'upcoming',
            'total_amount' => 60,
            'billing_unit' => 'day',
        ]);
    }

    /** @return array{0: RentalContract, 1: RentalItem} */
    private function agreement(Product $product, Customer $customer, array $overrides = []): array
    {
        $contract = RentalContract::create(array_merge([
            'business_id' => $this->business->id,
            'contract_number' => 'A-'.uniqid(),
            'customer_id' => $customer->id,
            'clerk_id' => $this->user->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active',
            'subtotal' => 60,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total_amount' => 60,
        ], $overrides));

        $item = RentalItem::create([
            'contract_id' => $contract->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 60,
            'duration' => 1,
            'subtotal' => 60,
        ]);

        return [$contract, $item];
    }

    public function test_a_unit_held_by_an_upcoming_reservation_cannot_be_deleted(): void
    {
        $product = $this->product();
        $reservation = $this->reservation($product, $this->customer());

        $this->actingAs($this->user)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee('Delete this product?')
            ->assertSee('held by an upcoming reservation');

        $this->actingAs($this->user)
            ->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('error');

        $this->assertTrue($product->fresh()->is_active);

        $reservation->update(['status' => 'cancelled']);

        $this->actingAs($this->user)
            ->delete(route('products.destroy', $product))
            ->assertSessionHasNoErrors();

        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_a_unit_on_an_open_agreement_cannot_be_deleted_until_the_agreement_closes(): void
    {
        $product = $this->product();
        [$contract] = $this->agreement($product, $this->customer());

        $this->actingAs($this->user)
            ->delete(route('products.destroy', $product))
            ->assertSessionHas('error');

        $this->assertTrue($product->fresh()->is_active);

        $contract->update(['status' => 'cancelled']);

        $this->actingAs($this->user)
            ->delete(route('products.destroy', $product))
            ->assertSessionHasNoErrors();

        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_a_customer_with_an_upcoming_reservation_cannot_be_deleted(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        $reservation = $this->reservation($product, $customer);

        $this->actingAs($this->user)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertDontSee('Delete this customer?')
            ->assertSee('upcoming reservation');

        $this->actingAs($this->user)
            ->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('error');

        $this->assertTrue($customer->fresh()->is_active);

        $reservation->update(['status' => 'cancelled']);

        $this->actingAs($this->user)
            ->delete(route('customers.destroy', $customer))
            ->assertSessionHasNoErrors();

        $this->assertFalse($customer->fresh()->is_active);
    }

    public function test_a_customer_with_an_open_agreement_cannot_be_deleted(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        [$contract] = $this->agreement($product, $customer);

        $this->actingAs($this->user)
            ->delete(route('customers.destroy', $customer))
            ->assertSessionHas('error');

        $this->assertTrue($customer->fresh()->is_active);

        $contract->update(['status' => 'cancelled']);

        $this->actingAs($this->user)
            ->delete(route('customers.destroy', $customer))
            ->assertSessionHasNoErrors();

        $this->assertFalse($customer->fresh()->is_active);
    }

    public function test_a_customer_owing_on_account_cannot_be_deleted(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        [$contract] = $this->agreement($product, $customer, [
            'status' => 'returned',
            'actual_return_date' => now()->toDateString(),
            'charged_to_account_at' => now(),
            'charged_amount' => 100,
            'total_amount' => 100,
        ]);

        $this->actingAs($this->user)
            ->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHas('error');

        $this->assertStringContainsString('charge account', session('error'));
        $this->assertTrue($customer->fresh()->is_active);

        $contract->update(['charge_reversed_at' => now()]);

        $this->actingAs($this->user)
            ->delete(route('customers.destroy', $customer))
            ->assertSessionHasNoErrors();

        $this->assertFalse($customer->fresh()->is_active);
    }
}
