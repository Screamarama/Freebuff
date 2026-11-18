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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function business(): Business
    {
        return Business::create(['name' => 'Acme Rentals', 'currency' => 'USD', 'is_active' => true]);
    }

    private function manager(Business $business): User
    {
        $user = new User(['name' => 'Mia Manager', 'email' => 'manager@acme.test', 'password' => 'password']);
        $user->role = 'manager';
        $user->business_id = $business->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    private function customer(Business $business): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'account_type' => 'cash',
        ]);
    }

    private function product(Business $business, int $stock): Product
    {
        return Product::create([
            'business_id' => $business->id,
            'name' => 'Compressor',
            'product_type' => 'rental',
            'rental_price_day' => 10.00,
            'stock_quantity' => $stock,
            'available_quantity' => $stock,
            'is_active' => true,
        ]);
    }

    private function contract(Business $business, Customer $customer, array $attributes = []): RentalContract
    {
        return RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'subtotal' => 0,
            'total_amount' => 0,
            ...$attributes,
        ]);
    }

    private function line(RentalContract $contract, Product $product, int $quantity): RentalItem
    {
        return $contract->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'rate_type' => 'day',
            'unit_price' => 10.00,
            'duration' => 2,
            'subtotal' => 20.00 * $quantity,
        ]);
    }

    private function reservation(Business $business, Customer $customer, Product $product, int $quantity, array $attributes = []): Reservation
    {
        return Reservation::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'quantity' => $quantity,
            'starts_at' => now()->addDays(3)->setTime(9, 0),
            'ends_at' => now()->addDays(4)->setTime(9, 0),
            'status' => 'upcoming',
            ...$attributes,
        ]);
    }

    public function test_a_reservation_cannot_overlap_an_agreement_holding_the_stock(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business, 1);

        $this->line($this->contract($business, $customer), $product, 1);

        $this->actingAs($manager)
            ->post(route('reservations.store'), [
                'product_id' => $product->id,
                'customer_id' => $customer->id,
                'quantity' => 1,
                'starts_at' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
                'ends_at' => now()->addDays(4)->setTime(10, 0)->toDateTimeString(),
            ])
            ->assertStatus(422);

        $this->assertSame(0, Reservation::count());
    }

    public function test_a_reservation_is_allowed_when_the_stock_is_free_over_those_dates(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business, 1);

        // The agreement ends before the reservation starts.
        $this->line($this->contract($business, $customer, [
            'start_date' => now()->addDays(8)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]), $product, 1);

        $this->actingAs($manager)
            ->post(route('reservations.store'), [
                'product_id' => $product->id,
                'customer_id' => $customer->id,
                'quantity' => 1,
                'starts_at' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
                'ends_at' => now()->addDays(4)->setTime(10, 0)->toDateTimeString(),
            ])
            ->assertRedirect(route('reservations.index'));

        $this->assertSame(1, Reservation::count());
    }

    public function test_converting_a_reservation_checks_agreements_too(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business, 1);

        // Another agreement already holds the only unit for those dates.
        $this->line($this->contract($business, $customer), $product, 1);

        $reservation = $this->reservation($business, $customer, $product, 1);

        $this->actingAs($manager)
            ->post(route('reservations.convert', $reservation))
            ->assertSessionHasErrors('reservation');

        $this->assertSame(0, RentalContract::where('reservation_id', $reservation->id)->count());
        $this->assertSame('upcoming', $reservation->fresh()->status);
    }

    public function test_a_rentals_available_quantity_follows_what_is_held_today(): void
    {
        $this->travelTo(now()->setTime(9, 0));

        $business = $this->business();
        $customer = $this->customer($business);
        $product = $this->product($business, 2);

        $contract = $this->contract($business, $customer, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);
        $this->line($contract, $product, 1);

        $reservation = $this->reservation($business, $customer, $product, 1, [
            'starts_at' => now()->setTime(10, 0),
            'ends_at' => now()->setTime(16, 0),
        ]);

        $this->assertSame(0, $product->fresh()->available_quantity);

        // Releasing the reservation hands its unit back; the agreement still holds one.
        $reservation->update(['status' => 'cancelled']);

        $this->assertSame(1, $product->fresh()->available_quantity);
    }

    public function test_the_products_pages_render_a_rentals_derived_availability(): void
    {
        $this->travelTo(now()->setTime(9, 0));

        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business, 2);

        $contract = $this->contract($business, $customer, [
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);
        $this->line($contract, $product, 1);

        // One of the two units is on hire today, so the pages say 1 of 2.
        $this->actingAs($manager)
            ->get(route('products.index'))
            ->assertOk()
            ->assertSee('Available')
            ->assertSee('1 of 2');

        $this->actingAs($manager)
            ->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Available quantity')
            ->assertSee('1 of 2 available');
    }

    public function test_a_reservation_whose_window_has_passed_stops_holding_stock(): void
    {
        $this->travelTo(now()->setTime(15, 0));

        $business = $this->business();
        $customer = $this->customer($business);
        $product = $this->product($business, 1);

        $this->reservation($business, $customer, $product, 1, [
            'starts_at' => now()->setTime(8, 0),
            'ends_at' => now()->setTime(9, 0),
            'status' => 'upcoming',
        ]);

        // The hold ended at 09:00 and nobody converted it, so the unit is free.
        $this->assertSame(1, $product->fresh()->available_quantity);
    }

    public function test_an_agreement_moving_onto_another_booking_is_refused(): void
    {
        $business = $this->business();
        $customer = $this->customer($business);
        $product = $this->product($business, 1);

        $first = $this->contract($business, $customer, [
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ]);
        $this->line($first, $product, 1);

        $second = $this->contract($business, $customer, [
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
        ]);
        $this->line($second, $product, 1);

        $first->start_date = $second->start_date;
        $first->end_date = $second->end_date;

        $this->expectException(HttpException::class);

        app(\App\Services\Availability::class)->assertContractCanHold($first);
    }

    public function test_an_agreement_whose_units_are_already_held_is_refused(): void
    {
        $business = $this->business();
        $customer = $this->customer($business);
        $product = $this->product($business, 1);

        $this->reservation($business, $customer, $product, 1);

        $draft = $this->contract($business, $customer, ['status' => 'draft']);
        $this->line($draft, $product, 1);

        $this->expectException(HttpException::class);

        app(\App\Services\Availability::class)->assertContractCanHold($draft);
    }

    public function test_the_daily_availability_list_agrees_with_the_booking_check(): void
    {
        $this->travelTo(now()->setTime(9, 0));

        $business = $this->business();
        $customer = $this->customer($business);
        $product = $this->product($business, 2);

        // One unit on an agreement for two days, two more on a reservation.
        $contract = $this->contract($business, $customer, [
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);
        $this->line($contract, $product, 1);

        $this->reservation($business, $customer, $product, 2, [
            'starts_at' => now()->addDays(2)->setTime(9, 0),
            'ends_at' => now()->addDays(2)->setTime(17, 0),
        ]);

        $availability = app(\App\Services\Availability::class);
        $from = now()->toDateString();
        $to = now()->addDays(3)->toDateString();
        $days = collect($availability->dailyAvailability($product, $from, $to))
            ->keyBy(fn (array $day) => $day['date']->toDateString());

        $this->assertSame(2, $days[$from]['available']);
        $this->assertSame(1, $days[now()->addDay()->toDateString()]['available']);
        $this->assertSame(0, $days[now()->addDays(2)->toDateString()]['available']);
        $this->assertSame(3, $days[now()->addDays(2)->toDateString()]['booked']);

        foreach ($days as $day) {
            // A day row means the whole day, so compare with a booking check
            // for that same window.
            $this->assertSame(
                $availability->availableFor(
                    $product,
                    $day['date']->copy()->startOfDay(),
                    $day['date']->copy()->endOfDay()
                ),
                $day['available'],
                'The list and the check disagree on ' . $day['date']->toDateString()
            );
        }
    }

    public function test_the_product_page_shows_the_availability_range(): void
    {
        $this->travelTo(now()->setTime(9, 0));

        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business, 1);

        $contract = $this->contract($business, $customer, [
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ]);
        $this->line($contract, $product, 1);

        $from = now()->toDateString();
        $to = now()->addDays(2)->toDateString();

        $this->actingAs($manager)
            ->get(route('products.show', ['product' => $product, 'from' => $from, 'to' => $to]))
            ->assertOk()
            ->assertSee('Availability')
            ->assertSee(now()->addDay()->format('D, M j'))
            ->assertSee('Fully booked')
            ->assertSee('Free');
    }

    public function test_a_to_date_without_a_from_date_starts_today(): void
    {
        $this->travelTo(now()->setTime(9, 0));

        $business = $this->business();
        $manager = $this->manager($business);
        $product = $this->product($business, 1);

        $this->actingAs($manager)
            ->get(route('products.show', ['product' => $product, 'to' => now()->addDays(2)->toDateString()]))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertViewHas('from', fn ($from) => $from->toDateString() === now()->toDateString())
            ->assertSee('Availability');
    }

    public function test_a_to_date_before_the_default_from_date_is_rejected(): void
    {
        $this->travelTo(now()->setTime(9, 0));

        $business = $this->business();
        $manager = $this->manager($business);
        $product = $this->product($business, 1);

        $this->actingAs($manager)
            ->from(route('products.show', $product))
            ->get(route('products.show', ['product' => $product, 'to' => now()->subDay()->toDateString()]))
            ->assertSessionHasErrors(['to' => 'The end date must be on or after the start date.']);
    }

    public function test_a_long_range_is_shortened_with_a_notice(): void
    {
        $this->travelTo(now()->setTime(9, 0));

        $business = $this->business();
        $manager = $this->manager($business);
        $product = $this->product($business, 1);

        $response = $this->actingAs($manager)
            ->get(route('products.show', [
                'product' => $product,
                'from' => now()->toDateString(),
                'to' => now()->addDays(60)->toDateString(),
            ]))
            ->assertOk()
            ->assertViewHas('truncated', true)
            ->assertSee('The range was shortened to 31 days');

        $this->assertSame(
            now()->addDays(30)->toDateString(),
            $response->viewData('to')->toDateString()
        );
    }

    public function test_another_tenants_product_cannot_be_booked_or_added(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);

        $other = $this->business();
        $otherProduct = $this->product($other, 1);
        $otherCustomer = $this->customer($other);

        $this->actingAs($manager)
            ->post(route('reservations.store'), [
                'product_id' => $otherProduct->id,
                'customer_id' => $otherCustomer->id,
                'quantity' => 1,
                'starts_at' => now()->addDays(2)->setTime(9, 0)->toDateTimeString(),
                'ends_at' => now()->addDays(3)->setTime(9, 0)->toDateTimeString(),
            ])
            ->assertStatus(404);

        $contract = $this->contract($business, $customer);

        $this->actingAs($manager)
            ->post(route('rental-agreements.items.store', $contract), [
                'kind' => 'rental',
                'product_id' => $otherProduct->id,
                'quantity' => 1,
                'rate_type' => 'day',
                'duration' => 2,
            ])
            ->assertStatus(404);

        $this->assertSame(0, $contract->items()->count());
        $this->assertSame(0, Reservation::count());
    }

    public function test_updating_a_reservation_does_not_count_it_against_itself(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business, 2);

        $reservation = $this->reservation($business, $customer, $product, 2);

        // The whole stock is already on this reservation: moving it must pass.
        $this->actingAs($manager)
            ->patch(route('reservations.update', $reservation), [
                'product_id' => $product->id,
                'customer_id' => $customer->id,
                'quantity' => 2,
                'starts_at' => now()->addDays(5)->setTime(10, 0)->toDateTimeString(),
                'ends_at' => now()->addDays(6)->setTime(10, 0)->toDateTimeString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $reservation->fresh()->quantity);

        // Raising it beyond what an agreement leaves free is still refused.
        $this->line($this->contract($business, $customer), $product, 1);

        $this->actingAs($manager)
            ->patch(route('reservations.update', $reservation), [
                'product_id' => $product->id,
                'customer_id' => $customer->id,
                'quantity' => 2,
                'starts_at' => now()->addDays(3)->setTime(10, 0)->toDateTimeString(),
                'ends_at' => now()->addDays(4)->setTime(10, 0)->toDateTimeString(),
            ])
            ->assertStatus(422);

        // The refused update left the moved reservation as it was.
        $this->assertSame(now()->addDays(5)->toDateString(), $reservation->fresh()->starts_at->toDateString());
    }

    public function test_a_second_line_of_the_same_product_is_checked_as_one_total(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business, 2);
        $contract = $this->contract($business, $customer);

        $this->line($contract, $product, 1);

        // The contract's own line is added back, so a second one still fits.
        $this->actingAs($manager)
            ->post(route('rental-agreements.items.store', $contract), [
                'kind' => 'rental',
                'product_id' => $product->id,
                'quantity' => 1,
                'rate_type' => 'day',
                'duration' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $contract->items()->sum('quantity'));

        // A third would be one more than the stock has.
        $this->actingAs($manager)
            ->post(route('rental-agreements.items.store', $contract), [
                'kind' => 'rental',
                'product_id' => $product->id,
                'quantity' => 1,
                'rate_type' => 'day',
                'duration' => 2,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(2, (int) $contract->items()->sum('quantity'));
    }

    public function test_adding_an_item_cannot_oversubscribe_stock(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business, 2);
        $contract = $this->contract($business, $customer);

        // One unit is promised to a reservation over the same dates.
        $this->reservation($business, $customer, $product, 1);

        $this->actingAs($manager)
            ->post(route('rental-agreements.items.store', $contract), [
                'kind' => 'rental',
                'product_id' => $product->id,
                'quantity' => 2,
                'rate_type' => 'day',
                'duration' => 2,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, $contract->items()->count());

        // One unit is free, so one is allowed.
        $this->actingAs($manager)
            ->post(route('rental-agreements.items.store', $contract), [
                'kind' => 'rental',
                'product_id' => $product->id,
                'quantity' => 1,
                'rate_type' => 'day',
                'duration' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, (int) $contract->items()->sum('quantity'));
    }

    public function test_changing_an_item_quantity_respects_the_same_stock(): void
    {
        $business = $this->business();
        $manager = $this->manager($business);
        $customer = $this->customer($business);
        $product = $this->product($business, 2);
        $contract = $this->contract($business, $customer);
        $item = $this->line($contract, $product, 2);

        $this->actingAs($manager)
            ->patch(route('rental-agreements.items.update', [$contract, $item]), [
                'quantity' => 3,
                'unit_price' => 10.00,
                'duration' => 2,
            ])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(2, (int) $item->fresh()->quantity);

        // The contract's own two units are not counted against themselves.
        $this->actingAs($manager)
            ->patch(route('rental-agreements.items.update', [$contract, $item]), [
                'quantity' => 2,
                'unit_price' => 10.00,
                'duration' => 2,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $item->fresh()->quantity);
    }
}
