<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\RentalContract;
use App\Models\User;
use App\Services\AgreementLedger;
use App\Services\CustomerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountAgingTest extends TestCase
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
            'phone' => '555-0142',
            'account_type' => 'charge',
            'credit_limit' => '5000.00',
        ]);
    }

    /** A charged agreement whose due date is moved to a given number of days ago. */
    private function chargedDaysAgo(Business $business, Customer $customer, User $actor, float $total, int $daysAgo): RentalContract
    {
        $contract = RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => 'active',
            'subtotal' => $total,
            'total_amount' => $total,
        ]);

        app(CustomerAccount::class)->charge($contract, $actor);

        $contract->update(['due_on' => now()->subDays($daysAgo)->toDateString()]);

        return $contract;
    }

    public function test_open_charges_are_bucketed_by_how_late_they_are(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);

        $this->chargedDaysAgo($business, $customer, $manager, 100.00, -5); // due in five days
        $this->chargedDaysAgo($business, $customer, $manager, 50.00, 10);
        $this->chargedDaysAgo($business, $customer, $manager, 30.00, 45);
        $this->chargedDaysAgo($business, $customer, $manager, 20.00, 100);

        $aging = app(CustomerAccount::class)->aging($business->id);

        $this->assertSame(200.0, $aging['open']);
        $this->assertSame(100.0, $aging['overdue']);
        $this->assertSame([
            'current' => 100.0,
            'days_1_30' => 50.0,
            'days_31_60' => 30.0,
            'days_61_plus' => 20.0,
        ], $aging['totals']);

        $row = $aging['rows'][0];
        $this->assertSame($customer->id, $row['customer']->id);
        $this->assertSame(200.0, $row['open']);
        $this->assertSame(20.0, $row['buckets']['days_61_plus']);
        $this->assertSame(now()->subDays(100)->toDateString(), $row['oldest_due_on']->toDateString());
    }

    public function test_a_charge_due_today_is_not_yet_overdue(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);

        $this->chargedDaysAgo($business, $customer, $manager, 70.00, 0);

        $aging = app(CustomerAccount::class)->aging($business->id);

        $this->assertSame(70.0, $aging['totals']['current']);
        $this->assertSame(0.0, $aging['overdue']);
        $this->assertSame(0.0, $aging['rows'][0]['overdue']);

        // ...which is also how the page colours it: no red for a due-today charge.
        $this->actingAs($manager)
            ->get(route('receivables.index'))
            ->assertOk()
            ->assertSee(now()->toDateString())
            ->assertDontSee('text-red-700');
    }

    public function test_a_paid_charge_drops_out_of_the_aging_list(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $this->chargedDaysAgo($business, $customer, $manager, 40.00, 10);

        $this->actingAs($manager)->post(route('customers.account-payment', $customer), [
            'amount' => 40,
            'payment_method' => 'cash',
        ]);

        $aging = app(CustomerAccount::class)->aging($business->id);

        $this->assertSame(0.0, $aging['open']);
        $this->assertSame([], $aging['rows']);
    }

    public function test_customers_are_listed_oldest_debt_first(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $younger = $this->customer($business, 'Alice');
        $older = $this->customer($business, 'Bob');

        $this->chargedDaysAgo($business, $younger, $manager, 40.00, 5);
        $this->chargedDaysAgo($business, $older, $manager, 60.00, 70);

        $aging = app(CustomerAccount::class)->aging($business->id);

        $this->assertSame('Bob', $aging['rows'][0]['customer']->first_name);
        $this->assertSame('Alice', $aging['rows'][1]['customer']->first_name);
    }

    public function test_a_credit_is_reported_beside_the_debt_not_netted_away(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);
        $ledger = app(AgreementLedger::class);

        // One charge is owed: $100.
        $this->chargedDaysAgo($business, $customer, $manager, 100.00, -5);

        // Another was paid in full and then cut to $40, leaving $10 of credit.
        $credited = RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => 'active',
            'subtotal' => 50.00,
            'total_amount' => 50.00,
        ]);

        $accounts->charge($credited, $manager);
        $ledger->record($credited, ['amount' => 50, 'payment_method' => 'cash'], $manager);
        $credited->update(['total_amount' => 40.00, 'subtotal' => 40.00]);

        $this->assertSame(-10.0, $ledger->balance($credited->fresh()));
        $this->assertSame(['owed' => 100.0, 'credited' => 10.0, 'net' => 90.0], $accounts->position($customer->fresh()));
        $this->assertSame(100.0, $accounts->openBalance($customer->fresh()));
        // The credit counts against the $5,000 limit, so it frees $4,910.
        $this->assertSame(4910.0, $accounts->availableCredit($customer->fresh()));

        $aging = $accounts->aging($business->id);
        $this->assertSame(100.0, $aging['open']);
        $this->assertSame(10.0, $aging['credited']);
        $this->assertSame(10.0, $aging['rows'][0]['credited']);

        $this->actingAs($manager)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Credit')
            ->assertSeeInOrder(['$10.00', 'owed to the customer']);

        $this->actingAs($manager)
            ->get(route('receivables.index'))
            ->assertOk()
            ->assertSee('Credited')
            ->assertSee('$10.00')
            ->assertSee('$100.00')
            ->assertSee(route('customers.statement', $customer));
    }

    public function test_the_receivables_page_shows_the_buckets_and_tenant_only(): void
    {
        $business = $this->business();
        $other = $this->business('Other Rentals');
        $manager = $this->user($business);
        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');
        $customer = $this->customer($business);
        $stranger = $this->customer($other, 'Zoe');

        $this->chargedDaysAgo($business, $customer, $manager, 50.00, 45);
        $this->chargedDaysAgo($other, $stranger, $this->user($other, 'manager', 'other@acme.test'), 90.00, 45);

        $this->actingAs($manager)
            ->get(route('receivables.index'))
            ->assertOk()
            ->assertSee('Receivables')
            ->assertSee('Jane')
            ->assertSee('31–60 days')
            ->assertSee('$50.00')
            ->assertDontSee('Zoe')
            ->assertDontSee('$90.00');

        // Taking money at the counter needs the same picture.
        $this->actingAs($clerk)->get(route('receivables.index'))->assertOk();
    }
}
