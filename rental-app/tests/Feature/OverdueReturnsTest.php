<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\RentalItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueReturnsTest extends TestCase
{
    use RefreshDatabase;

    private Business $business;

    private User $user;

    private Customer $customer;

    private Product $product;

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

        $this->customer = Customer::create([
            'business_id' => $this->business->id,
            'customer_code' => 'C-1',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
        ]);

        $this->product = Product::create([
            'business_id' => $this->business->id,
            'name' => 'Mini Excavator',
            'product_type' => 'rental',
            'is_active' => true,
            'stock_quantity' => 1,
            'rental_price_day' => 100,
        ]);
    }

    private function contract(array $overrides = []): RentalContract
    {
        $contract = RentalContract::create(array_merge([
            'business_id' => $this->business->id,
            'contract_number' => 'A-'.uniqid(),
            'customer_id' => $this->customer->id,
            'clerk_id' => $this->user->id,
            'start_date' => now()->subDays(4)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'active',
            'subtotal' => 200,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total_amount' => 200,
        ], $overrides));

        RentalItem::create([
            'contract_id' => $contract->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => 100,
            'duration' => 2,
            'subtotal' => 200,
        ]);

        return $contract;
    }

    public function test_the_board_lists_agreements_past_their_end_date_with_days_late_and_a_suggested_fee(): void
    {
        $overdue = $this->contract();

        $this->actingAs($this->user)
            ->get(route('overdue-returns.index'))
            ->assertOk()
            ->assertSee('Overdue returns')
            ->assertSee($overdue->contract_number)
            ->assertSee('Jane Doe')
            ->assertSee('2 days late')
            ->assertSee('100.00')
            ->assertSee('200.00');
    }

    public function test_returned_cancelled_and_still_due_agreements_are_left_off(): void
    {
        $returned = $this->contract([
            'contract_number' => 'A-RETURNED',
            'status' => 'returned',
            'actual_return_date' => now()->subDay()->toDateString(),
        ]);
        $cancelled = $this->contract(['contract_number' => 'A-CANCELLED', 'status' => 'cancelled']);
        $future = $this->contract([
            'contract_number' => 'A-FUTURE',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->get(route('overdue-returns.index'))
            ->assertOk()
            ->assertDontSee($returned->contract_number)
            ->assertDontSee($cancelled->contract_number)
            ->assertDontSee($future->contract_number)
            ->assertSee('Nothing is overdue');
    }

    public function test_another_tenants_overdue_agreement_is_not_shown(): void
    {
        $otherBusiness = Business::create(['name' => 'Other Rentals', 'currency' => 'USD', 'is_active' => true]);
        $otherCustomer = Customer::create([
            'business_id' => $otherBusiness->id,
            'customer_code' => 'O-1',
            'first_name' => 'Other',
            'last_name' => 'Tenant',
            'phone' => '555-0199',
        ]);
        $otherContract = RentalContract::create([
            'business_id' => $otherBusiness->id,
            'contract_number' => 'A-OTHER',
            'customer_id' => $otherCustomer->id,
            'start_date' => now()->subDays(4)->toDateString(),
            'end_date' => now()->subDays(2)->toDateString(),
            'status' => 'active',
            'subtotal' => 100,
            'tax_amount' => 0,
            'tax_rate' => 0,
            'total_amount' => 100,
        ]);

        $this->actingAs($this->user)
            ->get(route('overdue-returns.index'))
            ->assertOk()
            ->assertDontSee($otherContract->contract_number);
    }

    public function test_the_chase_list_downloads_as_csv_with_the_same_rows_and_totals(): void
    {
        $overdue = $this->contract();
        $future = $this->contract([
            'contract_number' => 'A-FUTURE',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $response = $this->actingAs($this->user)->get(route('overdue-returns.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('overdue-returns-'.now()->format('Y-m-d').'.csv', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Agreement,Customer,Phone,"Due back","Days late",Units,"Daily rent","Suggested late fee",Currency', $csv);
        $this->assertStringContainsString($overdue->contract_number, $csv);
        $this->assertStringContainsString('"Jane Doe",555-0142', $csv);
        $this->assertStringContainsString('2,1,100.00,200.00,USD', $csv);
        $this->assertStringContainsString('"Overdue total","1 agreement",,,,1,,200.00,USD', $csv);
        $this->assertStringNotContainsString($future->contract_number, $csv);
    }

    public function test_a_clerk_can_see_the_board(): void
    {
        $clerk = new User(['name' => 'Dana Clerk', 'email' => 'clerk@acme.test', 'password' => 'password']);
        $clerk->role = 'clerk';
        $clerk->business_id = $this->business->id;
        $clerk->save();

        $this->actingAs($clerk)->get(route('overdue-returns.index'))->assertOk();
    }

    public function test_the_return_form_prefills_the_suggested_late_fee_but_keeps_a_fee_already_set(): void
    {
        $overdue = $this->contract();

        $this->actingAs($this->user)
            ->get(route('rental-agreements.show', $overdue))
            ->assertOk()
            ->assertSee('name="late_fee"', false)
            ->assertSee('value="200"', false);

        // A fee already on the agreement is not replaced by the suggestion.
        $overdue->update(['late_fee' => 50]);

        $this->actingAs($this->user)
            ->get(route('rental-agreements.show', $overdue))
            ->assertOk()
            ->assertSee('value="50.00"', false);

        // Nothing overdue: the field stays at zero.
        $future = $this->contract([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->user)
            ->get(route('rental-agreements.show', $future))
            ->assertOk()
            ->assertSee('value="0.00"', false);
    }

    public function test_a_same_day_rental_is_charged_one_days_rent_per_day_late(): void
    {
        $contract = $this->contract([
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDays(3)->toDateString(),
            'subtotal' => 50,
        ]);

        $this->assertSame(3, $contract->daysLate(now()));
        $this->assertSame(50.0, $contract->dailyRent());
        $this->assertSame(150.0, $contract->suggestedLateFee(now()));
    }
}
