<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function business(string $name = 'Acme Rentals'): Business
    {
        return Business::create(['name' => $name, 'currency' => 'USD', 'is_active' => true]);
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

    private function customer(Business $business, string $first = 'Jane'): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => $first,
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'charge',
            'credit_limit' => '5000.00',
        ]);
    }

    private function contract(Business $business, Customer $customer, array $attributes = []): RentalContract
    {
        return RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-16',
            'end_date' => '2026-11-18',
            'status' => 'active',
            'subtotal' => 100.00,
            'total_amount' => 100.00,
            ...$attributes,
        ]);
    }

    private function product(Business $business, string $name, int $stock, int $available, string $type = 'rental'): Product
    {
        return Product::create([
            'business_id' => $business->id,
            'name' => $name,
            'product_type' => $type,
            'stock_quantity' => $stock,
            'available_quantity' => $available,
            'is_active' => true,
        ]);
    }

    public function test_a_manager_sees_the_days_work_and_what_needs_attention(): void
    {
        $this->travelTo('2026-11-16 09:00:00');

        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business, 'Pat');

        $pickup = $this->contract($business, $customer, ['contract_number' => 'RA-PICKUP']);
        $return = $this->contract($business, $customer, [
            'contract_number' => 'RA-RETURN',
            'start_date' => '2026-11-12',
            'end_date' => '2026-11-16',
        ]);
        $overdue = $this->contract($business, $customer, [
            'contract_number' => 'RA-LATE',
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-12',
        ]);
        $this->contract($business, $customer, [
            'contract_number' => 'RA-RETURNED',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'actual_return_date' => '2026-11-05',
            'status' => 'returned',
        ]);

        // Money owed on the charge account.
        $this->contract($business, $customer, [
            'contract_number' => 'RA-UNPAID',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-04',
            'total_amount' => 100.00,
            'charged_to_account_at' => '2026-11-05 10:00:00',
            'charged_amount' => 100.00,
            'due_on' => '2026-11-10',
        ]);

        $widget = $this->product($business, 'Widget', 1, 0);
        // Sale stock is what the low-stock card reports; rental units on rent
        // are covered by the pickups and returns cards instead.
        $this->product($business, 'Hard Hat', 10, 0, 'sale');
        $this->product($business, 'Spare Drill', 5, 4, 'sale');

        $reservation = Reservation::create([
            'business_id' => $business->id,
            'product_id' => $widget->id,
            'customer_id' => $customer->id,
            'created_by' => $manager->id,
            'quantity' => 1,
            'starts_at' => '2026-11-16 09:00:00',
            'ends_at' => '2026-11-20 17:00:00',
            'status' => 'upcoming',
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertOk()
            // Today's pickups and returns, with links to the agreements.
            ->assertSee('pickups')
            ->assertSee($pickup->contract_number)
            ->assertSee($return->contract_number)
            // Overdue equipment says how late it is.
            ->assertSee($overdue->contract_number)
            ->assertSee('4 days late')
            // A returned agreement is not chased.
            ->assertDontSee('RA-RETURNED')
            // Unpaid balances come from the charge account.
            ->assertSee('Unpaid balances')
            ->assertSee('$100.00')
            // Open reservations.
            ->assertSee('Open reservations')
            ->assertSee($reservation->customer->full_name)
            ->assertSee(route('reservations.show', $reservation))
            // Out of stock is sale stock with nothing left, not a rental unit on rent.
            ->assertSee('Hard Hat')
            ->assertSee('0 of 10 available')
            ->assertDontSee('Spare Drill')
            ->assertDontSee('0 of 1 available')
            // Each card links through.
            ->assertSee(route('rental-agreements.index'))
            ->assertSee(route('receivables.index'))
            ->assertSee(route('products.index'));
    }

    public function test_a_rental_only_tenant_does_not_get_a_low_stock_card(): void
    {
        $this->travelTo('2026-11-16 09:00:00');

        $business = $this->business();
        $manager = $this->user($business);
        $this->product($business, 'Excavator', 1, 0);

        // Nothing is sold, so sale stock can never run out: the card is noise.
        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Out of stock')
            ->assertDontSee('Nothing is out of stock.');
    }

    public function test_another_tenants_work_is_not_on_the_dashboard(): void
    {
        $this->travelTo('2026-11-16 09:00:00');

        $business = $this->business();
        $manager = $this->user($business);

        $other = $this->business('Other Rentals');
        $otherCustomer = $this->customer($other, 'Olive');
        $otherContract = $this->contract($other, $otherCustomer, ['contract_number' => 'RA-OTHER']);
        $this->product($other, 'Other Widget', 2, 0);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee($otherContract->contract_number)
            ->assertDontSee('Other Widget')
            ->assertDontSee('Olive');
    }

    public function test_a_super_admin_still_gets_the_platform_summary(): void
    {
        $business = $this->business();

        $superAdmin = new User(['name' => 'Sam', 'email' => 'super@acme.test', 'password' => 'password']);
        $superAdmin->role = 'super_admin';
        $superAdmin->email_verified_at = now();
        $superAdmin->save();

        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Platform-wide summary', false)
            ->assertSee($business->name);
    }
}
