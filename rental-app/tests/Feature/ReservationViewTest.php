<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both reservation forms hand a product map to inline JavaScript, and a bad
 * Blade expression there is a parse error at render time. Loading the page is
 * what catches it; nothing else in the suite compiles these two views.
 */
class ReservationViewTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $business = Business::create([
            'name' => 'Acme Rentals',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $clerk = new User(['name' => 'Dana Clerk', 'email' => 'clerk@acme.test', 'password' => 'password']);
        $clerk->role = 'clerk';
        $clerk->business_id = $business->id;
        $clerk->save();

        return $clerk;
    }

    private function product(User $user): Product
    {
        return Product::create([
            'business_id' => $user->business_id,
            'name' => 'Mini Excavator',
            'product_type' => 'rental',
            'is_active' => true,
            'stock_quantity' => 2,
            'rental_price_hour' => 12.50,
            'rental_price_day' => 60.00,
        ]);
    }

    private function reservation(Product $product): Reservation
    {
        $customer = Customer::create([
            'business_id' => $product->business_id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
        ]);

        return Reservation::create([
            'business_id' => $product->business_id,
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'quantity' => 1,
            'starts_at' => '2026-12-01 10:00:00',
            'ends_at' => '2026-12-01 13:00:00',
            'status' => 'upcoming',
        ]);
    }

    public function test_the_create_page_renders(): void
    {
        $user = $this->user();
        $this->product($user);

        $this->actingAs($user)
            ->get(route('reservations.create'))
            ->assertOk()
            ->assertSee('New reservation')
            ->assertSee('Mini Excavator');
    }

    public function test_converting_warns_when_the_customers_exemption_has_expired(): void
    {
        $user = $this->user();
        $reservation = $this->reservation($this->product($user));

        $reservation->customer->update([
            'tax_exemption_number' => 'EX-4471',
            'tax_exempt_copy_on_file' => true,
            'tax_exemption_expires_on' => '2026-01-31',
        ]);

        $this->actingAs($user)
            ->get(route('reservations.show', $reservation))
            ->assertOk()
            ->assertSee('exemption certificate expired on')
            ->assertSee('The agreement will be taxed. Convert anyway?');

        // A current certificate converts with no prompt.
        $reservation->customer->update(['tax_exemption_expires_on' => '2027-01-31']);

        $this->actingAs($user)
            ->get(route('reservations.show', $reservation))
            ->assertOk()
            ->assertDontSee('exemption certificate expired on')
            ->assertDontSee('Convert anyway?');
    }

    public function test_the_edit_page_renders(): void
    {
        $user = $this->user();
        $reservation = $this->reservation($this->product($user));

        $this->actingAs($user)
            ->get(route('reservations.edit', $reservation))
            ->assertOk()
            ->assertSee('Edit reservation')
            ->assertSee('Mini Excavator');
    }
}
