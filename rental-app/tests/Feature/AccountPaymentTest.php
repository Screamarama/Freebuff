<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\RentalContract;
use App\Models\User;
use App\Services\AgreementLedger;
use App\Services\CustomerAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function business(): Business
    {
        return Business::create([
            'name' => 'Acme Rentals',
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

    private function customer(Business $business, string $limit = '1000.00'): Customer
    {
        return Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
            'account_type' => 'charge',
            'credit_limit' => $limit,
        ]);
    }

    private function contract(Business $business, Customer $customer, float $total): RentalContract
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

    /** Two open charges ($60 older, $40 newer) on one account. */
    private function twoOpenCharges(): array
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);
        $older = $this->contract($business, $customer, 60.00);
        $newer = $this->contract($business, $customer, 40.00);
        $accounts = app(CustomerAccount::class);

        $accounts->charge($older, $manager);
        $accounts->charge($newer, $manager);

        return [$business, $manager, $customer, $older, $newer];
    }

    public function test_one_receipt_pays_the_oldest_charges_first(): void
    {
        [$business, $manager, $customer, $older, $newer] = $this->twoOpenCharges();

        $this->actingAs($manager)
            ->post(route('customers.account-payment', $customer), [
                'amount' => 80,
                'payment_method' => 'cash',
            ])
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHasNoErrors();

        $receipt = Payment::where('kind', 'account_payment')->sole();
        $this->assertSame('80.00', (string) $receipt->amount);
        $this->assertNotNull($receipt->receipt_number);
        $this->assertSame('customer', $receipt->reference_type);

        // One allocation per charge, oldest first, with no receipt number of
        // its own: the number belongs to the customer-level receipt.
        $allocations = Payment::where('account_payment_id', $receipt->id)
            ->orderBy('reference_id')
            ->get();

        $this->assertCount(2, $allocations);
        $this->assertSame(['60.00', '20.00'], $allocations->map(fn ($p) => (string) $p->amount)->all());
        $this->assertNull($allocations->first()->receipt_number);

        $ledger = app(AgreementLedger::class);
        $this->assertSame(0.0, $ledger->balance($older->fresh()));
        $this->assertSame(20.0, $ledger->balance($newer->fresh()));
        $this->assertSame(20.0, app(CustomerAccount::class)->openBalance($customer->fresh()));

        $audit = AuditLog::where('action', 'account.payment_recorded')->sole();
        $this->assertSame('80.00', $audit->details['amount']);
        $this->assertSame(2, $audit->details['charges']);
    }

    public function test_a_payment_above_the_outstanding_balance_is_refused(): void
    {
        [$business, $manager, $customer, $older] = $this->twoOpenCharges();

        $this->actingAs($manager)
            ->post(route('customers.account-payment', $customer), [
                'amount' => 100.01,
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::where('kind', 'account_payment')->count());
        $this->assertSame(100.0, app(CustomerAccount::class)->openBalance($customer->fresh()));
        $this->assertSame(60.0, app(AgreementLedger::class)->balance($older->fresh()));
    }

    public function test_an_account_with_nothing_outstanding_takes_no_payment(): void
    {
        $business = $this->business();
        $manager = $this->user($business);
        $customer = $this->customer($business);

        $this->actingAs($manager)
            ->post(route('customers.account-payment', $customer), [
                'amount' => 10,
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors([
                'account' => 'This account has nothing outstanding to pay.',
            ]);

        $this->assertSame(0, Payment::count());
    }

    public function test_voiding_one_allocation_voids_the_whole_account_receipt(): void
    {
        [$business, $manager, $customer, $older, $newer] = $this->twoOpenCharges();

        $this->actingAs($manager)->post(route('customers.account-payment', $customer), [
            'amount' => 80,
            'payment_method' => 'cash',
        ]);

        $receipt = Payment::where('kind', 'account_payment')->sole();
        $allocation = Payment::where('account_payment_id', $receipt->id)
            ->where('reference_id', $older->id)
            ->sole();

        $this->actingAs($manager)
            ->patch(route('rental-agreements.payments.void', [$older, $allocation]), [
                'reason' => 'Paid on the wrong account',
            ])
            ->assertSessionHasNoErrors();

        $ledger = app(AgreementLedger::class);
        $this->assertSame(60.0, $ledger->balance($older->fresh()));
        $this->assertSame(40.0, $ledger->balance($newer->fresh()));
        $this->assertSame(100.0, app(CustomerAccount::class)->openBalance($customer->fresh()));

        $this->assertNotNull($receipt->fresh()->voided_at);
        $this->assertSame('Paid on the wrong account', $receipt->fresh()->void_reason);

        $this->assertSame(
            0,
            Payment::where('account_payment_id', $receipt->id)->effective()->count()
        );

        // The reversing entries stay attached to the receipt, so its history is
        // one trail rather than orphaned rows on the agreements.
        $this->assertSame(
            2,
            Payment::where('account_payment_id', $receipt->id)->whereNotNull('reversal_of_id')->count()
        );

        $audit = AuditLog::where('action', 'account.payment_voided')->sole();
        $this->assertSame(2, $audit->details['charges']);
        $this->assertSame('Paid on the wrong account', $audit->details['reason']);
    }

    public function test_the_customer_page_shows_receipts_and_what_each_paid(): void
    {
        [$business, $manager, $customer, $older, $newer] = $this->twoOpenCharges();

        $this->actingAs($manager)->post(route('customers.account-payment', $customer), [
            'amount' => 80,
            'payment_method' => 'cash',
        ]);

        $receipt = Payment::where('kind', 'account_payment')->sole();

        $this->actingAs($manager)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee($receipt->receipt_number)
            ->assertSee($older->contract_number)
            ->assertSee($newer->contract_number)
            ->assertSee('60.00')
            ->assertSee('20.00');
    }

    public function test_an_account_receipt_can_be_voided_from_the_customer_page(): void
    {
        [$business, $manager, $customer, $older, $newer] = $this->twoOpenCharges();

        $this->actingAs($manager)->post(route('customers.account-payment', $customer), [
            'amount' => 80,
            'payment_method' => 'cash',
        ]);

        $receipt = Payment::where('kind', 'account_payment')->sole();

        $this->actingAs($manager)
            ->patch(route('customers.account-payments.void', [$customer, $receipt]), [
                'reason' => 'Paid on the wrong account',
            ])
            ->assertRedirect(route('customers.show', $customer))
            ->assertSessionHasNoErrors();

        $this->assertNotNull($receipt->fresh()->voided_at);
        $this->assertSame(100.0, app(CustomerAccount::class)->openBalance($customer->fresh()));

        $this->actingAs($manager)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('voided')
            ->assertSee('Paid on the wrong account')
            ->assertDontSee(route('customers.account-payments.void', [$customer, $receipt]));
    }

    public function test_a_clerk_sees_the_receipts_without_a_void_form(): void
    {
        [$business, $manager, $customer] = $this->twoOpenCharges();
        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');

        $this->actingAs($manager)->post(route('customers.account-payment', $customer), [
            'amount' => 80,
            'payment_method' => 'cash',
        ]);

        $receipt = Payment::where('kind', 'account_payment')->sole();

        $this->actingAs($clerk)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee($receipt->receipt_number)
            ->assertSee('Account receipts')
            ->assertDontSee(route('customers.account-payments.void', [$customer, $receipt]));
    }

    public function test_a_clerk_cannot_void_an_account_receipt(): void
    {
        [$business, $manager, $customer, $older, $newer] = $this->twoOpenCharges();
        $clerk = $this->user($business, 'clerk', 'clerk@acme.test');

        $this->actingAs($manager)->post(route('customers.account-payment', $customer), [
            'amount' => 80,
            'payment_method' => 'cash',
        ]);

        $receipt = Payment::where('kind', 'account_payment')->sole();
        $allocation = Payment::where('account_payment_id', $receipt->id)->firstOrFail();

        $this->actingAs($clerk)
            ->patch(route('rental-agreements.payments.void', [$older, $allocation]), [
                'reason' => 'Trying it anyway',
            ])
            ->assertForbidden();

        $this->assertNull($receipt->fresh()->voided_at);
        $this->assertSame(0.0, app(AgreementLedger::class)->balance($older->fresh()));
        $this->assertSame(20.0, app(AgreementLedger::class)->balance($newer->fresh()));
    }
}
