<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalContract;
use App\Models\User;
use App\Services\AgreementLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalDepositTest extends TestCase
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

    private function user(Business $business, string $role = 'clerk', string $email = 'clerk@acme.test'): User
    {
        $user = new User(['name' => 'Dana Clerk', 'email' => $email, 'password' => 'password']);
        $user->role = $role;
        $user->business_id = $business->id;
        $user->email_verified_at = now();
        $user->save();

        return $user;
    }

    /** One rented item at $100, so damage fees have a line to land on. */
    private function contract(Business $business, float $total = 100.00): RentalContract
    {
        $customer = Customer::create([
            'business_id' => $business->id,
            'customer_code' => 'C-'.uniqid(),
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0142',
        ]);

        $product = Product::create([
            'business_id' => $business->id,
            'name' => 'Excavator',
            'product_type' => 'rental',
            'rental_price_day' => $total,
            'deposit_amount' => 50.00,
        ]);

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

        $contract->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'rate_type' => 'day',
            'unit_price' => $total,
            'duration' => 1,
            'subtotal' => $total,
        ]);

        return $contract;
    }

    private function payRent(User $clerk, RentalContract $contract, string $amount = '100.00'): void
    {
        $this->actingAs($clerk)->post(route('rental-agreements.payments.store', $contract), [
            'amount' => $amount,
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
    }

    private function holdDeposit(User $clerk, RentalContract $contract, string $amount = '50.00'): void
    {
        $this->actingAs($clerk)->post(route('rental-agreements.payments.deposit', $contract), [
            'deposit_amount' => $amount,
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_deposit_is_held_without_reducing_the_rent_balance(): void
    {
        $business = $this->business();
        $clerk = $this->user($business);
        $contract = $this->contract($business);

        $this->holdDeposit($clerk, $contract, '50.00');

        $deposit = Payment::where('kind', 'deposit_received')->sole();

        $this->assertSame('50.00', $deposit->amount);
        $this->assertSame('deposit_received', $deposit->kind);
        $this->assertMatchesRegularExpression('/^RCP-\d{8}-0001$/', $deposit->receipt_number);
        $this->assertSame('50.00', $contract->fresh()->deposit_paid);

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            // The hold does not pay the rent.
            ->assertViewHas('paid', fn ($paid) => round($paid, 2) === 0.0)
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 100.0)
            ->assertViewHas('deposit', fn ($deposit) => round($deposit['received'], 2) === 50.0
                && round($deposit['remaining'], 2) === 50.0)
            ->assertSee('Deposit held');
    }

    public function test_cancelling_an_agreement_refunds_the_held_deposit_in_full(): void
    {
        $business = $this->business();
        $clerk = $this->user($business, 'manager');
        $contract = $this->contract($business);

        $this->holdDeposit($clerk, $contract, '50.00');

        $this->actingAs($clerk)
            ->from(route('rental-agreements.show', $contract))
            ->patch(route('rental-agreements.cancel', $contract), ['refund_method' => 'cash', 'cancellation_reason' => 'Customer changed plans'])
            ->assertRedirect(route('rental-agreements.show', $contract))
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('cancelled', $contract->status);
        $this->assertSame('0.00', $contract->deposit_paid);

        $refund = Payment::where('kind', 'deposit_refund')->sole();

        $this->assertSame(-50.0, (float) $refund->amount);
        $this->assertSame('cash', $refund->payment_method);
        $this->assertSame('Deposit refunded at cancellation', $refund->notes);
        $this->assertNotNull($refund->receipt_number);
        $this->assertSame(0.0, app(AgreementLedger::class)->depositSummary($contract)['remaining']);
    }

    public function test_cancelling_an_agreement_with_a_deposit_requires_a_refund_method(): void
    {
        $business = $this->business();
        $clerk = $this->user($business, 'manager');
        $contract = $this->contract($business);

        $this->holdDeposit($clerk, $contract, '50.00');

        $this->actingAs($clerk)
            ->from(route('rental-agreements.show', $contract))
            ->patch(route('rental-agreements.cancel', $contract), ['cancellation_reason' => 'Customer changed plans'])
            ->assertSessionHasErrors('refund_method');

        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame(0, $contract->payments()->where('kind', 'deposit_refund')->count());
        $this->assertSame('50.00', $contract->fresh()->deposit_paid);
    }

    public function test_cancelling_refunds_prepaid_rent_and_waives_the_charges(): void
    {
        $business = $this->business();
        $clerk = $this->user($business, 'manager');
        $contract = $this->contract($business);

        $this->payRent($clerk, $contract, '100.00');
        $this->holdDeposit($clerk, $contract, '50.00');

        $this->actingAs($clerk)
            ->from(route('rental-agreements.show', $contract))
            ->patch(route('rental-agreements.cancel', $contract), ['refund_method' => 'cash', 'cancellation_reason' => 'Customer changed plans'])
            ->assertRedirect(route('rental-agreements.show', $contract))
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('cancelled', $contract->status);
        $this->assertSame('0.00', $contract->subtotal);
        $this->assertSame('0.00', $contract->tax_amount);
        $this->assertSame('0.00', $contract->total_amount);
        $this->assertSame('0.00', $contract->deposit_paid);

        $ledger = app(AgreementLedger::class);

        $this->assertSame(0.0, $ledger->charges($contract));
        $this->assertSame(0.0, $ledger->paid($contract));
        $this->assertSame(0.0, $ledger->balance($contract));

        $rentRefund = $contract->payments()->where('kind', 'payment')->where('amount', '<', 0)->sole();

        $this->assertSame(-100.0, (float) $rentRefund->amount);
        $this->assertSame('cash', $rentRefund->payment_method);
        $this->assertSame('Rent refunded at cancellation', $rentRefund->notes);
        $this->assertNotNull($rentRefund->receipt_number);

        $depositRefund = $contract->payments()->where('kind', 'deposit_refund')->sole();

        $this->assertSame(-50.0, (float) $depositRefund->amount);
    }

    public function test_cancelling_with_prepaid_rent_requires_a_refund_method(): void
    {
        $business = $this->business();
        $clerk = $this->user($business, 'manager');
        $contract = $this->contract($business);

        $this->payRent($clerk, $contract, '100.00');

        $this->actingAs($clerk)
            ->from(route('rental-agreements.show', $contract))
            ->patch(route('rental-agreements.cancel', $contract), ['cancellation_reason' => 'Customer changed plans'])
            ->assertSessionHasErrors('refund_method');

        $contract->refresh();

        $this->assertSame('active', $contract->status);
        $this->assertSame('100.00', $contract->total_amount);
        $this->assertSame(0, $contract->payments()->where('amount', '<', 0)->count());
    }

    public function test_a_charged_agreement_must_reverse_the_charge_before_cancelling(): void
    {
        $business = $this->business();
        $clerk = $this->user($business, 'manager');
        $contract = $this->contract($business);

        $contract->update(['charged_to_account_at' => now()]);

        $this->actingAs($clerk)
            ->from(route('rental-agreements.show', $contract))
            ->patch(route('rental-agreements.cancel', $contract), ['refund_method' => 'cash', 'cancellation_reason' => 'Customer changed plans'])
            ->assertSessionHasErrors([
                'refund_method' => 'Reverse the account charge before cancelling this agreement.',
            ]);

        $this->assertSame('active', $contract->fresh()->status);
        $this->assertSame('100.00', $contract->fresh()->total_amount);
    }

    public function test_fees_are_applied_against_the_deposit_and_the_rest_is_refunded(): void
    {
        $business = $this->business();
        $clerk = $this->user($business);
        $contract = $this->contract($business);

        $this->payRent($clerk, $contract);
        $this->holdDeposit($clerk, $contract, '50.00');

        $item = $contract->items()->first();

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.settle', $contract), [
                'returned_on' => now()->toDateString(),
                'late_fee' => '20.00',
                'damage' => [$item->id => '10.00'],
                'refund_method' => 'cash',
            ])
            ->assertRedirect(route('rental-agreements.show', $contract))
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('returned', $contract->status);
        $this->assertSame(now()->toDateString(), $contract->actual_return_date->toDateString());
        $this->assertSame('20.00', $contract->late_fee);
        $this->assertSame('30.00', $contract->deposit_applied);
        $this->assertSame('10.00', $item->fresh()->damage_fee);
        // The hold column falls with the settlement instead of staying at $50.
        $this->assertSame('0.00', $contract->deposit_paid);

        // $20 late + $10 damage out of the $50 hold, $20 handed back.
        $refund = Payment::where('kind', 'deposit_refund')->sole();
        $this->assertSame('-20.00', $refund->amount);
        $this->assertSame('deposit_refund', $refund->kind);

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('charges', fn ($charges) => round($charges, 2) === 130.0)
            ->assertViewHas('paid', fn ($paid) => round($paid, 2) === 130.0)
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 0.0)
            ->assertViewHas('deposit', fn ($deposit) => round($deposit['refunded'], 2) === 20.0
                && round($deposit['applied'], 2) === 30.0)
            ->assertSee('Deposit refunded')
            ->assertSee($refund->receipt_number);
    }

    public function test_the_deposit_column_follows_the_hold_through_a_refund(): void
    {
        $business = $this->business();
        $clerk = $this->user($business);
        $contract = $this->contract($business);

        $this->payRent($clerk, $contract);
        $this->holdDeposit($clerk, $contract, '50.00');

        $this->assertSame('50.00', $contract->fresh()->deposit_paid);

        $item = $contract->items()->first();

        $this->actingAs($clerk)->post(route('rental-agreements.payments.settle', $contract), [
            'returned_on' => now()->toDateString(),
            'late_fee' => '0',
            'damage' => [$item->id => '0'],
            'refund_method' => 'cash',
        ])->assertSessionHasNoErrors();

        // Everything was handed back, so nothing is held any more.
        $this->assertSame('0.00', $contract->fresh()->deposit_paid);
    }

    public function test_fees_beyond_the_deposit_leave_a_balance_due(): void
    {
        $business = $this->business();
        $clerk = $this->user($business);
        $contract = $this->contract($business);

        $this->payRent($clerk, $contract);
        $this->holdDeposit($clerk, $contract, '20.00');

        $item = $contract->items()->first();

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.settle', $contract), [
                'returned_on' => now()->toDateString(),
                'late_fee' => '50.00',
                'damage' => [$item->id => '0'],
                'refund_method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $contract->refresh();

        // The hold covers $20 of the $50 late fee; $30 is still owed.
        $this->assertSame('20.00', $contract->deposit_applied);
        $this->assertSame(0, Payment::where('kind', 'deposit_refund')->count());

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 30.0);
    }

    public function test_the_whole_deposit_is_refunded_when_nothing_is_owed(): void
    {
        $business = $this->business();
        $clerk = $this->user($business);
        $contract = $this->contract($business);

        $this->payRent($clerk, $contract);
        $this->holdDeposit($clerk, $contract, '50.00');

        $item = $contract->items()->first();

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.settle', $contract), [
                'returned_on' => now()->toDateString(),
                'late_fee' => '0',
                'damage' => [$item->id => '0'],
                'refund_method' => 'card',
            ])
            ->assertSessionHasNoErrors();

        $contract->refresh();

        $this->assertSame('0.00', $contract->deposit_applied);

        $refund = Payment::where('kind', 'deposit_refund')->sole();
        $this->assertSame('-50.00', $refund->amount);
        $this->assertSame('card', $refund->payment_method);

        $this->actingAs($clerk)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertViewHas('balance', fn ($balance) => round($balance, 2) === 0.0)
            ->assertViewHas('deposit', fn ($deposit) => round($deposit['refunded'], 2) === 50.0);
    }

    public function test_a_deposit_cannot_be_taken_once_the_agreement_is_returned(): void
    {
        $business = $this->business();
        $clerk = $this->user($business);
        $contract = $this->contract($business);

        $this->payRent($clerk, $contract);
        $this->holdDeposit($clerk, $contract);

        $item = $contract->items()->first();

        $this->actingAs($clerk)->post(route('rental-agreements.payments.settle', $contract), [
            'returned_on' => now()->toDateString(),
            'late_fee' => '0',
            'damage' => [$item->id => '0'],
            'refund_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.deposit', $contract), [
                'deposit_amount' => '30.00',
                'payment_method' => 'cash',
            ])
            ->assertSessionHasErrors('deposit_amount');

        $this->assertSame(1, Payment::where('kind', 'deposit_received')->count());
    }

    public function test_settling_twice_is_refused(): void
    {
        $business = $this->business();
        $clerk = $this->user($business);
        $contract = $this->contract($business);

        $this->payRent($clerk, $contract);
        $this->holdDeposit($clerk, $contract);

        $item = $contract->items()->first();

        $payload = [
            'returned_on' => now()->toDateString(),
            'late_fee' => '10.00',
            'damage' => [$item->id => '0'],
            'refund_method' => 'cash',
        ];

        $this->actingAs($clerk)->post(route('rental-agreements.payments.settle', $contract), $payload)
            ->assertSessionHasNoErrors();

        $rows = Payment::count();

        $this->actingAs($clerk)
            ->post(route('rental-agreements.payments.settle', $contract), $payload)
            ->assertSessionHasErrors('late_fee');

        $this->assertSame($rows, Payment::count());
        $this->assertSame(1, Payment::where('kind', 'deposit_refund')->count());
    }

    public function test_deposit_receipts_cannot_be_voided(): void
    {
        $business = $this->business();
        $clerk = $this->user($business);
        $admin = $this->user($business, 'admin', 'admin@acme.test');
        $contract = $this->contract($business);

        $this->holdDeposit($clerk, $contract, '50.00');

        $deposit = Payment::where('kind', 'deposit_received')->sole();

        $this->actingAs($admin)
            ->patch(route('rental-agreements.payments.void', [$contract, $deposit]), [
                'reason' => 'Wrong amount at the counter',
            ])
            ->assertStatus(422);

        $this->assertNull($deposit->fresh()->voided_at);
        $this->assertSame(1, Payment::count());

        // And the row offers no void control at all.
        $this->actingAs($admin)
            ->get(route('rental-agreements.show', $contract))
            ->assertOk()
            ->assertDontSee(route('rental-agreements.payments.void', [$contract, $deposit]));
    }

    public function test_the_deposit_settlement_is_audited(): void
    {
        $business = $this->business();
        $clerk = $this->user($business);
        $contract = $this->contract($business);

        $this->payRent($clerk, $contract);
        $this->holdDeposit($clerk, $contract);

        $item = $contract->items()->first();

        $this->actingAs($clerk)->post(route('rental-agreements.payments.settle', $contract), [
            'returned_on' => now()->toDateString(),
            'late_fee' => '20.00',
            'damage' => [$item->id => '10.00'],
            'refund_method' => 'cash',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'deposit.recorded']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deposit.settled']);

        $settled = \App\Models\AuditLog::where('action', 'deposit.settled')->sole();
        $this->assertSame('30.00', $settled->details['applied']);
        $this->assertSame('20.00', $settled->details['refunded']);
    }
}
