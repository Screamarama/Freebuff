<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Models\User;
use App\Services\AgreementLedger;
use App\Services\CustomerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerStatementTest extends TestCase
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

    private function customer(Business $business): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'CUS-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0101',
            'account_type' => 'charge',
            'credit_limit' => '5000.00',
        ]);
    }

    private function agreement(Business $business, Customer $customer, float $total): RentalContract
    {
        return RentalContract::create([
            'business_id' => $business->id,
            'contract_number' => 'RA-'.uniqid(),
            'customer_id' => $customer->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-05',
            'status' => 'active',
            'subtotal' => $total,
            'total_amount' => $total,
        ]);
    }

    public function test_the_statement_lists_each_charge_and_payment_with_a_running_balance(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);
        $ledger = app(AgreementLedger::class);

        $first = $this->agreement($business, $customer, 100.00);
        $accounts->charge($first, $manager);
        $ledger->record($first, ['amount' => 40, 'payment_method' => 'cash'], $manager);

        $second = $this->agreement($business, $customer, 50.00);
        $accounts->charge($second, $manager);
        $ledger->record($second, ['amount' => 50, 'payment_method' => 'cash'], $manager);
        // Cut the agreement down after it was paid in full: $10 of credit.
        $second->update(['total_amount' => 40.00, 'subtotal' => 40.00]);

        $statement = $accounts->statement($customer->fresh());
        $lines = $statement['lines'];

        $this->assertCount(5, $lines);
        $this->assertSame(['Charge — '.$first->contract_number, 100.0, 100.0], [$lines[0]['description'], $lines[0]['amount'], $lines[0]['balance']]);
        $this->assertStringStartsWith('Payment — ', $lines[1]['description']);
        $this->assertSame([-40.0, 60.0], [$lines[1]['amount'], $lines[1]['balance']]);
        // The charge line keeps what was billed; the cut is its own line.
        $this->assertSame(['Charge — '.$second->contract_number, 50.0, 110.0], [$lines[2]['description'], $lines[2]['amount'], $lines[2]['balance']]);
        $this->assertSame([-50.0, 60.0], [$lines[3]['amount'], $lines[3]['balance']]);
        $this->assertSame(['Charges reduced — '.$second->contract_number, -10.0, 50.0], [$lines[4]['description'], $lines[4]['amount'], $lines[4]['balance']]);
        $this->assertSame(50.0, $statement['closing']);
        // Closing agrees with the account: $60 owed less $10 credit.
        $this->assertSame(['owed' => 60.0, 'credited' => 10.0, 'net' => 50.0], $accounts->position($customer->fresh()));

        $this->actingAs($manager)
            ->get(route('customers.statement', $customer))
            ->assertOk()
            ->assertSee('Customer statement')
            ->assertSee('Jane Doe')
            ->assertSee($first->contract_number)
            ->assertSee($second->contract_number)
            ->assertSee('Closing balance')
            ->assertSee('$100.00')
            ->assertSee('-$50.00')
            ->assertSeeInOrder(['$60.00', 'owed,', '$10.00', 'credit']);
    }

    public function test_the_tax_inside_a_charge_gets_its_own_line_and_survives_a_rate_change(): void
    {
        $business = $this->business();
        $business->update(['country' => 'US', 'state_sales_tax' => 9]);
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);

        $contract = $this->agreement($business, $customer, 100.00);
        $contract->update(['tax_rate' => 9.00, 'tax_amount' => 9.00, 'total_amount' => 109.00]);
        $accounts->charge($contract, $manager);

        $statement = $accounts->statement($customer->fresh());
        $lines = $statement['lines'];

        $this->assertCount(2, $lines);
        $this->assertSame(['Charge — '.$contract->contract_number, 100.0, 100.0], [$lines[0]['description'], $lines[0]['amount'], $lines[0]['balance']]);
        $this->assertSame(['Sales tax — '.$contract->contract_number, 9.0, 109.0], [$lines[1]['description'], $lines[1]['amount'], $lines[1]['balance']]);
        $this->assertSame(109.0, $statement['closing']);
        $this->assertSame(109.0, $accounts->position($customer->fresh())['owed']);

        // A later rate change must not rewrite the reprint.
        $business->update(['state_sales_tax' => 20]);

        $reprint = $accounts->statement($customer->fresh());

        $this->assertSame(['Charge — '.$contract->contract_number, 'Sales tax — '.$contract->contract_number], array_column($reprint['lines'], 'description'));
        $this->assertSame([100.0, 9.0], array_column($reprint['lines'], 'amount'));
        $this->assertSame(109.0, $reprint['closing']);

        $this->actingAs($manager)
            ->get(route('customers.statement', $customer))
            ->assertOk()
            ->assertSee('Sales tax — '.$contract->contract_number)
            ->assertSee('$9.00')
            ->assertSee('$109.00');
    }

    public function test_the_tax_label_is_snapshotted_with_the_charge(): void
    {
        $business = $this->business();
        $business->update(['country' => 'US', 'state_sales_tax' => 9]);
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);

        $billedInUs = $this->agreement($business, $customer, 100.00);
        $billedInUs->update(['tax_rate' => 9.00, 'tax_amount' => 9.00, 'total_amount' => 109.00]);
        $accounts->charge($billedInUs, $manager);

        $this->assertSame('Sales tax', $billedInUs->fresh()->charged_tax_label);

        // The tenant moves to a VAT country after the charge was issued.
        $business->update(['country' => 'CA', 'state_sales_tax' => null, 'vat' => 20]);

        $labels = array_column($accounts->statement($customer->fresh())['lines'], 'description');

        $this->assertContains('Sales tax — '.$billedInUs->contract_number, $labels);
        $this->assertNotContains('VAT — '.$billedInUs->contract_number, $labels);

        // A charge billed after the move reads the new label.
        $billedInCa = $this->agreement($business, $customer, 100.00);
        $billedInCa->update(['tax_rate' => 20.00, 'tax_amount' => 20.00, 'total_amount' => 120.00]);
        $accounts->charge($billedInCa, $manager);

        $labels = array_column($accounts->statement($customer->fresh())['lines'], 'description');

        $this->assertContains('VAT — '.$billedInCa->contract_number, $labels);
    }

    public function test_a_late_fee_added_after_billing_gets_its_own_line(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);
        $ledger = app(AgreementLedger::class);

        $contract = $this->agreement($business, $customer, 100.00);
        $accounts->charge($contract, $manager);
        $contract->update(['charged_to_account_at' => now()->subDays(3)]);
        $ledger->record($contract, ['amount' => 20, 'payment_method' => 'cash'], $manager);

        // The agreement was already billed at $100; the late fee comes later.
        $contract->update([
            'status' => 'returned',
            'actual_return_date' => now()->subDay()->toDateString(),
            'late_fee' => 10.00,
            'total_amount' => 110.00,
        ]);

        $statement = $accounts->statement($customer->fresh());

        $this->assertSame([100.0, 10.0, -20.0], array_column($statement['lines'], 'amount'));
        $this->assertSame('Charge — '.$contract->contract_number, $statement['lines'][0]['description']);
        $this->assertSame('Late fee — '.$contract->contract_number, $statement['lines'][1]['description']);
        $this->assertSame(90.0, $statement['closing']);
        $this->assertSame(90.0, $accounts->position($customer->fresh())['owed']);

        $this->actingAs($manager)
            ->get(route('customers.statement', $customer))
            ->assertOk()
            ->assertSee('Late fee —')
            ->assertSee('$90.00');
    }

    public function test_an_adjustment_line_names_every_change_that_moved_the_total(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);

        $contract = $this->agreement($business, $customer, 109.00);
        $contract->update([
            'subtotal' => 100.00,
            'tax_rate' => 9.00,
            'tax_amount' => 9.00,
            // Same day as the charge, so the adjustment follows the two lines
            // it changes rather than being dated before them.
            'actual_return_date' => now()->toDateString(),
        ]);
        $accounts->charge($contract, $manager);

        // Tax rose $9 and a $10 late fee was assessed at the return.
        $contract->update(['tax_amount' => 18.00, 'late_fee' => 10.00, 'total_amount' => 128.00]);

        $statement = $accounts->statement($customer->fresh());

        $this->assertSame('Tax and late fee — '.$contract->contract_number, $statement['lines'][2]['description']);
        $this->assertSame(19.0, $statement['lines'][2]['amount']);
    }

    public function test_a_reversed_adjusted_charge_nets_to_zero_and_keeps_its_adjustment(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);

        $contract = $this->agreement($business, $customer, 109.00);
        $contract->update(['subtotal' => 100.00, 'tax_rate' => 9.00, 'tax_amount' => 9.00]);
        $accounts->charge($contract, $manager);

        // Tax rose by $9 after billing, then the whole charge was reversed.
        $contract->update(['tax_amount' => 18.00, 'total_amount' => 118.00]);
        $accounts->reverseCharge($contract, $manager, 'Customer cancelled');

        $statement = $accounts->statement($customer->fresh());
        $lines = $statement['lines'];

        // Every line is struck through: the customer owes nothing.
        $this->assertSame(0.0, $statement['closing']);
        $this->assertSame([100.0, 9.0, 9.0], array_column($lines, 'amount'));
        $this->assertSame([true, true, true], array_column($lines, 'excluded'));
        $this->assertSame([null, null, null], array_column($lines, 'balance'));

        // The earlier adjustment is kept beside the charge it belongs to, with
        // the same note, so a reprint shows the whole credited amount.
        $this->assertSame('Tax — '.$contract->contract_number, $lines[2]['description']);
        $this->assertSame('Reversed — Customer cancelled', $lines[2]['note']);

        // $9 billed plus $9 adjusted is the $18 of tax the sales-tax report credits.
        $this->assertSame(18.0, round($lines[1]['amount'] + $lines[2]['amount'], 2));
    }

    public function test_money_received_before_billing_is_not_listed_twice(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);
        $ledger = app(AgreementLedger::class);

        $contract = $this->agreement($business, $customer, 100.00);
        $before = $ledger->record($contract, ['amount' => 20, 'payment_method' => 'cash'], $manager);
        $before->update(['payment_date' => now()->subDay()]);

        // $20 was already in; the account only took the $80 remainder, but the
        // charge line is the agreement's charges so nothing is counted twice.
        $accounts->charge($contract, $manager);
        $ledger->record($contract, ['amount' => 10, 'payment_method' => 'cash'], $manager);

        $statement = $accounts->statement($customer->fresh());

        // The earlier receipt is dated first; the charge line is the full 100.
        $this->assertSame([-20.0, 100.0, -10.0], array_column($statement['lines'], 'amount'));
        $this->assertSame([-20.0, 80.0, 70.0], array_column($statement['lines'], 'balance'));
        $this->assertSame(70.0, $statement['closing']);
        $this->assertSame(70.0, $accounts->position($customer->fresh())['owed']);
    }

    public function test_an_account_receipt_shows_its_number_on_each_agreement(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);

        $first = $this->agreement($business, $customer, 100.00);
        $second = $this->agreement($business, $customer, 50.00);
        $accounts->charge($first, $manager);
        $accounts->charge($second, $manager);

        $receipt = $accounts->recordPayment($customer->fresh(), ['amount' => 120, 'payment_method' => 'cash'], $manager);

        $statement = $accounts->statement($customer->fresh());
        $payments = array_values(array_filter(
            $statement['lines'],
            fn (array $line) => str_starts_with($line['description'], 'Payment — ')
        ));

        // Allocations carry no receipt number of their own; the receipt does.
        $this->assertCount(2, $payments);
        $this->assertSame('Payment — '.$receipt->receipt_number, $payments[0]['description']);
        $this->assertSame('Payment — '.$receipt->receipt_number, $payments[1]['description']);
        $this->assertSame(30.0, $statement['closing']);
        $this->assertSame(30.0, $accounts->position($customer->fresh())['owed']);
    }

    public function test_a_deposit_applied_at_return_shows_as_money_in(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);
        $ledger = app(AgreementLedger::class);

        $contract = $this->agreement($business, $customer, 100.00);
        $accounts->charge($contract, $manager);
        $contract->update([
            'status' => 'returned',
            'actual_return_date' => now()->subDay()->toDateString(),
            'deposit_applied' => 40.00,
            'charged_to_account_at' => now()->subDays(2),
        ]);
        $ledger->record($contract, ['amount' => 20, 'payment_method' => 'cash'], $manager);

        $statement = $accounts->statement($customer->fresh());

        $this->assertSame('Charge — '.$contract->contract_number, $statement['lines'][0]['description']);
        $this->assertSame('Deposit applied — '.$contract->contract_number, $statement['lines'][1]['description']);
        $this->assertStringStartsWith('Payment — ', $statement['lines'][2]['description']);
        $this->assertSame([100.0, -40.0, -20.0], array_column($statement['lines'], 'amount'));
        $this->assertSame(40.0, $statement['closing']);
    }

    public function test_voided_payments_and_reversed_charges_are_struck_out(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);
        $ledger = app(AgreementLedger::class);

        $kept = $this->agreement($business, $customer, 100.00);
        $accounts->charge($kept, $manager);
        $voided = $ledger->record($kept, ['amount' => 40, 'payment_method' => 'cash'], $manager);
        $ledger->void($voided, $manager, 'Taken twice');

        $reversed = $this->agreement($business, $customer, 30.00);
        $accounts->charge($reversed, $manager);
        $accounts->reverseCharge($reversed, $manager, 'Billed in error');

        $statement = $accounts->statement($customer->fresh());
        $lines = $statement['lines'];

        // Both stay on the page, but neither moves the balance.
        $this->assertCount(3, $lines);
        $this->assertSame([false, true, true], array_column($lines, 'excluded'));
        $this->assertSame('Voided — Taken twice', $lines[1]['note']);
        $this->assertSame('Reversed — Billed in error', $lines[2]['note']);
        $this->assertSame([100.0, null, null], array_column($lines, 'balance'));
        $this->assertSame(100.0, $statement['closing']);
        $this->assertSame(100.0, $accounts->openBalance($customer->fresh()));

        $this->actingAs($manager)
            ->get(route('customers.statement', $customer))
            ->assertOk()
            ->assertSee('Voided — Taken twice')
            ->assertSee('Reversed — Billed in error')
            ->assertSee('line-through');
    }

    public function test_a_reprint_keeps_the_currency_each_line_was_billed_in(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);
        $ledger = app(AgreementLedger::class);

        $contract = $this->agreement($business, $customer, 100.00);
        $accounts->charge($contract, $manager);
        $this->assertSame('USD', $contract->fresh()->currency);

        // The setting changes after the customer was billed in dollars.
        $business->update(['currency' => 'EUR']);

        $statement = $accounts->statement($customer->fresh());

        $this->assertSame('USD', $statement['lines'][0]['currency']);
        // Totals follow the billed currency until a newer receipt says otherwise.
        $this->assertSame('USD', $statement['currency']);
        $this->actingAs($manager)
            ->get(route('customers.statement', $customer))
            ->assertOk()
            ->assertSee('$100.00')
            ->assertDontSee('€100.00');

        // A receipt taken now is genuinely in euros, and leads the totals.
        $ledger->record($contract, ['amount' => 10, 'payment_method' => 'cash'], $manager);

        $statement = $accounts->statement($customer->fresh());

        $this->assertSame('EUR', $statement['lines'][1]['currency']);
        $this->assertSame('EUR', $statement['currency']);
        $this->actingAs($manager)
            ->get(route('customers.statement', $customer))
            ->assertOk()
            ->assertSee('€10.00');
    }

    public function test_the_statement_uses_the_business_currency(): void
    {
        $business = $this->business();
        $business->update(['currency' => 'EUR']);
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $accounts = app(CustomerAccount::class);

        $contract = $this->agreement($business, $customer, 10.00);
        $accounts->charge($contract, $manager);

        $this->actingAs($manager)
            ->get(route('customers.statement', $customer))
            ->assertOk()
            ->assertSee('€10.00')
            ->assertDontSee('$10.00');
    }

    public function test_a_statement_is_tenant_scoped(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);

        $otherBusiness = $this->business('Other Rentals');
        $intruder = $this->user($otherBusiness, 'manager', 'other@test.test');

        $this->actingAs($manager)
            ->get(route('customers.statement', $customer))
            ->assertOk();

        $this->actingAs($intruder)
            ->get(route('customers.statement', $customer))
            ->assertNotFound();
    }
}
